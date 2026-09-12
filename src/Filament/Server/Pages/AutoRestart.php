<?php

namespace Meigrafd\ModAutoRestart\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Meigrafd\ModAutoRestart\Services\AutoUpdateService;
use Meigrafd\ModAutoRestart\Services\Compatibility;
use Meigrafd\ModAutoRestart\Services\GameBuild;
use Meigrafd\ModAutoRestart\Services\GameProfile;
use Meigrafd\ModAutoRestart\Services\Installer;
use Meigrafd\ModAutoRestart\Services\PackageResolver;
use Meigrafd\ModAutoRestart\Services\Messenger;
use Meigrafd\ModAutoRestart\Services\ModScanner;
use Meigrafd\ModAutoRestart\Services\RconClient;
use Meigrafd\ModAutoRestart\Services\StateStore;

class AutoRestart extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-refresh-alert';

    protected static ?int $navigationSort = 9;

    protected string $view = 'mod-auto-restart::auto-restart';

    /** @var array<string,mixed> Einstellungen, an das Formular gebunden */
    public array $auto = [];

    /** @var array<string,mixed> Laufender Zustand (Phase, letzte Pruefung) */
    public array $run = [];

    /** @var array<int,array<string,mixed>> */
    public array $history = [];

    /** @var array<int,array<string,mixed>> installierte Mods */
    public array $mods = [];

    /** @var array<string,mixed> aufgeloestes Spielprofil */
    public array $profile = [];

    /** @var array<string,string> Schluessel => Beschriftung */
    public array $profileOptions = [];

    public string $modsNote = '';

    public bool $eggAutoUpdate = true;

    public bool $crossplay = false;

    public bool $canWarn = false;

    // --------------------------------------------------------- Installation

    /** Eingabefeld: URL oder "Autor-Paket". */
    public string $addInput = '';

    /**
     * Der geprueften Plan, bevor er ausgefuehrt wird.
     *
     * Bewusst zweistufig: erst zeigen, was passieren wuerde, dann auf einen
     * zweiten Knopf hin tun. Ein Installer, der auf Enter hin sofort Dateien
     * schreibt, gibt dem Operator keine Gelegenheit, eine falsch kopierte URL
     * zu bemerken - und die Abhaengigkeiten sieht er sonst nie.
     *
     * @var array<string,mixed>|null
     */
    public ?array $plan = null;

    public static function getNavigationLabel(): string
    {
        return trans('mar::messages.nav');
    }

    /**
     * Sichtbar fuer Nutzer mit Dateizugriff.
     *
     * Dateizugriff, weil die Einstellungen in einer Datei neben den
     * Serverdateien liegen - wer die lesen darf, darf auch diese Seite sehen.
     *
     * Ob das Egg zu einem Profil passt, entscheidet hier bewusst NICHT ueber die
     * Sichtbarkeit, solange `require_known_profile` aus ist: sonst waere ein
     * eigenes Egg mit ungewoehnlichem Namen von der Funktion ausgesperrt, obwohl
     * der Operator sein Profil von Hand setzen koennte.
     */
    public static function canAccess(): bool
    {
        $server = Filament::getTenant();
        if (!$server instanceof Server) {
            return false;
        }

        if (config('mod-auto-restart.require_known_profile', false)
            && app(GameProfile::class)->detect($server) === null) {
            return false;
        }

        return (bool) user()?->can(SubuserPermission::FileRead, $server);
    }

    public function mount(): void
    {
        $this->profileOptions = app(GameProfile::class)->options();
        $this->load();
    }

    private function getServer(): Server
    {
        return Filament::getTenant();
    }

    private function canWrite(): bool
    {
        return (bool) user()?->can(SubuserPermission::FileUpdate, $this->getServer());
    }

    private function canRestart(): bool
    {
        return (bool) user()?->can(SubuserPermission::ControlRestart, $this->getServer());
    }

    // --------------------------------------------------- Mods installieren

    /**
     * Eingabe aufloesen und den Plan zeigen. Aendert nichts auf dem Server.
     */
    public function preview(): void
    {
        abort_unless($this->canWrite(), 403);

        $input = trim($this->addInput);
        if ($input === '') {
            return;
        }

        $source = app(GameProfile::class)->sourceFor($this->profile, $this->auto, '');
        if ($source === null) {
            Notification::make()->title(trans('mar::messages.notify.no_source'))->warning()->send();

            return;
        }

        // Was schon liegt, damit der Plan zwischen "neu", "Update" und "schon
        // da" unterscheiden kann.
        $installed = [];
        foreach ($this->mods as $mod) {
            $installed[$mod['full_name']] = ['version' => $mod['version']];
        }

        $resolved = app(PackageResolver::class)->resolve($input, $this->profile, $source, $installed);

        if (!$resolved['ok']) {
            Notification::make()->title($resolved['error'])->danger()->send();
            $this->plan = null;

            return;
        }

        // Zeitpunkt des letzten Spiel-Updates als Massstab fuer das
        // Altersurteil. Nicht ermittelbar heisst: kein Urteil, nicht "alt".
        $notes = app(Compatibility::class)->checkAll($resolved['install'], $this->profile, $this->gameBuildAt());

        $this->plan = [
            'source' => $source,
            'install' => $resolved['install'],
            'skipped' => $resolved['skipped'],
            'warnings' => array_merge($resolved['warnings'], $notes['warn']),
            'stop' => $notes['stop'],
            'good' => $notes['good'],
        ];
    }

    public function clearPlan(): void
    {
        $this->plan = null;
        $this->addInput = '';
    }

    /** Den gezeigten Plan ausfuehren. */
    public function install(): void
    {
        abort_unless($this->canWrite(), 403);

        if (!$this->plan || !$this->plan['install']) {
            return;
        }

        $installer = app(Installer::class);
        $server = $this->getServer();
        $done = [];
        $failed = [];

        foreach ($this->plan['install'] as $package) {
            $result = $installer->install($server, $package, $this->profile);
            if ($result['ok']) {
                $done[] = $result['note'];
            } else {
                $failed[] = $result['note'];
                // Abbruch beim ersten Fehler. Weiterzumachen hiesse, ein Mod
                // ohne seine Abhaengigkeit zu installieren - der Server laedt es
                // dann nicht und sagt nicht warum.
                break;
            }
        }

        $this->plan = null;
        $this->addInput = '';
        $this->forgetIndex();
        $this->load();

        if ($failed) {
            Notification::make()
                ->title(trans('mar::messages.notify.install_failed'))
                ->body(implode("\n", array_merge($done, $failed)))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('mar::messages.notify.installed', ['n' => count($done)]))
            ->body(implode("\n", $done) . "\n\n" . trans('mar::messages.notify.restart_hint'))
            ->success()
            ->persistent()
            ->send();
    }

    /** Ein Mod entfernen. */
    public function remove(string $fullName): void
    {
        abort_unless($this->canWrite(), 403);

        // Vorher nachsehen, ob ein anderes installiertes Mod davon abhaengt.
        // Eine Bibliothek zu loeschen, die drei Mods brauchen, ist sonst ein
        // Server, der beim naechsten Start still drei Mods weniger laedt.
        $needed = $this->dependents($fullName);
        if ($needed) {
            Notification::make()
                ->title(trans('mar::messages.notify.still_needed', ['mod' => $fullName]))
                ->body(implode(', ', $needed))
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $result = app(Installer::class)->remove($this->getServer(), $fullName, $this->profile);
        $this->forgetIndex();
        $this->load();

        Notification::make()
            ->title($result['note'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * Installierte Mods, die das genannte als Abhaengigkeit fuehren.
     *
     * @return array<int,string>
     */
    private function dependents(string $fullName): array
    {
        $out = [];
        foreach ($this->mods as $mod) {
            foreach ((array) ($mod['dependencies'] ?? []) as $dependency) {
                if (str_starts_with((string) $dependency, $fullName . '-')) {
                    $out[] = $mod['full_name'];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Wann der installierte Spiel-Build veroeffentlicht wurde.
     *
     * Dient als Massstab fuer "seit dem letzten Spiel-Update nicht angefasst".
     * Nicht ermittelbar heisst null, und null heisst: kein Altersurteil.
     */
    private function gameBuildAt(): ?int
    {
        return app(GameBuild::class)->latestChangedAt($this->profile['app_id'] ?? null);
    }

    private function forgetIndex(): void
    {
        $path = trim((string) ($this->profile['mods_path'] ?? ''), '/');
        \Illuminate\Support\Facades\Cache::forget("mar:index:{$this->getServer()->id}:" . md5($path));
    }

    public function load(): void
    {
        $server = $this->getServer();
        $state = app(StateStore::class)->read($server);

        $this->auto = $state['auto'];
        $this->run = $state['run'];
        $this->history = $this->formatHistory($state['history']);

        $this->profile = app(GameProfile::class)->for($server, $this->auto);

        $service = app(AutoUpdateService::class);
        $this->eggAutoUpdate = $service->autoUpdateEnabled($server);
        $this->crossplay = $service->noteFlag($server, $this->profile, 'crossplay');
        $this->canWarn = app(Messenger::class)->available($this->profile, $this->auto);

        $index = app(ModScanner::class)->index($server, $this->profile);
        $this->mods = $index['mods'];
        $this->modsNote = $index['note'];
    }

    // ------------------------------------------------------------- aktionen

    /**
     * Profilwechsel sofort anwenden, ohne Speichern.
     *
     * Sonst zeigt die Seite nach der Auswahl noch die Mods, Quellen und Felder
     * des alten Profils, und der Operator speichert eine Einstellung, deren
     * Wirkung er nie gesehen hat.
     */
    public function updatedAutoProfile(): void
    {
        $this->profile = app(GameProfile::class)->for($this->getServer(), $this->auto);
        $this->load();
    }

    public function save(): void
    {
        abort_unless($this->canWrite(), 403);
        $server = $this->getServer();
        $service = app(AutoUpdateService::class);

        // Die einzige Verweigerung. Ohne AUTO_UPDATE laedt der Start nichts
        // nach, das Update steht danach immer noch aus, und die naechste
        // Pruefung startet wieder neu. Das ist eine Neustart-Schleife, und die
        // verhindert man besser, als sie zu erkennen.
        if (($this->auto['enabled'] ?? false) && !$service->autoUpdateEnabled($server)) {
            $this->auto['enabled'] = false;
            $this->persist($server);
            Notification::make()
                ->title(trans('mar::messages.notify.needs_flag'))
                ->body(trans('mar::messages.notify.needs_flag_body'))
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $this->persist($server);
        Notification::make()->title(trans('mar::messages.notify.saved'))->success()->send();
    }

    public function enableEggAutoUpdate(): void
    {
        abort_unless($this->canWrite(), 403);
        abort_unless((bool) user()?->can(SubuserPermission::StartupUpdate, $this->getServer()), 403);

        if (!app(AutoUpdateService::class)->enableAutoUpdateVariable($this->getServer())) {
            Notification::make()->title(trans('mar::messages.notify.no_flag'))->warning()->send();

            return;
        }

        $this->load();
        Notification::make()
            ->title(trans('mar::messages.notify.flag_set'))
            ->body(trans('mar::messages.notify.flag_set_body'))
            ->success()
            ->send();
    }

    /** Jetzt pruefen: alles frisch holen, nichts neu starten. */
    public function checkNow(): void
    {
        $found = app(AutoUpdateService::class)->detect($this->getServer(), $this->profile, $this->auto, true);
        $this->load();

        Notification::make()
            ->title($found['note'])
            ->body(implode("\n", array_slice($found['detail'], 0, 10)))
            ->{match (true) {
                (bool) $found['reason'] => 'warning',
                $found['degraded'] => 'danger',
                default => 'success',
            }}()
            ->send();
    }

    /** Ansageweg testen und sofort sichtbares Ergebnis liefern. */
    public function testMessaging(): void
    {
        $via = (string) (($this->profile['messaging'] ?? [])['via'] ?? 'none');

        if ($via === 'none') {
            Notification::make()->title(trans('mar::messages.notify.no_messaging'))->warning()->send();

            return;
        }

        if ($via === 'rcon' && trim((string) ($this->auto['rcon_password'] ?? '')) === '') {
            Notification::make()->title(trans('mar::messages.notify.rcon_no_password'))->warning()->send();

            return;
        }

        // Nicht nur anmelden: eine sichtbare Nachricht schicken. Eine
        // erfolgreiche Anmeldung beweist noch nicht, dass Spieler die Warnung
        // spaeter auch sehen.
        $ok = app(Messenger::class)->broadcast(
            $this->getServer(),
            $this->profile,
            $this->auto,
            trans('mar::messages.test_message')
        );

        $where = $via === 'rcon'
            ? (function () {
                $c = app(RconClient::class)->configFor($this->getServer(), $this->profile, $this->auto);

                return $c['host'] . ':' . $c['port'];
            })()
            : trans('mar::messages.settings.via_console');

        Notification::make()
            ->title($ok
                ? trans('mar::messages.notify.msg_ok', ['where' => $where])
                : trans('mar::messages.notify.msg_failed', ['where' => $where]))
            ->body($ok ? trans('mar::messages.notify.msg_ok_body') : trans('mar::messages.notify.msg_failed_body'))
            ->{$ok ? 'success' : 'danger'}()
            ->send();
    }

    public function restartNow(): void
    {
        abort_unless($this->canRestart(), 403);

        $result = app(AutoUpdateService::class)->restartManually(
            $this->getServer(),
            trans('mar::messages.manual_reason'),
            (string) (user()?->username ?? '')
        );

        $this->load();

        Notification::make()
            ->title(trans('mar::messages.notify.restarting'))
            ->body($result['wanted_backup']
                ? ($result['backup_done']
                    ? trans('mar::messages.notify.backup_done')
                    : trans('mar::messages.notify.backup_running'))
                : trans('mar::messages.notify.backup_off'))
            ->success()
            ->send();
    }

    /** Fehlerzustand quittieren, damit die Funktion wieder eingeschaltet werden kann. */
    public function clearFailure(): void
    {
        abort_unless($this->canWrite(), 403);
        $server = $this->getServer();
        $store = app(StateStore::class);
        $state = $store->read($server);
        $state['run'] = ['phase' => 'idle', 'last_restart_at' => $state['run']['last_restart_at'] ?? 0];
        $store->write($server, $state);
        $this->load();
    }

    // ------------------------------------------------------------- anzeige

    private function persist(Server $server): void
    {
        $store = app(StateStore::class);
        $state = $store->read($server);
        $state['auto'] = $this->auto;
        $store->write($server, $state);
        // Zurueckgelesen, damit das Formular die begrenzten Werte zeigt und
        // nicht das, was jemand hineingetippt hat.
        $this->load();
    }

    /**
     * @param  array<int,array<string,mixed>>  $history
     * @return array<int,array<string,mixed>>
     */
    private function formatHistory(array $history): array
    {
        $zone = config('app.timezone');
        $rows = [];

        foreach ($history as $entry) {
            $changes = [];
            foreach ($entry['changes'] as $change) {
                $from = (string) $change['from'];
                $to = (string) $change['to'];

                $changes[] = [
                    'kind' => $change['kind'],
                    'name' => $change['name'] !== '' ? $change['name'] : $change['id'],
                    // Nur ein Paar, wenn beide Seiten bekannt sind. Ein Pfeil
                    // ins Leere behauptet eine Version, die nie aufgezeichnet
                    // wurde.
                    'pair' => ($from !== '' && $to !== '' && $from !== $to) ? [$from, $to] : null,
                    'from_only' => ($from !== '' && ($to === '' || $to === $from)) ? $from : '',
                    'url' => $change['url'],
                    'source' => $change['source'] ?? '',
                ];
            }

            $at = Carbon::createFromTimestamp($entry['at'])->setTimezone($zone);
            $rows[] = [
                'at' => $at->format('Y-m-d H:i'),
                'ago' => $at->diffForHumans(),
                'trigger' => $entry['trigger'],
                'reason' => $entry['reason'],
                'by' => $entry['by'],
                'changes' => $changes,
                'players' => $entry['players'],
                'warned' => $entry['warned'],
                'outcome' => $entry['outcome'],
                'note' => $entry['note'],
                'down' => $entry['down'] > 0
                    ? CarbonInterval::seconds($entry['down'])->cascade()->forHumans(short: true)
                    : null,
            ];
        }

        return $rows;
    }

    public function checkedAt(): ?string
    {
        $at = (int) ($this->run['checked_at'] ?? 0);

        return $at > 0
            ? Carbon::createFromTimestamp($at)->setTimezone(config('app.timezone'))->diffForHumans()
            : null;
    }

    /** Farbe der Statuskarte: gruen, gelb, rot. */
    public function statusTone(): string
    {
        return match (true) {
            ($this->run['phase'] ?? '') === 'failed' => 'danger',
            ($this->run['phase'] ?? '') === 'warning' => 'warning',
            (bool) ($this->run['degraded'] ?? false) => 'danger',
            default => 'success',
        };
    }

    /** @return array<string,string> Quellenname => Basis-URL */
    public function sources(): array
    {
        return (array) ($this->profile['sources'] ?? []);
    }

    /** Ansageweg des aktuellen Profils: rcon, console oder none. */
    public function messagingVia(): string
    {
        return (string) (($this->profile['messaging'] ?? [])['via'] ?? 'none');
    }

    /** Mod, das dieses Profil fuer Ansagen braucht, oder leer. */
    public function messagingMod(): string
    {
        return (string) (($this->profile['messaging'] ?? [])['needs_mod'] ?? '');
    }

    public function writable(): bool
    {
        return $this->canWrite();
    }

    public function restartable(): bool
    {
        return $this->canRestart();
    }
}
