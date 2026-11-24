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
require __DIR__ . '/stubs-install.php';
require __DIR__ . '/../src/Services/AutoUpdateService.php';

use App\Models\Server;
use Meigrafd\ModAutoRestart\Services\AutoUpdateService;
use Meigrafd\ModAutoRestart\Services\GameBuild;
use Meigrafd\ModAutoRestart\Services\GameProfile;
use Meigrafd\ModAutoRestart\Services\Installer;
use Meigrafd\ModAutoRestart\Services\PackageResolver;
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
    ModScanner::$forgotten = 0;
    ModScanner::$installed = [
        ['full_name' => 'Autor-ModA', 'namespace' => 'Autor', 'name' => 'ModA',
            'version' => '1.0.0', 'folder' => 'Autor-ModA', 'tracked' => true],
    ];
    RegistryClient::$latest = ['Autor-ModA' => ['version' => '1.0.0', 'updated' => 1000, 'source' => 'thunderstore']];
    RegistryClient::$degraded = false;
    RegistryClient::$calls = 0;
    GameBuild::$result = ['outdated' => false, 'installed' => 100, 'latest' => 100];
    GameBuild::$installedBuild = 100;
    GameBuild::$flags = 4;
    GameBuild::$resets = 0;
    GameBuild::$resetWorks = true;
    $GLOBALS['test_config'] = [];
    PackageResolver::reset();
    Installer::reset();
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
        new PackageResolver(),
        new Installer(),
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
ok('  aber geprueft, mit Hinweis auf die Wartezeit', ($s->state['run']['checked_at'] ?? 0) > 0
    && str_contains($s->state['run']['note'] ?? '', 'Abklingzeit'));
ok('  Phase bleibt idle', ($s->state['run']['phase'] ?? 'idle') === 'idle');

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

echo "\n=== Update einspielen vor dem Neustart\n";

resetAll();
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
$s = store();
$svc = service($s);
$svc->tickServer(new Server());
ok('waehrend der Warnung noch nichts installiert', Installer::$installed === []);
$svc->tickServer(new Server());
ok('vor dem Neustart wird das Update eingespielt', Installer::$installed === ['Autor-ModA'] && PowerService::$sent === ['restart']);
ok('  aus der Quelle, gegen die geprueft wurde', (PackageResolver::$calls[0] ?? []) === ['Autor-ModA', 'thunderstore']);
ok('  der Mod-Index wird danach verworfen', ModScanner::$forgotten >= 1);

resetAll();
// Download schlaegt fehl: kein Neustart, Fehlerzustand, Historie sagt warum.
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
Installer::$ok = false;
$s = store();
$svc = service($s);
$svc->tickServer(new Server());
$svc->tickServer(new Server());
ok('Einspielen scheitert: kein Neustart', PowerService::$sent === []);
ok('  Phase failed, Funktion aus', ($s->state['run']['phase'] ?? '') === 'failed' && ($s->state['auto']['enabled'] ?? true) === false);
ok('  Historie: fehlgeschlagen, nicht neu gestartet', ($s->state['history'][0]['outcome'] ?? '') === 'failed'
    && str_contains($s->state['history'][0]['note'] ?? '', 'Nicht neu gestartet'));

resetAll();
// Zurueckgezogenes Update: das Repository fuehrt eine aeltere Version, der
// Resolver hat nichts einzuspielen (kein Herunterstufen). Auch dann kein
// Neustart - er braechte die aeltere Version nie herbei.
RegistryClient::$latest = ['Autor-ModA' => ['version' => '0.9.0', 'updated' => 500, 'source' => 'thunderstore']];
PackageResolver::$plans['Autor-ModA'] = ['ok' => true, 'error' => null, 'root' => null, 'install' => [],
    'skipped' => [['full_name' => 'Autor-ModA', 'version' => '1.0.0', 'why' => 'installierte Version 1.0.0 ist neuer als 0.9.0']], 'warnings' => []];
$s = store();
$svc = service($s);
$svc->tickServer(new Server());
$svc->tickServer(new Server());
ok('nichts einzuspielen: kein Neustart, Fehlerzustand', PowerService::$sent === [] && ($s->state['run']['phase'] ?? '') === 'failed');
ok('  Grund nennt das Herunterstufen', str_contains($s->state['run']['note'] ?? '', 'neuer als'));

resetAll();
// Neustart von Hand mit Vorwarnung: nichts veraltet, nichts einspielen.
$s = store(['warn_minutes' => 0]);
$svc = service($s);
$svc->scheduleRestart(new Server(), 'tester');
$svc->tickServer(new Server());
ok('Neustart von Hand: nichts eingespielt, aber neu gestartet', Installer::$installed === [] && PowerService::$sent === ['restart']);

