<?php

/**
 * Abhaengigkeitsaufloesung und Aufbau-Erkennung.
 *
 * Die Aufbau-Erkennung wird gegen Verzeichnislisten geprueft, die aus echten
 * Thunderstore-Paketen stammen - abgeschrieben aus dem, was `unzip -l` bei
 * ValheimRcon, Jotunn und BepInExPack wirklich ausgibt. Erfundene Beispiele
 * haetten hier keinen Wert: der ganze Sinn dieser Klasse ist, mit der Vielfalt
 * echter Pakete fertigzuwerden.
 *
 * Aufruf:  php tests/InstallTest.php
 *          php tests/InstallTest.php --live     zusaetzlich gegen die echte API
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/../src/Services/PackageResolver.php';
require __DIR__ . '/../src/Services/Compatibility.php';

use Meigrafd\ModAutoRestart\Services\Compatibility;
use Meigrafd\ModAutoRestart\Services\PackageResolver;

$failed = 0;
$passed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $failed, $passed;
    if ($condition) {
        $passed++;
        printf("  %-6s %s\n", 'OK', $label);
    } else {
        $failed++;
        printf("  %-6s %s%s\n", 'FEHL', $label, $detail ? '  <- ' . $detail : '');
    }
}

/** Verzeichniseintrag in der Form, die Wings liefert. */
function entryDir(string $name): array
{
    return ['name' => $name, 'directory' => true];
}

function entryFile(string $name): array
{
    return ['name' => $name, 'directory' => false];
}

// Der Installer haengt an DaemonFileRepository, das es hier nicht gibt. Nur die
// Erkennung wird gebraucht, und die fasst nichts an - also eine schlanke
// Kopie der Klasse ohne Konstruktor.
namespace_stub();
function namespace_stub(): void
{
    if (!class_exists('App\\Repositories\\Daemon\\DaemonFileRepository')) {
        eval('namespace App\\Repositories\\Daemon; class DaemonFileRepository {}');
    }
}
require __DIR__ . '/../src/Services/Installer.php';

use Meigrafd\ModAutoRestart\Services\Installer;

$installer = new Installer(new App\Repositories\Daemon\DaemonFileRepository());

echo "\n=== Aufbau-Erkennung an echten Paketen\n";

// Tristan-ValheimRcon-1.6.2
ok('ValheimRcon (flach)', $installer->classify([
    entryFile('CHANGELOG.md'), entryFile('icon.png'), entryFile('manifest.json'),
    entryFile('README.md'), entryFile('THIRD-PARTY-NOTICES.txt'), entryFile('ValheimRcon.dll'),
])['layout'] === 'flat');

// Azumatt-AzuClock-1.0.5
ok('AzuClock (flach)', $installer->classify([
    entryFile('AzuClock.dll'), entryFile('CHANGELOG.md'), entryFile('icon.png'),
    entryFile('manifest.json'), entryFile('README.md'),
])['layout'] === 'flat');

// ValheimModding-Jotunn-2.30.0
$shape = $installer->classify([
    entryDir('plugins'), entryFile('CHANGELOG.md'), entryFile('icon.png'), entryFile('manifest.json'), entryFile('README.md'),
]);
ok('Jotunn (plugins/)', $shape['layout'] === 'plugins', 'bekam ' . $shape['layout']);
ok('  und nennt den richtigen Unterordner', $shape['from'] === 'plugins');

// ValheimModding-YamlDotNet-16.3.1
ok('YamlDotNet (plugins/)', $installer->classify([
    entryDir('plugins'), entryFile('manifest.json'), entryFile('README.md'), entryFile('icon.png'), entryFile('LICENSE.txt'),
])['layout'] === 'plugins');

