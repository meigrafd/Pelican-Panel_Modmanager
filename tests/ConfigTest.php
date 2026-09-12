<?php

/**
 * BepInEx-Konfigurationsdateien lesen und zurueckschreiben.
 *
 * Geprueft wird gegen eine Datei, wie BepInEx sie wirklich schreibt (Kopfzeile,
 * Abschnitte, Typ-Kommentare, leere Vorgabe, Aufzaehlung, Flags, Bereich). Der
 * wichtigste Test ist der letzte: nach dem Schreiben darf sich AUSSER der
 * geaenderten Wertzeile kein Byte geaendert haben. Ein Editor, der Kommentare
 * verliert oder Zeilenenden umstellt, faellt erst auf, wenn ein Mod seine
 * Datei nicht mehr lesen kann.
 *
 * Aufruf:  php tests/ConfigTest.php
 */

require __DIR__ . '/../src/Services/ConfigFile.php';

use Meigrafd\ModAutoRestart\Services\ConfigFile;

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

$raw = "## Settings file was created by plugin ValheimRcon v1.6.2\n"
    . "## Plugin GUID: org.tristan.rcon\n"
    . "\n"
    . "[General]\n"
    . "\n"
    . "## Port for RCON server\n"
    . "# Setting type: Int32\n"
    . "# Default value: 2458\n"
    . "Port = 2458\n"
    . "\n"
    . "## Password. Empty password disables the plugin\n"
    . "# Setting type: String\n"
    . "# Default value: \n"
    . "Password = \n"
    . "\n"
    . "## Whitelist IP mask\n"
    . "# Setting type: String\n"
    . "# Default value: \n"
    . "Whitelist IP mask = 192.168.1.0/24, 10.0.0.1\n"
    . "\n"
    . "[Logging]\n"
    . "\n"
    . "## Enable logging\n"
    . "# Setting type: Boolean\n"
    . "# Default value: true\n"
    . "Enabled = false\n"
    . "\n"
    . "## Log level\n"
    . "# Setting type: LogLevel\n"
    . "# Default value: Info\n"
    . "# Acceptable values: Debug, Info, Warning, Error\n"
    . "Level = Warning\n"
    . "\n"
    . "## Which channels to log\n"
    . "# Setting type: Channels\n"
    . "# Default value: Chat\n"
    . "# Acceptable values: Chat, Console, Discord\n"
    . "# Multiple values can be set at the same time by separating them with , (e.g. Chat, Console)\n"
    . "Channels = Chat, Discord\n"
    . "\n"
    . "## Damage multiplier\n"
    . "# Setting type: Single\n"
    . "# Default value: 1\n"
    . "# Acceptable value range: From 0 to 10\n"
    . "Multiplier = 1.5\n";

$cf = new ConfigFile();
$doc = $cf->parse($raw);

echo "\n=== Lesen\n";

ok('Plugin-Name aus der Kopfzeile', $doc['plugin'] === 'ValheimRcon', $doc['plugin']);
ok('Version aus der Kopfzeile', $doc['version'] === '1.6.2');
ok('GUID aus der Kopfzeile', $doc['guid'] === 'org.tristan.rcon');
ok('sieben Eintraege', count($doc['entries']) === 7, (string) count($doc['entries']));

$by = [];
foreach ($doc['entries'] as $i => $e) {
    $by[$e['key']] = $e + ['index' => $i];
}

ok('Abschnitt wird zugeordnet', ($by['Port']['section'] ?? '') === 'General' && ($by['Level']['section'] ?? '') === 'Logging');
ok('Beschreibung aus den ##-Zeilen', ($by['Port']['description'] ?? '') === 'Port for RCON server');
ok('Typ und Vorgabe', ($by['Port']['type'] ?? '') === 'Int32' && ($by['Port']['default'] ?? '') === '2458');
ok('leere Vorgabe ist leer, nicht null', ($by['Password']['default'] ?? null) === '');
ok('leerer Wert wird gelesen', ($by['Password']['value'] ?? 'x') === '');
ok('Schluessel mit Leerzeichen', isset($by['Whitelist IP mask']) && $by['Whitelist IP mask']['value'] === '192.168.1.0/24, 10.0.0.1');
ok('Aufzaehlung: erlaubte Werte', ($by['Level']['acceptable'] ?? []) === ['Debug', 'Info', 'Warning', 'Error']);
ok('Flags werden erkannt', ($by['Channels']['flags'] ?? false) === true);
ok('Bereich wird erkannt', ($by['Multiplier']['range'] ?? null) === ['0', '10']);
ok('Kommentar-Zustand haengt nicht am vorigen Eintrag', ($by['Enabled']['acceptable'] ?? null) === null && ($by['Enabled']['range'] ?? null) === null);

