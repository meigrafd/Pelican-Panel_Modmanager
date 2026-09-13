<?php

/**
 * Die Phasenlogik ohne Panel durchgespielt.
 *
 * Getestet wird genau das, was sich sonst nur in Produktion zeigt und dort
 * teuer ist: startet der Dienst neu, wenn er soll, und - wichtiger - laesst er
 * es, wenn er es lassen soll. Jeder Test faehrt echte Zustaende durch
 * tickServer() und schaut danach in PowerService::$sent nach, ob ein Neustart
 * rausging.
 *
 * Aufruf:  php tests/PhaseTest.php
 *
 * Uebernommen aus pz-mod-manager und auf die universelle Fassung angepasst.
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/../src/Services/AutoUpdateService.php';

use App\Models\Server;
use Meigrafd\ModAutoRestart\Services\AutoUpdateService;
use Meigrafd\ModAutoRestart\Services\GameBuild;
use Meigrafd\ModAutoRestart\Services\GameProfile;
use Meigrafd\ModAutoRestart\Services\Messenger;
use Meigrafd\ModAutoRestart\Services\ModScanner;
use Meigrafd\ModAutoRestart\Services\PowerService;
use Meigrafd\ModAutoRestart\Services\RegistryClient;
use Meigrafd\ModAutoRestart\Services\StateStore;

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

/** Alles auf Ausgangszustand, damit kein Test den naechsten beeinflusst. */
function resetAll(): void
{
    PowerService::reset();
    Messenger::reset();
    Messenger::$players = 0;
    Messenger::$working = true;
    ModScanner::$ok = true;
    ModScanner::$installed = [
        ['full_name' => 'Autor-ModA', 'namespace' => 'Autor', 'name' => 'ModA',
            'version' => '1.0.0', 'folder' => 'Autor-ModA', 'tracked' => true],
    ];
    RegistryClient::$latest = ['Autor-ModA' => ['version' => '1.0.0', 'updated' => 1000, 'source' => 'thunderstore']];
    RegistryClient::$degraded = false;
    RegistryClient::$calls = 0;
    GameBuild::$result = ['outdated' => false, 'installed' => 100, 'latest' => 100];
    GameBuild::$installedBuild = 100;
    $GLOBALS['test_config'] = [];
}

function service(MemoryStore $store): AutoUpdateService
{
    return new AutoUpdateService(
        new GameProfile(),
        new ModScanner(),
        new RegistryClient(),
        new Messenger(),
        $store,
        new GameBuild(),
        new PowerService(),
    );
}

/** @param array<string,mixed> $auto */
function store(array $auto = [], array $run = [], array $history = []): MemoryStore
{
    return new MemoryStore(
        array_merge(StateStore::AUTO_DEFAULTS, ['enabled' => true, 'warn_minutes' => 0, 'countdown_seconds' => 0,
            'backup' => false, 'cooldown_minutes' => 0], $auto),
        $run,
        $history
    );
}

echo "\n=== Wann NICHT neu gestartet wird\n";

resetAll();
$s = store(['enabled' => false]);
service($s)->tickServer(new Server());
ok('ausgeschaltet: kein Neustart', PowerService::$sent === []);

resetAll();
$s = store();
service($s)->tickServer(new Server());
ok('alles aktuell: kein Neustart', PowerService::$sent === []);
ok('  und der Zustand bleibt idle', ($s->state['run']['phase'] ?? 'idle') === 'idle');

resetAll();
// Das Repository ist nicht erreichbar. Der haeufigste echte Fall, und der
// gefaehrlichste: eine ausgefallene Abfrage darf nie wie "veraltet" aussehen.
RegistryClient::$latest = ['Autor-ModA' => ['version' => null, 'updated' => null, 'source' => 'thunderstore']];
RegistryClient::$degraded = true;
$s = store();
service($s)->tickServer(new Server());
ok('Repository nicht erreichbar: kein Neustart', PowerService::$sent === []);
ok('  aber als unvollstaendig markiert', ($s->state['run']['degraded'] ?? false) === true);