echo "\n=== Probelauf\n";

resetAll();
$GLOBALS['test_config']['mod-auto-restart.dry_run'] = true;
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
$s = store();
$svc = service($s);
$svc->tickServer(new Server());
$svc->tickServer(new Server());
ok('Probelauf: KEIN Neustart', PowerService::$sent === []);
ok('  und nichts eingespielt', Installer::$installed === []);
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

echo "\n=== Enshrouded: nur Spiel-Updates\n";

$ens = new Server('1', 'Enshrouded');
resetAll();
$ensProfile = (new GameProfile())->for($ens);
ok('Egg "Enshrouded" ergibt das Profil enshrouded', ($ensProfile['key'] ?? '') === 'enshrouded');
ok('  Steam-App 2278520, keine Mod-Quelle, kein Mod-Ordner',
    $GLOBALS['real_config']['profiles']['enshrouded']['app_id'] === '2278520'
    // array_key_exists, nicht ??: der Wert darf null sein, und ?? hielte ihn fuer fehlend.
    && ($ensProfile['sources'] ?? null) === []
    && array_key_exists('mods_path', $ensProfile) && $ensProfile['mods_path'] === null);
ok('  kein Ansageweg', (($ensProfile['messaging'] ?? [])['via'] ?? '') === 'none');
ok('  wird nicht uebergangen', (new GameProfile())->skipReason($ens) === null);

// Mod-Update im Repository UND Spiel-Update: ohne Quelle zaehlt nur das Spiel.
RegistryClient::$latest = ['Autor-ModA' => ['version' => '2.0.0', 'updated' => 2000, 'source' => 'thunderstore']];
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
$s = store();
$svc = service($s);
$svc->tickServer($ens);
ok('Spiel-Update: Grund ist nur das Spiel', ($s->state['run']['reason'] ?? '') === 'Spiel');
$svc->tickServer($ens);
ok('  Neustart geht raus, nichts wird eingespielt', PowerService::$sent === ['restart'] && Installer::$installed === []);
ok('  das Repository wurde gar nicht gefragt', RegistryClient::$calls === 0);

echo "\n=== Server, die das Plugin nicht anfasst\n";

$pz = new Server('1', 'Project Zomboid');
$pzDir = plugin_path('pz-mod-manager');
@rmdir($pzDir);

resetAll();
ok('ohne pz-mod-manager: Project Zomboid wird bedient', (new GameProfile())->skipReason($pz) === null);

@mkdir($pzDir, 0777, true);
resetAll();
$why = (new GameProfile())->skipReason($pz);
ok('mit pz-mod-manager: Project Zomboid wird uebergangen', $why !== null && str_contains($why, 'pz-mod-manager'), (string) $why);
ok('  Valheim davon unberuehrt', (new GameProfile())->skipReason(new Server()) === null);

// Und der Tick haelt sich daran, auch wenn dort "an" gespeichert ist und
// ein Spiel-Update vorliegt.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
$s = store();
$svc = service($s);
$svc->tickServer($pz);
$svc->tickServer($pz);
ok('  Tick uebergeht den Server: kein Neustart, Phase bleibt idle', PowerService::$sent === [] && ($s->state['run']['phase'] ?? 'idle') === 'idle');
@rmdir($pzDir);

resetAll();
$GLOBALS['test_config']['mod-auto-restart.ignored_eggs'] = ['palworld', 'ZOMBOID'];
ok('ignorierte Eggs: Namensteil, ohne Gross-/Kleinschreibung', (new GameProfile())->skipReason($pz) !== null);
ok('  Valheim bleibt', (new GameProfile())->skipReason(new Server()) === null);
$GLOBALS['test_config'] = [];

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

echo "\n=== Steam haengt (state is 0x6): appmanifest zuruecksetzen\n";

/** Kontrolle nach einem Spiel-Update, bei dem der Build sich nicht bewegt hat. */
function stuckVerify(array $extra = []): MemoryStore
{
    return store([], array_merge(['phase' => 'verifying', 'reason' => 'Spiel', 'restarted_at' => time() - 600,
        'verify_after' => time() - 60, 'verify_before' => time() + 600,
        'stale_ids' => [], 'stale_before' => [], 'build_before' => 100, 'manifest_reset' => false,
        'last_restart_at' => time() - 600], $extra),
        [['at' => time() - 600, 'trigger' => 'auto', 'reason' => 'Spiel', 'changes' => [], 'outcome' => 'pending']]);
}

