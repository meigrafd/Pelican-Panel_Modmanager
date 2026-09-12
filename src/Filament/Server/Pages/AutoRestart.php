<?php

namespace Meigrafd\ModAutoRestart\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Meigrafd\ModAutoRestart\Services\AutoUpdateService;
use Meigrafd\ModAutoRestart\Services\Compatibility;
use Meigrafd\ModAutoRestart\Services\ConfigFile;
use Meigrafd\ModAutoRestart\Services\ConfigStore;
use Meigrafd\ModAutoRestart\Services\GameBuild;
use Meigrafd\ModAutoRestart\Services\GameProfile;
use Meigrafd\ModAutoRestart\Services\Installer;
use Meigrafd\ModAutoRestart\Services\Messenger;
use Meigrafd\ModAutoRestart\Services\ModScanner;
use Meigrafd\ModAutoRestart\Services\PackageResolver;
use Meigrafd\ModAutoRestart\Services\RconClient;
use Meigrafd\ModAutoRestart\Services\StateStore;

/**
 * Die Seite am Server.
 *
 * Vollstaendig aus Filament-Bausteinen aufgebaut, nicht aus eigenem HTML. Das
 * ist keine Stilfrage: Ein Filament-Panel kompiliert sein CSS vorab und nimmt
 * nur die Klassen auf, die es selbst verwendet. Von Hand geschriebene
 * Tailwind-Klassen aus einem Plugin stehen nicht darin - die Felder erscheinen
 * dann untereinander und ungestylt, ohne dass irgendwo ein Fehler auftaucht.
 * Section, Grid, Select und TextInput bringen Layout, Spalten, Dunkelmodus und
 * Abstaende dagegen selbst mit.
 *
 * @property Schema $form
 */