resetAll();
ModScanner::$ok = false;
$s = store();
service($s)->tickServer(new Server());
ok('Mod-Ordner nicht lesbar: kein Neustart', PowerService::$sent === []);

resetAll();
// Spiel-Build unbekannt, weil kein appmanifest da ist.
GameBuild::$result = ['outdated' => false, 'installed' => null, 'latest' => null];
$s = store();
service($s)->tickServer(new Server());
ok('Spiel-Build unbekannt: kein Neustart', PowerService::$sent === []);

resetAll();
$s = store([], ['phase' => 'failed', 'note' => 'kaputt']);
service($s)->tickServer(new Server());
ok('Phase failed ist endgueltig: kein Neustart', PowerService::$sent === []);

resetAll();
// Abklingzeit: gerade eben neu gestartet, und schon wieder ein Update.
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
$s = store(['cooldown_minutes' => 60], ['phase' => 'idle', 'last_restart_at' => time() - 60]);
service($s)->tickServer(new Server());
ok('Abklingzeit laeuft noch: kein Neustart', PowerService::$sent === []);

echo "\n=== Wann neu gestartet wird\n";

resetAll();
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
$s = store();
$svc = service($s);
$svc->tickServer(new Server());           // idle -> warning (0 Minuten Warnzeit)
ok('neue Mod-Version: Phase wird warning', ($s->state['run']['phase'] ?? '') === 'warning');
$svc->tickServer(new Server());           // warning -> verifying + Neustart
ok('  danach geht ein Neustart raus', PowerService::$sent === ['restart']);
ok('  Phase ist verifying', ($s->state['run']['phase'] ?? '') === 'verifying');
ok('  Historie haelt den Versuch fest', count($s->state['history'] ?? []) === 1);
ok('  mit Ergebnis pending', ($s->state['history'][0]['outcome'] ?? '') === 'pending');
ok('  und der alten Version als "von"', ($s->state['history'][0]['changes'][0]['from'] ?? '') === '1.0.0');

resetAll();
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
$s = store();
$svc = service($s);
$svc->tickServer(new Server());
$svc->tickServer(new Server());
ok('neuer Spiel-Build: Neustart', PowerService::$sent === ['restart']);

echo "\n=== Probelauf\n";

resetAll();
$GLOBALS['test_config']['mod-auto-restart.dry_run'] = true;
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
$s = store();
$svc = service($s);
$svc->tickServer(new Server());
$svc->tickServer(new Server());
ok('Probelauf: KEIN Neustart', PowerService::$sent === []);
ok('  Historie vermerkt dry-run', ($s->state['history'][0]['outcome'] ?? '') === 'dry-run');
ok('  Phase zurueck auf idle', ($s->state['run']['phase'] ?? '') === 'idle');
// Ohne gesetzte Abklingzeit liefe der naechste Tick sofort wieder los und die
// Historie waere in Minuten voll.
ok('  Abklingzeit ist gesetzt', ($s->state['run']['last_restart_at'] ?? 0) > 0);

echo "\n=== Kontrolle nach dem Neustart\n";

resetAll();
// Der Neustart hat nichts bewirkt: dieselbe Mod ist immer noch veraltet.
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
$s = store([], ['phase' => 'verifying', 'reason' => 'Mod', 'restarted_at' => time() - 600,
    'verify_after' => time() - 60, 'verify_before' => time() + 600,
    'stale_ids' => ['Autor-ModA'], 'stale_before' => [], 'last_restart_at' => time() - 600]);
service($s)->tickServer(new Server());
ok('Update nicht angekommen: schaltet sich ab', ($s->state['auto']['enabled'] ?? true) === false);
ok('  Phase ist failed', ($s->state['run']['phase'] ?? '') === 'failed');
ok('  und startet NICHT erneut', PowerService::$sent === []);