resetAll();
$ens = new Server('1', 'Enshrouded');
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
GameBuild::$flags = 6;
$s = stuckVerify();
$svc = service($s);
$svc->tickServer($ens);
ok('StateFlags 6: appmanifest wird zurueckgesetzt', GameBuild::$resets === 1);
ok('  und EIN zweiter Neustart geht raus', PowerService::$sent === ['restart']);
ok('  Funktion bleibt an, Phase verifying', ($s->state['auto']['enabled'] ?? false) === true
    && ($s->state['run']['phase'] ?? '') === 'verifying' && ($s->state['run']['manifest_reset'] ?? false) === true);
ok('  laengere Frist fuer den Download', ($s->state['run']['verify_before'] ?? 0) >= time() + 55 * 60);
ok('  Hinweis nennt 0x6', str_contains($s->state['run']['note'] ?? '', '0x6'), $s->state['run']['note'] ?? '');

// Kontrolle nach dem zweiten Neustart, waehrend Steam noch laedt: Datei fehlt.
$s->state['run']['verify_after'] = time() - 60;
GameBuild::$flags = null;
PowerService::reset();
$svc->tickServer($ens);
ok('Download laeuft noch (keine appmanifest): wartet, kein Fehler', ($s->state['run']['phase'] ?? '') === 'verifying'
    && PowerService::$sent === [] && ($s->state['auto']['enabled'] ?? false) === true);

// Fertig: neuer Build, vollstaendig installiert.
GameBuild::$flags = 4;
GameBuild::$installedBuild = 200;
GameBuild::$result = ['outdated' => false, 'installed' => 200, 'latest' => 200];
$svc->tickServer($ens);
ok('nach dem Neuladen aktuell: bestaetigt, idle', ($s->state['run']['phase'] ?? '') === 'idle'
    && ($s->state['history'][0]['outcome'] ?? '') === 'verified', json_encode($s->state['run']));
ok('  kein dritter Neustart', PowerService::$sent === []);
ok('  nur einmal zurueckgesetzt', GameBuild::$resets === 1);

resetAll();
// Nach dem Zuruecksetzen kommt bis zur Frist nichts: dann aus, mit Hinweis.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
GameBuild::$flags = 6;
$s = stuckVerify(['manifest_reset' => true, 'verify_before' => time() - 1]);
service($s)->tickServer($ens);
ok('zweiter Versuch scheitert auch: aus, kein weiterer Neustart', ($s->state['run']['phase'] ?? '') === 'failed'
    && PowerService::$sent === [] && GameBuild::$resets === 0);
ok('  Grund nennt content_log.txt', str_contains($s->state['run']['note'] ?? '', 'content_log.txt'), $s->state['run']['note'] ?? '');

resetAll();
// Build alt, aber Steam meldet "vollstaendig installiert" (4): kein Steam-Fehler,
// meist AUTO_UPDATE aus. Zuruecksetzen wuerde nichts bringen.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
GameBuild::$flags = 4;
$s = stuckVerify();
service($s)->tickServer($ens);
ok('StateFlags 4: kein Zuruecksetzen, sondern aus', GameBuild::$resets === 0 && ($s->state['run']['phase'] ?? '') === 'failed'
    && PowerService::$sent === []);

resetAll();
// AUTO_UPDATE aus: SteamCMD laeuft beim Start gar nicht, also auch kein Reparaturversuch.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
GameBuild::$flags = 6;
$s = stuckVerify();
service($s)->tickServer(new Server('0', 'Enshrouded'));
ok('AUTO_UPDATE=0: kein Zuruecksetzen, sondern aus', GameBuild::$resets === 0 && ($s->state['run']['phase'] ?? '') === 'failed');

resetAll();
// Umbenennen scheitert (Wings nicht erreichbar): aus, mit Steam-Hinweis.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
GameBuild::$flags = 6;
GameBuild::$resetWorks = false;
$s = stuckVerify();
service($s)->tickServer($ens);
ok('Zuruecksetzen scheitert: aus, kein Neustart', ($s->state['run']['phase'] ?? '') === 'failed' && PowerService::$sent === []);
ok('  Grund nennt das gescheiterte Steam-Update', str_contains($s->state['run']['note'] ?? '', 'StateFlags 6'), $s->state['run']['note'] ?? '');

echo "\n=== Warten, bis der Server leer ist\n";

resetAll();
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
Messenger::$players = 2;
$s = store(['wait_empty' => true, 'warn_minutes' => 5, 'discord_webhook' => 'https://discord.com/api/webhooks/1/x']);
$svc = service($s);
$svc->tickServer($ens);
ok('Spieler online: Phase warning mit Wartemodus', ($s->state['run']['phase'] ?? '') === 'warning'
    && ($s->state['run']['wait_empty'] ?? false) === true);