// denikson-BepInExPack_Valheim: aeussere Ebene ist ein einzelner Ordner
$shape = $installer->classify([entryDir('BepInExPack_Valheim')]);
ok('BepInExPack, aeussere Ebene (verschachtelt)', $shape['layout'] === 'nested', 'bekam ' . $shape['layout']);
ok('  zeigt in den Unterordner', $shape['from'] === 'BepInExPack_Valheim');

// ... und die Ebene darin
$shape = $installer->classify([
    entryDir('BepInEx'), entryFile('.doorstop_version'), entryFile('doorstop_config.ini'),
    entryFile('winhttp.dll'), entryFile('changelog.txt'),
]);
ok('BepInExPack, innere Ebene (Wurzelverzeichnis)', $shape['layout'] === 'root', 'bekam ' . $shape['layout']);

// Gefaehrlicher Grenzfall: ein Mod, das eigene Konfigurationsdateien
// mitbringt, hat plugins/ UND config/ - es darf nicht als 'root' gelten,
// solange kein BepInEx/ dabei ist.
ok('plugins/ + config/ bleibt plugins', $installer->classify([
    entryDir('plugins'), entryDir('config'), entryFile('manifest.json'),
])['layout'] === 'plugins');

// Und andersherum: BepInEx/ schlaegt plugins/.
ok('BepInEx/ schlaegt plugins/', $installer->classify([
    entryDir('BepInEx'), entryDir('plugins'), entryFile('manifest.json'),
])['layout'] === 'root');

echo "\n=== Eingaben lesen\n";

$r = new PackageResolver();
$cases = [
    'https://thunderstore.io/c/valheim/p/Tristan/ValheimRcon/' => ['Tristan', 'ValheimRcon'],
    'https://thunderstore.io/package/Tristan/ValheimRcon/' => ['Tristan', 'ValheimRcon'],
    'https://valheim.hexium.gg/mods/Azumatt/AzuClock' => ['Azumatt', 'AzuClock'],
    'Tristan-ValheimRcon' => ['Tristan', 'ValheimRcon'],
    'Tristan/ValheimRcon' => ['Tristan', 'ValheimRcon'],
    // Paketnamen duerfen Bindestriche haben, Autorennamen nicht.
    'Azumatt-Build_Camera-Custom' => ['Azumatt', 'Build_Camera-Custom'],
];
foreach ($cases as $input => $want) {
    $got = $r->parse($input);
    ok('liest: ' . substr($input, 0, 48),
        $got !== null && $got['namespace'] === $want[0] && $got['name'] === $want[1],
        $got ? $got['namespace'] . ' / ' . $got['name'] : 'null');
}
ok('Unsinn wird abgelehnt', $r->parse('was soll das denn') === null);
ok('Leereingabe wird abgelehnt', $r->parse('   ') === null);

echo "\n=== Abhaengigkeiten zerlegen\n";

$d = $r->splitDependency('denikson-BepInExPack_Valheim-5.4.2333');
ok('Autor', $d !== null && $d['namespace'] === 'denikson');
ok('Paket', $d !== null && $d['name'] === 'BepInExPack_Valheim');
ok('Version', $d !== null && $d['version'] === '5.4.2333');

// Von hinten trennen, nicht von vorn: sonst frisst der Paketname die Version.
$d = $r->splitDependency('Autor-Mit-Viel-Bindestrich-1.2.3');
ok('Paketname mit Bindestrichen', $d !== null && $d['name'] === 'Mit-Viel-Bindestrich' && $d['version'] === '1.2.3');
ok('ohne Version wird abgelehnt', $r->splitDependency('Autor-Paket') === null);

echo "\n=== Versionsvergleich\n";

ok('5.4.2350 >= 5.4.2333', $r->newerOrSame('5.4.2350', '5.4.2333'));
ok('1.0.0 nicht >= 2.0.0', !$r->newerOrSame('1.0.0', '2.0.0'));
ok('gleich zaehlt als erfuellt', $r->newerOrSame('1.2.3', '1.2.3'));
// Der Fall, der ein Herunterstufen verhindert: 1.10 ist neuer als 1.9.
ok('1.10.0 >= 1.9.0 (nicht als Text verglichen)', $r->newerOrSame('1.10.0', '1.9.0'));