resetAll();
// Diesmal hat es geklappt: installiert ist jetzt, was das Repository fuehrt.
ModScanner::$installed = [
    ['full_name' => 'Autor-ModA', 'namespace' => 'Autor', 'name' => 'ModA',
        'version' => '2.0.0', 'folder' => 'Autor-ModA', 'tracked' => true],
];
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
$s = store([], ['phase' => 'verifying', 'reason' => 'Mod', 'restarted_at' => time() - 600,
    'verify_after' => time() - 60, 'verify_before' => time() + 600,
    'stale_ids' => ['Autor-ModA'], 'stale_before' => [], 'last_restart_at' => time() - 600],
    [['at' => time() - 600, 'trigger' => 'auto', 'reason' => 'Mod', 'outcome' => 'pending',
      'changes' => [['kind' => 'mod', 'id' => 'Autor-ModA', 'name' => 'ModA', 'from' => '1.0.0']]]]);
service($s)->tickServer(new Server());
ok('Update angekommen: Phase idle', ($s->state['run']['phase'] ?? '') === 'idle');
ok('  bleibt eingeschaltet', ($s->state['auto']['enabled'] ?? false) === true);
ok('  Historie auf verified', ($s->state['history'][0]['outcome'] ?? '') === 'verified');
ok('  mit der neuen Version als "nach"', ($s->state['history'][0]['changes'][0]['to'] ?? '') === '2.0.0');

resetAll();
// Eine ANDERE Mod ist waehrend des Neustarts aktualisiert worden. Das ist ein
// Grund fuer den naechsten Durchlauf, kein Beleg, dass dieser scheiterte.
ModScanner::$installed = [
    ['full_name' => 'Autor-ModA', 'namespace' => 'Autor', 'name' => 'ModA', 'version' => '2.0.0', 'folder' => 'a', 'tracked' => true],
    ['full_name' => 'Autor-ModB', 'namespace' => 'Autor', 'name' => 'ModB', 'version' => '1.0.0', 'folder' => 'b', 'tracked' => true],
];
RegistryClient::$latest = [
    'Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore'],
    'Autor-ModB' => ['version' => '9.9.9', 'updated' => 3000, 'source' => 'thunderstore'],
];
$s = store([], ['phase' => 'verifying', 'reason' => 'Mod', 'restarted_at' => time() - 600,
    'verify_after' => time() - 60, 'verify_before' => time() + 600,
    'stale_ids' => ['Autor-ModA'], 'stale_before' => [], 'last_restart_at' => time() - 600]);
service($s)->tickServer(new Server());
ok('andere Mod neu: gilt NICHT als Fehlschlag', ($s->state['auto']['enabled'] ?? false) === true);

resetAll();
// Server kommt nicht zurueck, Frist abgelaufen.
ModScanner::$ok = false;
GameBuild::$installedBuild = null;
$s = store([], ['phase' => 'verifying', 'reason' => 'Mod', 'restarted_at' => time() - 3000,
    'verify_after' => time() - 2000, 'verify_before' => time() - 60,
    'stale_ids' => ['Autor-ModA'], 'last_restart_at' => time() - 3000]);
service($s)->tickServer(new Server());
ok('Server kommt nicht zurueck: schaltet sich ab', ($s->state['auto']['enabled'] ?? true) === false);

echo "\n=== Warnungen\n";

resetAll();
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
Messenger::$players = 3;
$s = store(['warn_minutes' => 5]);
service($s)->tickServer(new Server());
ok('Spieler online: es wird gewarnt', count(Messenger::$said) > 0);
ok('  Neustart erst spaeter', PowerService::$sent === []);
ok('  Platzhalter ersetzt', !str_contains(implode(' ', Messenger::$said), ':minutes'));

resetAll();
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
Messenger::$players = 0;
$s = store(['warn_minutes' => 5]);
$svc = service($s);
$svc->tickServer(new Server());
$svc->tickServer(new Server());
ok('niemand online: keine Wartezeit, sofort Neustart', PowerService::$sent === ['restart']);