ok('  kein Neustart', PowerService::$sent === []);
ok('  Discord: Neustart, sobald niemand mehr spielt, ohne Uhrzeit', count(Messenger::$discord) === 1
    && str_contains(Messenger::$discord[0], 'sobald niemand mehr spielt')
    && !str_contains(Messenger::$discord[0], 'spaetestens'), implode(' | ', Messenger::$discord));
ok('  Platzhalter :server ersetzt', str_contains(Messenger::$discord[0] ?? '', 'Testserver'));
ok('  im Spiel keine Warnung', Messenger::$said === []);
ok('  gilt als gewarnt (Discord)', ($s->state['run']['warned'] ?? false) === true);

$svc->tickServer($ens);
ok('weiter Spieler da: kein Neustart', PowerService::$sent === []);
ok('  Status nennt die Spielerzahl', str_contains($s->state['run']['note'] ?? '', '2 Spieler'), $s->state['run']['note'] ?? '');

// Der Kern der Sache: auch nach vielen Stunden kein erzwungener Neustart.
$s->state['run']['restart_at'] = time() - 12 * 3600;
$s->state['run']['started_at'] = time() - 12 * 3600;
$svc->tickServer($ens);
$svc->tickServer($ens);
ok('nach zwoelf Stunden mit Spielern: immer noch kein Neustart', PowerService::$sent === []
    && ($s->state['run']['phase'] ?? '') === 'warning');
ok('  und keine Warnung im Spiel', Messenger::$said === []);

Messenger::$players = 0;
$svc->tickServer($ens);
ok('Server leer: Neustart sofort', PowerService::$sent === ['restart']);
ok('  Historie: 0 Spieler beim Neustart', ($s->state['history'][0]['players'] ?? -1) === 0);
ok('  keine Ein-Minuten-Warnung, kein Countdown an einen leeren Server', Messenger::$said === []);

resetAll();
// Spielerzahl unbekannt: zaehlt als "jemand da", also nie ein Neustart.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
Messenger::$players = null;
$s = store(['wait_empty' => true, 'warn_minutes' => 5]);
$svc = service($s);
$svc->tickServer($ens);
$s->state['run']['restart_at'] = time() - 12 * 3600;
$svc->tickServer($ens);
$svc->tickServer($ens);
ok('Spielerzahl unbekannt: kein Neustart, auch nach Stunden', PowerService::$sent === []);
ok('  Status sagt "unbekannt" und warum nichts passiert', str_contains($s->state['run']['note'] ?? '', 'unbekannt')
    && str_contains($s->state['run']['note'] ?? '', 'Query-Port'), $s->state['run']['note'] ?? '');

resetAll();
// Jemand kommt in der Minute fuers Backup dazu: zurueck ins Warten, das
// Backup von eben gilt nicht mehr.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
Messenger::$players = 1;
$s = store(['wait_empty' => true], ['phase' => 'warning', 'wait_empty' => true, 'go' => 'empty',
    'backup_requested' => true, 'backup_id' => 5, 'reason' => 'Spiel', 'restart_at' => time() - 5,
    'started_at' => time() - 70, 'announced' => [], 'build_before' => 100]);
$svc = service($s);
$svc->tickServer($ens);
ok('Spieler kommt waehrend des Backups: kein Neustart', PowerService::$sent === []);
ok('  zurueck ins Warten, Backup-Marke entfernt', !isset($s->state['run']['go'])
    && !isset($s->state['run']['backup_requested']) && !isset($s->state['run']['backup_id']));

resetAll();
// Zustand aus 0.6.8: Frist erreicht ('go' => 'deadline'), Spieler da. Wird
// nicht mehr erzwungen.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
Messenger::$players = 3;
$s = store(['wait_empty' => true], ['phase' => 'warning', 'wait_empty' => true, 'go' => 'deadline',
    'reason' => 'Spiel', 'restart_at' => time() - 600, 'started_at' => time() - 7200, 'announced' => [],
    'build_before' => 100]);
$svc = service($s);
$svc->tickServer($ens);
ok('alte Frist aus 0.6.8 mit Spielern: kein Neustart', PowerService::$sent === [] && !isset($s->state['run']['go']));

resetAll();
// Niemand online, schon bei der Erkennung: sofort, Discord sagt "jetzt".
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
Messenger::$players = 0;
$s = store(['wait_empty' => true, 'discord_webhook' => 'https://discord.com/api/webhooks/1/x']);
$svc = service($s);
$svc->tickServer($ens);
ok('niemand online: kein Wartemodus', ($s->state['run']['wait_empty'] ?? true) === false);
ok('  Discord sagt "jetzt"', str_contains(Messenger::$discord[0] ?? '', 'jetzt'), implode(' | ', Messenger::$discord));
$svc->tickServer($ens);
ok('  Neustart mit dem naechsten Tick', PowerService::$sent === ['restart']);