echo "\n=== Kompatibilitaets-Hinweise\n";

$c = new Compatibility();
$gameUpdate = strtotime('2026-09-09');

$notes = $c->check(['namespace' => 'A', 'name' => 'B', 'deprecated' => true, 'updated' => time(), 'categories' => [], 'source' => 'x']);
ok('zurueckgezogen ergibt stop', ($notes[0]['level'] ?? '') === 'stop');

// AzuClock bei Thunderstore: Stand Maerz 2025, also aelter als 1.0.
$notes = $c->check([
    'namespace' => 'Azumatt', 'name' => 'AzuClock', 'deprecated' => false,
    'updated' => strtotime('2025-03-14'), 'categories' => ['Client-side'], 'source' => 'x',
], [], $gameUpdate);
$texts = implode(' | ', array_column($notes, 'text'));
ok('veraltetes Mod wird gemeldet', str_contains($texts, 'Spiel-Update'), $texts);
ok('rein clientseitig wird gemeldet', str_contains($texts, 'clientseitig'), $texts);
ok('beides nur als Warnung, nicht als stop', !in_array('stop', array_column($notes, 'level'), true));

// ValheimRcon: aktuell und serverseitig - nichts zu melden.
$notes = $c->check([
    'namespace' => 'Tristan', 'name' => 'ValheimRcon', 'deprecated' => false,
    'updated' => strtotime('2026-09-10'), 'categories' => ['Server-side', 'Tools'], 'source' => 'x',
], [], $gameUpdate);
ok('aktuelles Servermod: keine Hinweise', $notes === [], implode(' | ', array_column($notes, 'text')));

// Ohne bekanntes Spiel-Update darf nicht ueber das Alter gemeckert werden -
// sonst waere jedes Mod auf einem Server ohne appmanifest verdaechtig.
$notes = $c->check([
    'namespace' => 'A', 'name' => 'B', 'deprecated' => false,
    'updated' => strtotime('2020-01-01'), 'categories' => [], 'source' => 'x',
], [], null);
ok('ohne bekanntes Spiel-Update kein Altersurteil', $notes === []);

// Der Tag aus der zweiten Quelle schlaegt das Altersurteil: ein ausgezeichnetes
// Mod, das seit dem Spiel-Update nicht angefasst wurde, ist genau der Fall, in
// dem nichts anzufassen war.
$profileSig = ['signals' => ['current_tag' => ['source' => 'hexium', 'name' => 'Valheim 1.0'], 'sides' => 'thunderstore'],
               'sources' => []];
$notes = $c->check([
    'namespace' => 'A', 'name' => 'B', 'deprecated' => false,
    'updated' => strtotime('2026-07-01'), 'categories' => ['Valheim 1.0'], 'source' => 'hexium',
], $profileSig, $gameUpdate);
$levels = array_column($notes, 'level');
ok('1.0-Tag wird als gut gemeldet', in_array('good', $levels, true));
ok('  und unterdrueckt das Altersurteil', !in_array('warn', $levels, true), implode(' | ', array_column($notes,'text')));

// Ohne Tag bleibt es beim Hinweis - der fehlende Tag allein loest aber KEINEN
// eigenen Verdacht aus, weil 202 gepflegte Pakete ihn schlicht nicht tragen.
$notes = $c->check([
    'namespace' => 'A', 'name' => 'B', 'deprecated' => false,
    'updated' => strtotime('2026-09-11'), 'categories' => [], 'source' => 'hexium',
], $profileSig, $gameUpdate);
ok('fehlender Tag bei frischem Mod: keine Warnung', $notes === [], implode(' | ', array_column($notes,'text')));

echo "\n";
printf("ERGEBNIS: %d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