class AutoRestart extends Page
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-refresh-alert';

    protected static ?int $navigationSort = 9;

    protected string $view = 'mod-auto-restart::auto-restart';

    /** @var array<string,mixed> Formularzustand */
    public ?array $data = [];

    /** @var array<string,mixed> Laufender Zustand (Phase, letzte Pruefung) */
    public array $run = [];

    /** @var array<int,array<string,mixed>> */
    public array $history = [];

    /** @var array<int,array<string,mixed>> installierte Mods */
    public array $mods = [];

    /** @var array<string,mixed> aufgeloestes Spielprofil */
    public array $profile = [];

    public string $modsNote = '';

    /** @var array<string,mixed> Modlader auf dem Server (siehe ModScanner) */
    public array $loader = ['present' => false, 'marker' => ''];

    /** @var array<int,array<string,mixed>> Konfigurationsdateien im config-Ordner (siehe ConfigStore) */
    public array $configFiles = [];

    /** Pfad der Datei, die gerade im Editor steht. Leer: keine. */
    public string $configFile = '';

    /** @var array<string,mixed>|null die gelesene Datei (siehe ConfigFile::parse) */
    public ?array $configDoc = null;

    /**
     * Adresse dieser Seite, beim ersten Aufruf gemerkt.
     *
     * Der Editor wird ueber einen Parameter in der Adresse geoeffnet und
     * die Seite neu geladen, statt die Felder im laufenden Formular
     * nachzuschieben. Filament baut das Schema je Anfrage einmal; Felder,
     * die erst eine Aktion erzeugt, erschienen sonst erst beim naechsten
     * Klick. Spaetere Livewire-Anfragen gehen an /livewire/update, deshalb
     * muss die echte Adresse hier stehen.
     */
    public string $pageUrl = '';

    /**
     * Abbildung Formularfeld -> echter Mod-Name.
     *
     * Filament baut aus einem Punkt im Feldnamen eine Verschachtelung. Paketnamen
     * duerfen aber Punkte enthalten, und dann landete die Auswahl in einem
     * Unterschluessel statt beim Mod. Deshalb ein entschaerfter Name im Formular
     * und diese Abbildung zurueck - nicht schoen, aber der Alternative
     * (Mod-Namen einschraenken) fehlt die Grundlage.
     *
     * @var array<string,string>
     */
    public array $modKeys = [];

    public bool $eggAutoUpdate = true;

    public bool $flagWarning = false;

    public bool $canWarn = false;

    /**
     * Der geprueften Plan, bevor er ausgefuehrt wird.
     *
     * Zweistufig mit Absicht: erst zeigen, was passieren wuerde, dann auf einen
     * zweiten Knopf hin tun. Ein Installer, der auf Enter hin sofort Dateien
     * schreibt, gibt keine Gelegenheit, eine falsch kopierte URL zu bemerken -
     * und die Abhaengigkeiten sieht man sonst nie.
     *
     * @var array<string,mixed>|null
     */
    public ?array $plan = null;

    public static function getNavigationLabel(): string
    {
        return trans('mar::messages.nav');
    }

    public function getTitle(): string
    {
        return trans('mar::messages.nav');
    }

    /**
     * Sichtbar fuer Nutzer mit Dateizugriff - die Einstellungen liegen in einer
     * Datei neben den Serverdateien.
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
        $this->pageUrl = request()->url();
        $this->configFile = trim((string) request()->query('config', ''));
        $this->load();
    }

    // ------------------------------------------------------------- formular

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                $this->statusSection(),
                $this->settingsSection(),
                $this->modsSection(),
                $this->configSection(),
                $this->historySection(),
            ]);
    }

    private function statusSection(): Section
    {
        return Section::make(trans('mar::messages.status.heading'))
            ->description($this->statusDescription())
            ->icon('tabler-activity')
            ->columnSpanFull()
            ->schema([
                TextEntry::make('status_note')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state(fn () => $this->run['note'] ?? trans('mar::messages.status.idle'))
                    ->color($this->statusTone()),

                // Die Hinweise stehen als eigene Eintraege da, nicht als
                // Fliesstext: So bleiben sie sichtbar, wenn mehrere zugleich
                // zutreffen, und jeder hat seine eigene Farbe.
                TextEntry::make('hint_failed')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn () => ($this->run['phase'] ?? '') === 'failed')
                    ->state(trans('mar::messages.status.failed_help'))
                    ->color('danger'),

                TextEntry::make('hint_auto_update')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn () => !$this->eggAutoUpdate)
                    ->state(trans('mar::messages.status.no_auto_update'))
                    ->color('warning'),

                TextEntry::make('hint_crossplay')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => $this->flagWarning && (bool) $get('check_mods'))
                    ->state(trans('mar::messages.status.crossplay'))
                    ->color('warning'),

                TextEntry::make('hint_silent')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => (bool) $get('enabled') && !$this->canWarn)
                    ->state(fn () => $this->messagingVia() === 'none'
                        ? trans('mar::messages.status.silent_profile')
                        : trans('mar::messages.status.silent_unconfigured'))
                    ->color('warning'),

                TextEntry::make('status_detail')
                    ->label(trans('mar::messages.status.detail'))
                    ->columnSpanFull()
                    ->visible(fn () => !empty($this->run['detail']))
                    ->listWithLineBreaks()
                    ->state(fn () => (array) ($this->run['detail'] ?? [])),
            ])
            ->footerActions([
                Action::make('check_now')
                    ->label(trans('mar::messages.action.check_now'))
                    ->icon('tabler-refresh')
                    ->color('gray')
                    ->action('checkNow'),

                Action::make('restart_now')
                    ->label(trans('mar::messages.action.restart_now'))
                    ->icon('tabler-player-play')
                    ->color('gray')
                    ->visible(fn () => $this->canRestart())
                    ->requiresConfirmation()
                    ->modalDescription(trans('mar::messages.action.restart_confirm'))
                    ->action('restartNow'),

                Action::make('set_flag')
                    ->label(trans('mar::messages.action.set_flag'))
                    ->color('warning')
                    ->visible(fn () => !$this->eggAutoUpdate && $this->canWrite())
                    ->action('enableEggAutoUpdate'),

                Action::make('clear_failure')
                    ->label(trans('mar::messages.action.clear_failure'))
                    ->color('danger')
                    ->visible(fn () => ($this->run['phase'] ?? '') === 'failed' && $this->canWrite())
                    ->action('clearFailure'),
            ]);
    }

    private function settingsSection(): Section
    {
        $writable = $this->canWrite();

        return Section::make(trans('mar::messages.settings.heading'))
            ->description(trans('mar::messages.settings.intro'))
            ->icon('tabler-settings')
            ->collapsible()
            ->columnSpanFull()
            ->columns(['default' => 1, 'md' => 4])
            ->schema([
                Fieldset::make(trans('mar::messages.settings.game_heading'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        Select::make('profile')
                            ->label(trans('mar::messages.settings.profile'))
                            ->options(app(GameProfile::class)->options())
                            ->placeholder(trans('mar::messages.settings.profile_auto'))
                            ->helperText(fn () => ($this->profile['detected'] ?? null)
                                ? trans('mar::messages.settings.profile_detected', ['label' => $this->profile['label']])
                                : null)
                            ->disabled(!$writable)
                            // Sofort anwenden statt erst beim Speichern: sonst
                            // zeigt die Seite nach der Auswahl noch Mods,
                            // Quellen und Felder des alten Profils.
                            ->live()
                            ->afterStateUpdated(fn () => $this->profileChanged()),

                        TextInput::make('app_id')
                            ->label(trans('mar::messages.settings.app_id'))
                            ->placeholder(fn () => $this->profile['app_id'] ?? trans('mar::messages.settings.app_id_unknown'))
                            ->helperText(trans('mar::messages.settings.app_id_hint'))
                            ->numeric()
                            ->disabled(!$writable),

                        TextInput::make('mods_path')
                            ->label(trans('mar::messages.settings.mods_path'))
                            ->placeholder(fn () => $this->profile['mods_path'] ?? '—')
                            ->helperText(trans('mar::messages.settings.mods_path_hint'))
                            ->disabled(!$writable),

                        Select::make('source')
                            ->label(trans('mar::messages.settings.source'))
                            ->options(fn () => array_combine(array_keys($this->sources()), array_keys($this->sources())))
                            ->helperText(trans('mar::messages.settings.source_hint'))
                            ->visible(fn () => count($this->sources()) > 1)
                            ->disabled(!$writable),

                        TextEntry::make('source_only')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn () => count($this->sources()) === 1)
                            ->state(fn () => trans('mar::messages.settings.source_only',
                                ['name' => array_key_first($this->sources())])),
                    ]),

                Toggle::make('enabled')
                    ->label(trans('mar::messages.settings.enabled'))
                    ->columnSpanFull()
                    ->live()
                    ->disabled(!$writable),

                Select::make('warn_minutes')
                    ->label(trans('mar::messages.settings.warn'))
                    ->options([
                        0 => trans('mar::messages.settings.warn_none'),
                        1 => '1', 2 => '2',
                        5 => trans('mar::messages.settings.recommended', ['n' => 5]),
                        10 => '10', 15 => '15', 30 => '30',
                    ])
                    ->selectablePlaceholder(false)
                    ->disabled(!$writable),

                Select::make('check_minutes')
                    ->label(trans('mar::messages.settings.interval'))
                    ->options([
                        5 => trans('mar::messages.settings.recommended', ['n' => 5]),
                        10 => '10', 15 => '15', 30 => '30', 60 => '60',
                    ])
                    ->selectablePlaceholder(false)
                    ->disabled(!$writable),

                Select::make('backup')
                    ->label(trans('mar::messages.settings.backup'))
                    ->options([
                        1 => trans('mar::messages.settings.backup_always'),
                        0 => trans('mar::messages.settings.backup_never'),
                    ])
                    ->selectablePlaceholder(false)
                    ->helperText(trans('mar::messages.settings.backup_hint'))
                    ->disabled(!$writable),

                Select::make('watch')
                    ->label(trans('mar::messages.settings.watch'))
                    ->options([
                        'both' => trans('mar::messages.settings.watch_both'),
                        'mods' => trans('mar::messages.settings.watch_mods'),
                        'game' => trans('mar::messages.settings.watch_game'),
                    ])
                    ->selectablePlaceholder(false)
                    // Ein Feld nach aussen, zwei Schalter nach innen: "nur
                    // Mods" auf einem Crossplay-Server und "nur Spiel" auf einem
                    // Vanilla-Server sind beides sinnvolle Einstellungen.
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        $this->data['check_mods'] = $state !== 'game';
                        $this->data['check_game'] = $state !== 'mods';
                    })
                    ->disabled(!$writable),

                Fieldset::make(trans('mar::messages.settings.msg_heading'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('msg_intro')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state(fn () => match ($this->messagingVia()) {
                                'none' => trans('mar::messages.settings.msg_none'),
                                'console' => trans('mar::messages.settings.msg_console'),
                                default => $this->messagingMod()
                                    ? trans('mar::messages.settings.msg_rcon_mod', ['mod' => $this->messagingMod()])
                                    : trans('mar::messages.settings.msg_rcon'),
                            }),

                        TextInput::make('rcon_host')
                            ->label(trans('mar::messages.settings.rcon_host'))
                            ->placeholder(trans('mar::messages.settings.rcon_host_placeholder'))
                            ->visible(fn () => $this->messagingVia() === 'rcon')
                            ->disabled(!$writable),

                        TextInput::make('rcon_port')
                            ->label(trans('mar::messages.settings.rcon_port'))
                            ->placeholder(fn () => trans('mar::messages.settings.rcon_port_placeholder',
                                ['n' => ($this->profile['messaging']['port_offset'] ?? 0)]))
                            ->numeric()
                            ->visible(fn () => $this->messagingVia() === 'rcon')
                            ->disabled(!$writable),

                        TextInput::make('rcon_password')
                            ->label(trans('mar::messages.settings.rcon_password'))
                            ->password()
                            ->revealable()
                            ->helperText(trans('mar::messages.settings.rcon_warning'))
                            ->visible(fn () => $this->messagingVia() === 'rcon')
                            ->disabled(!$writable),

                        Actions::make([
                            Action::make('test_messaging')
                                ->label(trans('mar::messages.action.test_messaging'))
                                ->icon('tabler-message')
                                ->color('gray')
                                ->action('testMessaging'),
                        ])
                            ->columnSpanFull()
                            ->visible(fn () => $this->messagingVia() !== 'none'),
                    ]),

                Fieldset::make(trans('mar::messages.settings.advanced'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextInput::make('countdown_seconds')
                            ->label(trans('mar::messages.settings.countdown'))
                            ->numeric()->minValue(0)->maxValue(60)
                            ->disabled(!$writable),

                        TextInput::make('cooldown_minutes')
                            ->label(trans('mar::messages.settings.cooldown'))
                            ->numeric()->minValue(0)->maxValue(1440)
                            ->disabled(!$writable),

                        TextInput::make('backup_wait_seconds')
                            ->label(trans('mar::messages.settings.backup_wait'))
                            ->numeric()->minValue(0)->maxValue(900)
                            ->disabled(!$writable),

                        TextInput::make('msg_warn')
                            ->label(trans('mar::messages.settings.msg_warn'))
                            ->columnSpanFull()
                            ->disabled(!$writable),

                        TextInput::make('msg_final')
                            ->label(trans('mar::messages.settings.msg_final'))
                            ->columnSpanFull()
                            ->disabled(!$writable),

                        TextInput::make('msg_countdown')
                            ->label(trans('mar::messages.settings.msg_countdown'))
                            ->columnSpanFull()
                            ->disabled(!$writable),

                        TextInput::make('msg_back')
                            ->label(trans('mar::messages.settings.msg_back'))
                            ->columnSpanFull()
                            ->helperText(trans('mar::messages.settings.placeholders'))
                            ->disabled(!$writable),
                    ]),
            ])
            ->footerActions([
                Action::make('save')
                    ->label(trans('mar::messages.action.save'))
                    ->icon('tabler-device-floppy')
                    ->visible($writable)
                    ->action('save'),
            ]);
    }

    private function modsSection(): Section
    {
        return Section::make(trans('mar::messages.mods.heading'))
            ->description(fn () => $this->modsNote)
            ->icon('tabler-puzzle')
            ->collapsible()
            ->columnSpanFull()
            ->schema([
                Grid::make(['default' => 1, 'md' => 4])
                    ->columnSpanFull()
                    ->visible(fn () => $this->canWrite() && $this->sources() && !$this->plan)
                    ->schema([
                        TextInput::make('add_input')
                            ->hiddenLabel()
                            ->columnSpan(['default' => 1, 'md' => 3])
                            ->placeholder(trans('mar::messages.add.placeholder'))
                            ->helperText(trans('mar::messages.add.hint')),

                        Actions::make([
                            Action::make('preview')
                                ->label(trans('mar::messages.action.preview'))
                                ->icon('tabler-search')
                                ->action('preview'),
                        ]),
                    ]),

                // Der Plan. Steht bewusst zwischen Eingabe und Liste: was hier
                // steht, ist noch nicht passiert.
                Section::make(fn () => trans('mar::messages.add.plan', ['source' => $this->plan['source'] ?? '']))
                    ->columnSpanFull()
                    ->visible(fn () => (bool) $this->plan)
                    ->schema([
                        TextEntry::make('plan_install')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->listWithLineBreaks()
                            ->state(fn () => $this->planLines()),

                        TextEntry::make('plan_skipped')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn () => !empty($this->plan['skipped']))
                            ->listWithLineBreaks()
                            ->color('gray')
                            ->state(fn () => array_map(
                                fn ($r) => $r['full_name'] . ' — ' . $r['why'],
                                (array) ($this->plan['skipped'] ?? [])
                            )),

                        TextEntry::make('plan_good')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn () => !empty($this->plan['good']))
                            ->listWithLineBreaks()
                            ->color('success')
                            ->state(fn () => (array) ($this->plan['good'] ?? [])),

                        TextEntry::make('plan_warnings')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn () => !empty($this->plan['warnings']))
                            ->listWithLineBreaks()
                            ->color('warning')
                            ->state(fn () => (array) ($this->plan['warnings'] ?? [])),

                        TextEntry::make('plan_stop')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn () => !empty($this->plan['stop']))
                            ->listWithLineBreaks()
                            ->color('danger')
                            ->state(fn () => (array) ($this->plan['stop'] ?? [])),
                    ])
                    ->footerActions([
                        Action::make('do_install')
                            ->label(fn () => empty($this->plan['stop'])
                                ? trans('mar::messages.action.install')
                                : trans('mar::messages.action.install_anyway'))
                            ->icon('tabler-download')
                            ->color(fn () => empty($this->plan['stop']) ? 'primary' : 'danger')
                            ->visible(fn () => !empty($this->plan['install']))
                            ->action('install'),

                        Action::make('cancel_plan')
                            ->label(trans('mar::messages.action.cancel'))
                            ->color('gray')
                            ->action('clearPlan'),
                    ]),

                // Der Lader steht nicht in der Liste, weil er keine manifest.json
                // hat, wenn ihn das Egg installiert hat. Da ist er trotzdem, und
                // das soll man sehen - sonst wundert man sich, warum der Plan
                // BepInEx nicht mit aufnimmt.
                TextEntry::make('loader_state')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn () => (string) ($this->loader['marker'] ?? '') !== '')
                    ->color(fn () => ($this->loader['present'] ?? false) ? 'success' : 'gray')
                    ->state(fn () => ($this->loader['present'] ?? false)
                        ? trans('mar::messages.mods.loader_present', ['path' => $this->loader['marker'] ?? ''])
                        : trans('mar::messages.mods.loader_missing', ['path' => $this->loader['marker'] ?? ''])),

                Fieldset::make(trans('mar::messages.mods.installed'))
                    ->columnSpanFull()
                    ->columns(1)
                    ->visible(fn () => (bool) $this->mods)
                    ->schema($this->modRows()),
            ]);
    }

    /**
     * Der Editor fuer die Konfigurationsdateien der Mods.
     *
     * Kein Mod wird hier beim Namen gekannt: BepInEx schreibt zu jedem Wert
     * Beschreibung, Typ, Vorgabe und erlaubte Werte in die Datei, und daraus
     * entsteht das Formular. Geschrieben wird nur die Wertzeile - Kommentare
     * und Reihenfolge bleiben, wie das Mod sie hinterlassen hat.
     */
    private function configSection(): Section
    {
        $writable = $this->canWrite();

        return Section::make(trans('mar::messages.config.heading'))
            ->description(trans('mar::messages.config.intro'))
            ->icon('tabler-adjustments')
            ->collapsible()
            ->collapsed($this->configDoc === null)
            ->columnSpanFull()
            ->visible(fn () => $this->configDir() !== '')
            ->schema([
                Grid::make(['default' => 1, 'md' => 4])
                    ->columnSpanFull()
                    ->visible(fn () => (bool) $this->configFiles)
                    ->schema([
                        Select::make('config_file')
                            ->hiddenLabel()
                            ->columnSpan(['default' => 1, 'md' => 3])
                            ->options($this->configOptions())
                            ->placeholder(trans('mar::messages.config.file_placeholder')),

                        Actions::make([
                            Action::make('load_config')
                                ->label(trans('mar::messages.config.load'))
                                ->icon('tabler-file-settings')
                                ->action('loadConfig'),
                        ]),
                    ]),

                TextEntry::make('config_none')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn () => !$this->configFiles)
                    ->color('gray')
                    ->state(trans('mar::messages.config.none', ['path' => $this->configDir()])),

                Section::make($this->configTitle())
                    ->description(fn () => $this->configFile)
                    ->columnSpanFull()
                    ->visible(fn () => $this->configDoc !== null)
                    ->schema($this->configFields($writable))
                    ->footerActions([
                        Action::make('save_config')
                            ->label(trans('mar::messages.config.save'))
                            ->icon('tabler-device-floppy')
                            ->visible($writable)
                            ->action('saveConfig'),

                        Action::make('close_config')
                            ->label(trans('mar::messages.config.close'))
                            ->color('gray')
                            ->action('closeConfig'),
                    ]),
            ]);
    }

    private function historySection(): Section
    {
        return Section::make(trans('mar::messages.history.heading'))
            ->description(trans('mar::messages.history.intro'))
            ->icon('tabler-history')
            ->collapsible()
            ->collapsed()
            ->columnSpanFull()
            ->visible(fn () => (bool) $this->history)
            ->schema([
                TextEntry::make('history_list')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->listWithLineBreaks()
                    ->state(fn () => $this->historyLines()),
            ]);
    }

    // -------------------------------------------------------------- anzeige

    private function configDir(): string
    {
        return trim((string) (($this->profile['loader'] ?? [])['config'] ?? ''), '/');
    }

    private function loadConfigFiles(Server $server): void
    {
        $this->configFiles = $this->configDir() === ''
            ? []
            : app(ConfigStore::class)->list($server, $this->configDir());

        // Nur eine Datei aus der Liste darf in den Editor: der Parameter in
        // der Adresse ist Eingabe von aussen, kein Pfad, dem man traut.
        $known = array_column($this->configFiles, 'path');
        if ($this->configFile !== '' && !in_array($this->configFile, $known, true)) {
            $this->configFile = '';
        }

        $this->configDoc = null;
        if ($this->configFile !== '') {
            try {
                $this->configDoc = app(ConfigStore::class)->read($server, $this->configFile);
            } catch (\Throwable $e) {
                $this->configFile = '';
                Notification::make()->title(trans('mar::messages.config.unreadable'))->body($e->getMessage())->danger()->send();
            }
        }
    }

    /** @return array<string,string> Pfad => Anzeige */
    private function configOptions(): array
    {
        $options = [];
        foreach ($this->configFiles as $file) {
            $options[$file['path']] = $file['plugin'] !== ''
                ? $file['plugin'] . '  (' . $file['file'] . ')'
                : $file['file'];
        }

        return $options;
    }

    /** Datei zu einem Mod aus der Liste, wenn sich eine zuordnen laesst. */
    private function configFor(string $modName): ?string
    {
        $matcher = app(ConfigFile::class);
        foreach ($this->configFiles as $file) {
            if ($matcher->matches((string) $file['plugin'], $modName)) {
                return (string) $file['path'];
            }
        }

        return null;
    }

    private function configTitle(): string
    {
        if ($this->configDoc === null) {
            return '';
        }
        $plugin = (string) ($this->configDoc['plugin'] ?: basename($this->configFile));
        $version = (string) ($this->configDoc['version'] ?? '');

        return $version !== '' ? $plugin . ' ' . $version : $plugin;
    }

    /**
     * Ein Feld je Eintrag, gruppiert nach Abschnitt der Datei.
     *
     * Bewusst ohne Pruefregeln an den Feldern: das Formular ist dasselbe wie
     * fuer die Einstellungen, und eine fehlerhafte Zahl hier duerfte nicht
     * das Speichern dort blockieren. Geprueft wird in saveConfig().
     *
     * @return array<int,Fieldset>
     */
    private function configFields(bool $writable): array
    {
        if ($this->configDoc === null) {
            return [];
        }
        $parser = app(ConfigFile::class);
        $sections = [];

        foreach ((array) $this->configDoc['entries'] as $i => $entry) {
            $name = 'cfg_' . $i;
            $help = trim((string) $entry['description']);
            if ($entry['default'] !== null && $entry['default'] !== '') {
                $help .= ($help !== '' ? ' ' : '') . trans('mar::messages.config.default', ['value' => $entry['default']]);
            }
            if ($entry['range'] !== null) {
                $help .= ' ' . trans('mar::messages.config.range', ['min' => $entry['range'][0], 'max' => $entry['range'][1]]);
            }
            if ($entry['flags'] && $entry['acceptable']) {
                $help .= ' ' . trans('mar::messages.config.flags', ['values' => implode(', ', $entry['acceptable'])]);
            }

            $field = match ($parser->kind($entry)) {
                'toggle' => Toggle::make($name),
                'select' => Select::make($name)
                    ->options(array_combine($entry['acceptable'], $entry['acceptable']))
                    ->selectablePlaceholder(false),
                default => TextInput::make($name)
                    ->placeholder((string) ($entry['default'] ?? '')),
            };

            $sections[$entry['section']][] = $field
                ->label($entry['key'])
                ->helperText($help !== '' ? $help : null)
                ->disabled(!$writable);
        }

        $out = [];
        foreach ($sections as $section => $fields) {
            $out[] = Fieldset::make($section !== '' ? (string) $section : trans('mar::messages.config.no_section'))
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2])
                ->schema($fields);
        }

        return $out;
    }

    /** @return array<int,string> */
    private function planLines(): array
    {
        $lines = [];
        $install = (array) ($this->plan['install'] ?? []);
        $last = count($install) - 1;

        foreach ($install as $i => $package) {
            $line = ($i + 1) . '. ' . $package['full_name'] . '  ' . $package['version'];
            if (($package['action'] ?? '') === 'update') {
                $line .= '  (' . trans('mar::messages.add.update_from', ['from' => $package['from']]) . ')';
            } elseif ($i < $last) {
                // Alles ausser dem letzten Eintrag ist eine Abhaengigkeit: der
                // Plan ist so sortiert, dass zuerst kommt, was zuerst liegen
                // muss.
                $line .= '  (' . trans('mar::messages.add.dependency') . ')';
            }
            $lines[] = $line;
        }

        return $lines ?: [trans('mar::messages.add.nothing')];
    }

    /**
     * Eine Zeile je installiertem Mod: Name, Version, Quelle, geprueft, loeschen.
     *
     * Als Grid statt als Tabelle, weil die Zeilen Eingabefelder enthalten - die
     * Quellenauswahl gehoert zum Formular und muss mitgespeichert werden. Ein
     * Grid mit fester Spaltenzahl richtet die Zeilen genauso aus.
     *
     * @return array<int,Grid>
     */
    private function modRows(): array
    {
        $multi = count($this->sources()) > 1;
        $rows = [];

        foreach ($this->mods as $i => $mod) {
            $name = (string) $mod['full_name'];
            $key = $this->modKey($name);

            $cells = [
                TextEntry::make('mod_name_' . $i)
                    ->label($i === 0 ? trans('mar::messages.mods.name') : '')
                    ->state($name)
                    ->columnSpan(2),

                TextEntry::make('mod_version_' . $i)
                    ->label($i === 0 ? trans('mar::messages.mods.version') : '')
                    ->state($mod['version'] ?: '—'),

                TextEntry::make('mod_tracked_' . $i)
                    ->label($i === 0 ? trans('mar::messages.mods.tracked') : '')
                    ->state($mod['tracked'] ? trans('mar::messages.mods.yes') : trans('mar::messages.mods.no'))
                    // Kein Autor im Ordnernamen: eine geratene Zuordnung wuerde
                    // ewig ein Update melden, das nie ankommt.
                    ->color($mod['tracked'] ? 'success' : 'gray')
                    ->tooltip($mod['tracked'] ? null : trans('mar::messages.mods.untracked')),
            ];

            if ($multi) {
                $cells[] = Select::make('mod_sources.' . $key)
                    ->label($i === 0 ? trans('mar::messages.mods.source') : '')
                    ->options(array_combine(array_keys($this->sources()), array_keys($this->sources())))
                    // Leer heisst: die globale Wahl oben gilt. Das ist die
                    // Vorgabe, keine fehlende Angabe.
                    ->placeholder(trans('mar::messages.mods.source_global'))
                    ->disabled(fn () => !$this->canWrite() || !$mod['tracked']);
            }

            $configPath = $this->configFor((string) $mod['name']);

            $cells[] = Actions::make([
                Action::make('configure_' . $i)
                    ->label(trans('mar::messages.config.for_mod', ['mod' => $name]))
                    ->icon('tabler-adjustments')
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn () => $configPath !== null)
                    ->action(fn () => $this->openConfig((string) $configPath)),

                Action::make('remove_' . $i)
                    ->label(trans('mar::messages.mods.remove'))
                    ->icon('tabler-trash')
                    ->iconButton()
                    ->color('danger')
                    ->visible(fn () => $this->canWrite())
                    ->requiresConfirmation()
                    ->modalHeading(trans('mar::messages.mods.remove_named', ['mod' => $name]))
                    ->modalDescription(trans('mar::messages.mods.remove_confirm', ['mod' => $name]))
                    ->action(fn () => $this->remove($name)),
            ])->label($i === 0 ? ' ' : '');

            $rows[] = Grid::make(['default' => 2, 'md' => $multi ? 6 : 5])
                ->schema($cells);
        }

        return $rows;
    }

    /**
     * Entschaerfter Feldname fuer einen Mod.
     *
     * Ein Punkt im Namen wuerde Filament eine Verschachtelung bauen lassen und
     * die Auswahl im falschen Schluessel ablegen.
     */
    private function modKey(string $fullName): string
    {
        $key = str_replace('.', '_DOT_', $fullName);
        $this->modKeys[$key] = $fullName;

        return $key;
    }

    /** @return array<int,string> */
    private function historyLines(): array
    {
        $lines = [];

        foreach ($this->history as $entry) {
            $head = $entry['at'] . '  ·  ' . trans('mar::messages.outcome.' . $entry['outcome']);
            if ($entry['down']) {
                $head .= '  ·  ' . trans('mar::messages.history.down', ['time' => $entry['down']]);
            }

            $what = $entry['trigger'] === 'manual'
                ? trans('mar::messages.history.manual', ['by' => $entry['by'] ?: '?'])
                : trans('mar::messages.history.auto', ['reason' => $entry['reason']]);

            if (!is_null($entry['players'])) {
                $what .= ', ' . trans('mar::messages.history.players', ['n' => $entry['players']]);
            }
            if ($entry['trigger'] === 'auto' && !$entry['warned']) {
                // Ohne diese Angabe raet ein Operator, warum sich jemand ueber
                // einen Neustart ohne Vorwarnung beschwert.
                $what .= ', ' . trans('mar::messages.history.not_warned');
            }

            $changes = [];
            foreach ($entry['changes'] as $change) {
                $text = $change['name'];
                if ($change['pair']) {
                    $text .= ' ' . $change['pair'][0] . ' → ' . $change['pair'][1];
                } elseif ($change['from_only']) {
                    $text .= ' ' . $change['from_only'];
                }
                if ($change['source']) {
                    $text .= ' (' . $change['source'] . ')';
                }
                $changes[] = $text;
            }

            $line = $head . '  —  ' . $what;
            if ($changes) {
                $line .= ': ' . implode(', ', $changes);
            }
            if ($entry['note']) {
                $line .= '  ⚠ ' . $entry['note'];
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function statusDescription(): string
    {
        $parts = [$this->profile['label'] ?? '?'];
        if (!empty($this->profile['app_id'])) {
            $parts[] = 'App ' . $this->profile['app_id'];
        }
        if ($at = $this->checkedAt()) {
            $parts[] = trans('mar::messages.status.checked', ['ago' => $at]);
        }

        return implode(' · ', $parts);
    }

    private function checkedAt(): ?string
    {
        $at = (int) ($this->run['checked_at'] ?? 0);

        return $at > 0
            ? Carbon::createFromTimestamp($at)->setTimezone(config('app.timezone'))->diffForHumans()
            : null;
    }

    /** Farbe der Statuszeile. */
    private function statusTone(): string
    {
        return match (true) {
            ($this->run['phase'] ?? '') === 'failed' => 'danger',
            ($this->run['phase'] ?? '') === 'warning' => 'warning',
            (bool) ($this->run['degraded'] ?? false) => 'danger',
            default => 'success',
        };
    }

    /** @return array<string,string> */
    private function sources(): array
    {
        return (array) ($this->profile['sources'] ?? []);
    }

    private function messagingVia(): string
    {
        return (string) (($this->profile['messaging'] ?? [])['via'] ?? 'none');
    }

    private function messagingMod(): string
    {
        return (string) (($this->profile['messaging'] ?? [])['needs_mod'] ?? '');
    }

    // --------------------------------------------------------------- laden

    public function load(): void
    {
        $server = $this->getServer();
        $state = app(StateStore::class)->read($server);
        $auto = $state['auto'];

        $this->run = $state['run'];
        $this->history = $this->formatHistory($state['history']);
        $this->profile = app(GameProfile::class)->for($server, $auto);

        $service = app(AutoUpdateService::class);
        $this->eggAutoUpdate = $service->autoUpdateEnabled($server);
        $this->flagWarning = $service->noteFlag($server, $this->profile, 'crossplay');
        $this->canWarn = app(Messenger::class)->available($this->profile, $auto);

        $index = app(ModScanner::class)->index($server, $this->profile);
        $this->mods = $index['mods'];
        $this->modsNote = $index['note'];
        $this->loader = (array) ($index['loader'] ?? ['present' => false, 'marker' => '']);
        $this->loadConfigFiles($server);

        // Zwei Schalter nach innen, ein Auswahlfeld nach aussen.
        $auto['watch'] = ($auto['check_mods'] ?? true) && ($auto['check_game'] ?? true)
            ? 'both'
            : (($auto['check_mods'] ?? true) ? 'mods' : 'game');
        $auto['backup'] = ($auto['backup'] ?? true) ? 1 : 0;
        $auto['add_input'] = '';

        // Echte Mod-Namen in entschaerfte Feldnamen uebersetzen.
        $this->modKeys = [];
        $perMod = [];
        foreach ($this->mods as $mod) {
            $key = $this->modKey((string) $mod['full_name']);
            $perMod[$key] = $auto['mod_sources'][$mod['full_name']] ?? null;
        }
        $auto['mod_sources'] = $perMod;

        // Der Editor haengt am selben Formular; seine Werte muessen bei
        // jedem fill() mit, sonst stehen die Felder nach dem Speichern leer.
        $auto['config_file'] = $this->configFile;
        foreach ((array) ($this->configDoc['entries'] ?? []) as $i => $entry) {
            $auto['cfg_' . $i] = app(ConfigFile::class)->kind($entry) === 'toggle'
                ? strcasecmp((string) $entry['value'], 'true') === 0
                : $entry['value'];
        }

        $this->form->fill($auto);
    }

    /** @return array<string,mixed> Formularzustand zurueck in Speicherform */
    private function toAuto(): array
    {
        $data = $this->form->getState();

        $data['check_mods'] = ($data['watch'] ?? 'both') !== 'game';
        $data['check_game'] = ($data['watch'] ?? 'both') !== 'mods';
        $data['backup'] = (bool) ($data['backup'] ?? true);

        // Entschaerfte Feldnamen zurueck in echte Mod-Namen. Leere Auswahl
        // heisst "wie oben" und wird verworfen, statt als leerer Quellenname
        // gespeichert zu werden - sonst sammelt die Datei mit jedem Speichern
        // einen Eintrag pro Mod an, den niemand gesetzt hat.
        $perMod = [];
        foreach ((array) ($data['mod_sources'] ?? []) as $key => $source) {
            $name = $this->modKeys[$key] ?? null;
            if ($name !== null && is_string($source) && trim($source) !== '') {
                $perMod[$name] = $source;
            }
        }
        $data['mod_sources'] = $perMod;

        unset($data['watch'], $data['add_input'], $data['config_file']);
        foreach (array_keys($data) as $field) {
            if (str_starts_with((string) $field, 'cfg_')) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    // ------------------------------------------------------------- aktionen

    private function profileChanged(): void
    {
        $this->profile = app(GameProfile::class)->for($this->getServer(), $this->toAuto());
        $this->plan = null;
    }

    public function save(): void
    {
        abort_unless($this->canWrite(), 403);
        $server = $this->getServer();
        $service = app(AutoUpdateService::class);
        $auto = $this->toAuto();

        // Die einzige Verweigerung. Ohne AUTO_UPDATE laedt der Start nichts
        // nach, das Update steht danach immer noch aus, und die naechste
        // Pruefung startet wieder neu. Das ist eine Neustart-Schleife, und die
        // verhindert man besser, als sie zu erkennen.
        if (($auto['enabled'] ?? false) && !$service->autoUpdateEnabled($server)) {
            $auto['enabled'] = false;
            $this->persist($auto);
            Notification::make()
                ->title(trans('mar::messages.notify.needs_flag'))
                ->body(trans('mar::messages.notify.needs_flag_body'))
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $this->persist($auto);
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
        $found = app(AutoUpdateService::class)->detect($this->getServer(), $this->profile, $this->toAuto(), true);
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
        $auto = $this->toAuto();
        $via = $this->messagingVia();

        if ($via === 'none') {
            Notification::make()->title(trans('mar::messages.notify.no_messaging'))->warning()->send();

            return;
        }

        if ($via === 'rcon' && trim((string) ($auto['rcon_password'] ?? '')) === '') {
            Notification::make()->title(trans('mar::messages.notify.rcon_no_password'))->warning()->send();

            return;
        }

        // Nicht nur anmelden: eine sichtbare Nachricht schicken. Eine
        // erfolgreiche Anmeldung beweist noch nicht, dass Spieler die Warnung
        // spaeter auch sehen.
        $ok = app(Messenger::class)->broadcast(
            $this->getServer(), $this->profile, $auto, trans('mar::messages.test_message')
        );

        if ($via === 'rcon') {
            $c = app(RconClient::class)->configFor($this->getServer(), $this->profile, $auto);
            $where = $c['host'] . ':' . $c['port'];
        } else {
            $where = trans('mar::messages.settings.via_console');
        }

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

    // --------------------------------------------------- Mods installieren

    /** Eingabe aufloesen und den Plan zeigen. Aendert nichts auf dem Server. */
    public function preview(): void
    {
        abort_unless($this->canWrite(), 403);

        $auto = $this->toAuto();
        $input = trim((string) ($this->form->getState()['add_input'] ?? ''));
        if ($input === '') {
            return;
        }

        $source = app(GameProfile::class)->sourceFor($this->profile, $auto, '');
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

        $resolved = app(PackageResolver::class)->resolve(
            $input, $this->profile, $source, $installed, (bool) ($this->loader['present'] ?? false)
        );

        if (!$resolved['ok']) {
            Notification::make()->title($resolved['error'])->danger()->send();
            $this->plan = null;

            return;
        }

        // Zeitpunkt des letzten Spiel-Updates als Massstab fuer das
        // Altersurteil. Nicht ermittelbar heisst: kein Urteil, nicht "alt".
        $notes = app(Compatibility::class)->checkAll(
            $resolved['install'], $this->profile,
            app(GameBuild::class)->latestChangedAt($this->profile['app_id'] ?? null)
        );

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
        $this->data['add_input'] = '';
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

    // ------------------------------------------------------- konfiguration

    /** Die im Auswahlfeld gewaehlte Datei in den Editor holen. */
    public function loadConfig(): void
    {
        $this->openConfig(trim((string) ($this->data['config_file'] ?? '')));
    }

    /** Seite mit dieser Datei im Editor neu laden (siehe $pageUrl). */
    public function openConfig(string $path): void
    {
        if ($path === '' || $this->pageUrl === '') {
            return;
        }
        $this->redirect($this->pageUrl . '?config=' . rawurlencode($path));
    }

    public function closeConfig(): void
    {
        if ($this->pageUrl === '') {
            return;
        }
        $this->redirect($this->pageUrl);
    }

    /** Die Werte aus dem Editor in die Datei schreiben. */
    public function saveConfig(): void
    {
        abort_unless($this->canWrite(), 403);
        if ($this->configDoc === null || $this->configFile === '') {
            return;
        }

        $parser = app(ConfigFile::class);
        $values = [];
        $errors = [];

        // Roh aus dem Formularzustand, nicht ueber getState(): das wuerde das
        // ganze Formular pruefen, und hier geht es nur um diese Datei.
        foreach ((array) $this->configDoc['entries'] as $i => $entry) {
            $result = $parser->normalize($entry, $this->data['cfg_' . $i] ?? '');
            if ($result['error'] !== null) {
                $errors[] = $result['error'];

                continue;
            }
            if ($result['value'] !== (string) $entry['value']) {
                $values[$i] = $result['value'];
            }
        }

        if ($errors) {
            Notification::make()
                ->title(trans('mar::messages.config.invalid'))
                ->body(implode("\n", $errors))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if (!$values) {
            Notification::make()->title(trans('mar::messages.config.unchanged'))->send();

            return;
        }

        $server = $this->getServer();
        try {
            app(ConfigStore::class)->write($server, $this->configFile, $parser->render($this->configDoc, $values));
        } catch (\Throwable $e) {
            Notification::make()->title(trans('mar::messages.config.write_failed'))->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->load();
        Notification::make()
            ->title(trans('mar::messages.config.saved', ['n' => count($values)]))
            ->body(trans('mar::messages.config.restart_hint'))
            ->success()
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

    // ---------------------------------------------------------------- intern

    private function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function canWrite(): bool
    {
        return (bool) user()?->can(SubuserPermission::FileUpdate, $this->getServer());
    }

    private function canRestart(): bool
    {
        return (bool) user()?->can(SubuserPermission::ControlRestart, $this->getServer());
    }

    /** @param array<string,mixed> $auto */
    private function persist(array $auto): void
    {
        $server = $this->getServer();
        $store = app(StateStore::class);
        $state = $store->read($server);
        $state['auto'] = $auto;
        $store->write($server, $state);
        // Zurueckgelesen, damit das Formular die begrenzten Werte zeigt und
        // nicht das, was jemand hineingetippt hat.
        $this->load();
    }

    private function forgetIndex(): void
    {
        $path = trim((string) ($this->profile['mods_path'] ?? ''), '/');
        \Illuminate\Support\Facades\Cache::forget("mar:index:{$this->getServer()->id}:" . md5($path));
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
                    'source' => $change['source'] ?? '',
                ];
            }

            $at = Carbon::createFromTimestamp($entry['at'])->setTimezone($zone);
            $rows[] = [
                'at' => $at->format('Y-m-d H:i'),
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
}