resetAll();
// Ohne Wartemodus bleibt alles wie bisher.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
Messenger::$players = 3;
$s = store(['warn_minutes' => 5, 'discord_webhook' => 'https://discord.com/api/webhooks/1/x']);
service($s)->tickServer(new Server());
ok('Wartemodus aus: normale Vorwarnung', abs(($s->state['run']['restart_at'] ?? 0) - (time() + 300)) < 5
    && ($s->state['run']['wait_empty'] ?? true) === false);
ok('  Discord nennt die Minuten', str_contains(Messenger::$discord[0] ?? '', '5 Minuten'), implode(' | ', Messenger::$discord));

echo "\n=== Discord\n";

resetAll();
// Erfolgreiche Kontrolle: "wieder da" nach Discord.
GameBuild::$result = ['outdated' => false, 'installed' => 200, 'latest' => 200];
GameBuild::$installedBuild = 200;
$s = stuckVerify(['discord_webhook' => 'x']);
$s->state['auto']['discord_webhook'] = 'https://discord.com/api/webhooks/1/x';
service($s)->tickServer($ens);
ok('nach erfolgreicher Kontrolle: Discord "wieder da"', ($s->state['run']['phase'] ?? '') === 'idle'
    && str_contains(Messenger::$discord[0] ?? '', 'wieder da'), implode(' | ', Messenger::$discord));

resetAll();
// Ohne Webhook: nichts nach Discord.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
Messenger::$players = 2;
$s = store(['warn_minutes' => 5]);
service($s)->tickServer(new Server());
ok('ohne Webhook: nichts nach Discord', Messenger::$discord === []);

resetAll();
// Discord kaputt, kein Ansageweg im Spiel: Neustart laeuft trotzdem.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
Messenger::$players = 0;
Messenger::$discordWorking = false;
Messenger::$working = false;
$s = store(['discord_webhook' => 'https://discord.com/api/webhooks/1/x']);
$svc = service($s);
$svc->tickServer($ens);
$svc->tickServer($ens);
ok('Discord nicht erreichbar: Neustart trotzdem', PowerService::$sent === ['restart']);
ok('  Historie: nicht gewarnt', ($s->state['history'][0]['warned'] ?? true) === false);

resetAll();
// Leere Vorlage heisst: diese Nachricht nicht senden.
GameBuild::$result = ['outdated' => true, 'installed' => 100, 'latest' => 200];
$s = store(['discord_webhook' => 'https://discord.com/api/webhooks/1/x', 'discord_msg_warn' => '']);
service($s)->tickServer(new Server());
ok('leere Vorlage: nichts gesendet', Messenger::$discord === []);

resetAll();
// Neustart von Hand: auch Discord bekommt Bescheid.
$s = store(['enabled' => false, 'warn_minutes' => 10, 'discord_webhook' => 'https://discord.com/api/webhooks/1/x']);
Messenger::$players = 1;
service($s)->scheduleRestart(new Server(), 'tester');
ok('Neustart von Hand: Discord nennt Wartungs-Update und 10 Minuten',
    str_contains(Messenger::$discord[0] ?? '', 'Wartungs-Update') && str_contains(Messenger::$discord[0] ?? '', '10 Minuten'),
    implode(' | ', Messenger::$discord));

echo "\n=== Speicher: neue Einstellungen\n";

$read2 = function (array $auto): array {
    $store = new MemoryStore([], []);
    $m = new ReflectionMethod(StateStore::class, 'auto');
    $m->setAccessible(true);

    return $m->invoke($store, $auto);
};
ok('Frist aus 0.6.8 wird zum eingeschalteten Schalter', $read2(['wait_empty_minutes' => 180])['wait_empty'] === true);
ok('  Frist 0 aus 0.6.8 bleibt aus', $read2(['wait_empty_minutes' => 0])['wait_empty'] === false);
ok('  ausdruecklicher Schalter schlaegt die alte Frist', $read2(['wait_empty' => false, 'wait_empty_minutes' => 180])['wait_empty'] === false);
ok('Query-Port wird begrenzt', $read2(['query_port' => 70000])['query_port'] === 65535);
ok('Vorgabe Wartemodus: aus', StateStore::AUTO_DEFAULTS['wait_empty'] === false);

echo "\n";
printf("ERGEBNIS: %d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