resetAll();
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
Messenger::$players = null;   // unbekannt
$s = store(['warn_minutes' => 5]);
service($s)->tickServer(new Server());
ok('Spielerzahl unbekannt: wird gewartet, nicht sofort neu gestartet', PowerService::$sent === []);

resetAll();
// Der Ansageweg ist kaputt - das haeufigste Szenario nach einem Spiel-Update,
// weil RCON an einem Mod haengt. Der Neustart MUSS trotzdem stattfinden.
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
Messenger::$working = false;
Messenger::$players = null;
$s = store(['warn_minutes' => 0]);
$svc = service($s);
$svc->tickServer(new Server());
$svc->tickServer(new Server());
ok('Ansageweg kaputt: Neustart laeuft trotzdem', PowerService::$sent === ['restart']);
ok('  Historie haelt fest, dass nicht gewarnt wurde', ($s->state['history'][0]['warned'] ?? true) === false);

echo "\n=== Pruefung von Hand\n";

resetAll();
// Mitten in einer Warnung auf "Jetzt pruefen" geklickt: Zeitpunkt und
// Ergebnis kommen in den Zustand, die laufende Phase bleibt, wie sie ist.
$s = store([], ['phase' => 'warning', 'reason' => 'Mod', 'restart_at' => 12345]);
service($s)->noteManualCheck(new Server(), ['note' => 'Alles aktuell.', 'degraded' => false]);
ok('Zeitpunkt der Pruefung wird gemerkt', ($s->state['run']['checked_at'] ?? 0) > 0);
ok('  Ergebnis wird gemerkt', ($s->state['run']['note'] ?? '') === 'Alles aktuell.');
ok('  laufende Phase bleibt unangetastet', ($s->state['run']['phase'] ?? '') === 'warning'
    && ($s->state['run']['reason'] ?? '') === 'Mod' && ($s->state['run']['restart_at'] ?? 0) === 12345);
ok('  kein Neustart durch die Pruefung', PowerService::$sent === []);

echo "\n=== Neustart von Hand mit Vorwarnung\n";

resetAll();
// Auto-Neustart AUS, Spielerzahl unbekannt, fuenf Minuten Vorwarnung: der
// Ablauf muss trotzdem laufen, weil ein Mensch ihn angestossen hat.
Messenger::$players = null;
$s = store(['enabled' => false, 'warn_minutes' => 5]);
$svc = service($s);
$r = $svc->scheduleRestart(new Server(), 'tester');
ok('geplant mit fuenf Minuten', $r['ok'] === true && $r['minutes'] === 5);
ok('  Phase warning, Ausloeser manual', ($s->state['run']['phase'] ?? '') === 'warning' && ($s->state['run']['trigger'] ?? '') === 'manual');
ok('  erste Warnung ging raus', (bool) array_filter(Messenger::$said, fn ($t) => str_contains($t, '5 Minuten')));
$svc->tickServer(new Server());
ok('  Tick treibt trotz Auto-Neustart aus, aber kein Neustart vor Ablauf', PowerService::$sent === [] && ($s->state['run']['phase'] ?? '') === 'warning');
ok('  zweiter Plan waehrend der Warnung wird abgewiesen', $svc->scheduleRestart(new Server(), 'tester')['ok'] === false);
ok('  abbrechen geht und fuehrt zu idle', $svc->cancelScheduledRestart(new Server()) === true && ($s->state['run']['phase'] ?? '') === 'idle');
ok('  ohne Plan gibt es nichts abzubrechen', $svc->cancelScheduledRestart(new Server()) === false);

resetAll();
$s = store(['enabled' => false, 'warn_minutes' => 0]);
$svc = service($s);
$svc->scheduleRestart(new Server(), 'tester');
$svc->tickServer(new Server());
ok('ohne Vorwarnung: Neustart geht mit dem naechsten Tick raus', PowerService::$sent === ['restart']);
ok('  Historie: von Hand, durch tester, ausstehend', ($s->state['history'][0]['trigger'] ?? '') === 'manual'
    && ($s->state['history'][0]['by'] ?? '') === 'tester' && ($s->state['history'][0]['outcome'] ?? '') === 'pending');