echo "\n=== Feldart\n";

ok('Boolean -> Schalter', $cf->kind($by['Enabled']) === 'toggle');
ok('Aufzaehlung -> Auswahl', $cf->kind($by['Level']) === 'select');
ok('Flags -> Text, nicht Auswahl', $cf->kind($by['Channels']) === 'text');
ok('Int32 -> Zahl', $cf->kind($by['Port']) === 'number');
ok('Single -> Zahl', $cf->kind($by['Multiplier']) === 'number');
ok('String -> Text', $cf->kind($by['Password']) === 'text');

echo "\n=== Pruefen und normalisieren\n";

ok('Schalter an -> true', $cf->normalize($by['Enabled'], true)['value'] === 'true');
ok('Schalter aus -> false', $cf->normalize($by['Enabled'], false)['value'] === 'false');
ok('Zahl ok', $cf->normalize($by['Port'], ' 2460 ')['error'] === null && $cf->normalize($by['Port'], ' 2460 ')['value'] === '2460');
ok('Text statt Zahl wird abgelehnt', $cf->normalize($by['Port'], 'abc')['error'] !== null);
ok('Kommazahl bei Int32 wird abgelehnt', $cf->normalize($by['Port'], '24.5')['error'] !== null);
ok('Dezimalkomma wird zu Punkt', $cf->normalize($by['Multiplier'], '2,5')['value'] === '2.5');
ok('ausserhalb des Bereichs wird abgelehnt', $cf->normalize($by['Multiplier'], '11')['error'] !== null);
ok('im Bereich geht', $cf->normalize($by['Multiplier'], '10')['error'] === null);
ok('unerlaubter Auswahlwert wird abgelehnt', $cf->normalize($by['Level'], 'Verbose')['error'] !== null);
ok('erlaubter Auswahlwert geht', $cf->normalize($by['Level'], 'Debug')['error'] === null);
ok('Zeilenumbruch im Text wird entschaerft', !str_contains($cf->normalize($by['Password'], "a\nb")['value'], "\n"));

echo "\n=== Schreiben\n";

$out = $cf->render($doc, [$by['Port']['index'] => '2460', $by['Enabled']['index'] => 'true']);
$expect = str_replace(["Port = 2458\n", "Enabled = false\n"], ["Port = 2460\n", "Enabled = true\n"], $raw);
ok('nur die Wertzeilen aendern sich, sonst kein Byte', $out === $expect);
ok('ohne Aenderung kommt die Datei unveraendert zurueck', $cf->render($doc, []) === $raw);

$crlf = str_replace("\n", "\r\n", $raw);
$docCrlf = $cf->parse($crlf);
ok('CRLF wird erkannt und beibehalten', $cf->render($docCrlf, [$by['Port']['index'] => '1']) === str_replace("Port = 2458\r\n", "Port = 1\r\n", $crlf));

$bom = "\xEF\xBB\xBF" . $raw;
ok('BOM stoert das Lesen nicht', $cf->parse($bom)['plugin'] === 'ValheimRcon');

echo "\n=== Zuordnung Datei -> Mod\n";

ok('gleicher Name', $cf->matches('ValheimRcon', 'ValheimRcon'));
ok('Gross-/Kleinschreibung egal', $cf->matches('valheimrcon', 'ValheimRcon'));
ok('Sonderzeichen egal', $cf->matches('Valheim RCON', 'Valheim_Rcon'));
ok('Teilstring ab vier Zeichen', $cf->matches('Jotunn', 'JotunnLib'));
ok('zu kurzer Teilstring zaehlt nicht', !$cf->matches('Core', 'CoreKeeperMod'));
ok('anderer Name', !$cf->matches('AzuClock', 'ValheimRcon'));
ok('leer nie', !$cf->matches('', 'ValheimRcon'));

echo "\n";
printf("ERGEBNIS: %d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