ok('  Phase verifying behaelt den Ausloeser', ($s->state['run']['phase'] ?? '') === 'verifying' && ($s->state['run']['trigger'] ?? '') === 'manual');
$s->state['run']['verify_after'] = time() - 60;
$svc->tickServer(new Server());
ok('  nach der Rueckkehr bestaetigt und idle', ($s->state['history'][0]['outcome'] ?? '') === 'verified' && ($s->state['run']['phase'] ?? '') === 'idle');
ok('  Auto-Neustart bleibt aus', ($s->state['auto']['enabled'] ?? true) === false);
ok('  Willkommensnachricht ging raus', in_array(StateStore::AUTO_DEFAULTS['msg_back'], Messenger::$said, true));
ok('  kein zweiter Neustart', PowerService::$sent === ['restart']);

echo "\n=== AUTO_UPDATE\n";

resetAll();
$svc = service(store());
ok('AUTO_UPDATE=1 wird erkannt', $svc->autoUpdateEnabled(new Server('1')) === true);
ok('AUTO_UPDATE=0 wird erkannt', $svc->autoUpdateEnabled(new Server('0')) === false);

echo "\n=== Speicher: geleerte Textfelder\n";

// Filament liefert fuer ein geleertes Textfeld null, nicht "". Der Speicher
// muss beides als "nichts sagen" lesen - sonst kommt beim Zurueckladen die
// Vorgabe wieder, und die Nachricht laesst sich nie abschalten. Genau so
// sah es auf dem Panel aus: Feld geleert, gespeichert, Vorgabe stand wieder da.
$auto = new ReflectionMethod(StateStore::class, 'auto');
$read = fn (array $stored): array => $auto->invoke(store(), $stored);
ok('null leert die Nachricht', $read(['msg_back' => null])['msg_back'] === '');
ok('"" leert die Nachricht', $read(['msg_back' => ''])['msg_back'] === '');
ok('nur Leerzeichen leeren die Nachricht', $read(['msg_back' => '   '])['msg_back'] === '');
ok('fehlender Schluessel behaelt die Vorgabe', $read([])['msg_back'] === StateStore::AUTO_DEFAULTS['msg_back']);
ok('null bei einer Zahl behaelt die Vorgabe', $read(['check_minutes' => null])['check_minutes'] === StateStore::AUTO_DEFAULTS['check_minutes']);
ok('announce_via: unbekannter Wert faellt auf both zurueck', $read(['announce_via' => 'xyz'])['announce_via'] === 'both');
ok('announce_via: chat bleibt chat', $read(['announce_via' => 'chat'])['announce_via'] === 'chat');

echo "\n=== Stand je Mod fuer die Liste\n";

$profileOf = fn (MemoryStore $s) => (new GameProfile())->for(new Server(), $s->state['auto']);

resetAll();
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
$s = store();
$found = service($s)->detect(new Server(), $profileOf($s), $s->state['auto']);
ok('abweichende Version: update mit Nummer', ($found['versions']['Autor-ModA']['state'] ?? '') === 'update'
    && ($found['versions']['Autor-ModA']['latest'] ?? '') === '2.0.0');

resetAll();
$s = store();
$found = service($s)->detect(new Server(), $profileOf($s), $s->state['auto']);
ok('gleiche Version: current', ($found['versions']['Autor-ModA']['state'] ?? '') === 'current');

resetAll();
RegistryClient::$latest = ['Autor-ModA' => ['version' => null, 'updated' => null, 'source' => 'thunderstore']];
RegistryClient::$degraded = true;
$s = store();
$found = service($s)->detect(new Server(), $profileOf($s), $s->state['auto']);
ok('Repository ohne Antwort: unknown, nicht update', ($found['versions']['Autor-ModA']['state'] ?? '') === 'unknown');

echo "\n";
printf("ERGEBNIS: %d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
