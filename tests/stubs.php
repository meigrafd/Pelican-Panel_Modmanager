<?php

/**
 * Ersatzteile fuer alles, womit AutoUpdateService redet.
 *
 * Der Konstruktor des Dienstes ist gegen seine Mitspieler typisiert, deshalb
 * werden diese hier im echten Namensraum deklariert und zuerst geladen: PHP
 * greift dann nie zu den richtigen Dateien, und die Phasenlogik laesst sich
 * ohne Panel, ohne Datenbank und ohne Spielserver durchspielen. Jedes Teil
 * schreibt mit, was von ihm verlangt wurde; die Tests pruefen diese
 * Mitschriften.
 *
 * StateStore ist die Ausnahme und wird echt geladen, weil seine Vorgaben und
 * die Wertbegrenzung genau das sind, was die Phasen respektieren sollen. Ein
 * gefaelschter Speicher liesse die Tests mit sich selbst uebereinstimmen statt
 * mit dem Code, der ausgeliefert wird.
 *
 * Uebernommen aus tests/stubs.php von pz-mod-manager und auf die universelle
 * Fassung angepasst.
 */

namespace {
    if (!function_exists('now')) {
        function now()
        {
            return new class {
                public int $timestamp;

                public function __construct()
                {
                    $this->timestamp = time();
                }

                public function format(string $f): string
                {
                    return date($f);
                }
            };
        }
    }

    if (!function_exists('config')) {
        /** @var array<string,mixed> von Tests gesetzt, etwa fuer den Probelauf */
        $GLOBALS['test_config'] = [];

        // Die ECHTE Konfiguration, nicht eine nachgebaute. Die Spielprofile
        // sind Daten, die der Dienst auswertet - ein Test gegen erfundene
        // Profile pruefte die Erfindung, nicht das, was ausgeliefert wird.
        // Genau daran ist dieser Test beim ersten Lauf gescheitert: ein leeres
        // config() liess jedes Profil ohne Quellen dastehen, und die halbe
        // Erkennung stieg still aus.
        if (!function_exists('env')) {
            function env($key, $default = null)
            {
                return $default;
            }
        }
        $GLOBALS['real_config'] = require __DIR__ . '/../config/mod-auto-restart.php';

        function config($key, $default = null)
        {
            if (array_key_exists($key, $GLOBALS['test_config'])) {
                return $GLOBALS['test_config'][$key];
            }

            $path = explode('.', str_replace('mod-auto-restart.', '', $key));
            $value = $GLOBALS['real_config'];
            foreach ($path as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }

            return $value;
        }
    }

    if (!function_exists('app')) {
        function app($class = null)
        {
            // Nur der Backup-Dienst wird so aufgeloest, und ein Backup, das
            // nicht starten kann, darf einen Neustart nicht mit sich reissen.
            // Hier zu werfen ist der interessante Fall, kein Versehen.
            return new class {
                public function handle(...$args)
                {
                    throw new \RuntimeException('kein Backup-Dienst im Test');
                }
            };
        }
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        /** @var array<int,string> */
        public static array $lines = [];

        public static function __callStatic($name, $args)
        {
            self::$lines[] = $name . ': ' . ($args[0] ?? '');
        }
    }
}

namespace App\Models {
    /** Nur, was der Dienst anfasst: Egg-Variablen und eine Allocation. */
    class Server
    {
        public int $id = 1;

        public string $name = 'Testserver';

        public array $variables = [];

        public $egg;

        public $allocation;

        public function __construct(string $autoUpdate = '1', string $eggName = 'Valheim BepInEx')
        {
            $this->egg = (object) ['name' => $eggName];
            $this->allocation = (object) ['ip' => '127.0.0.1', 'port' => 2456];
            $this->variables = [
                (object) ['env_variable' => 'AUTO_UPDATE', 'server_value' => $autoUpdate, 'default_value' => '0', 'id' => 9],
                (object) ['env_variable' => 'SRCDS_APPID', 'server_value' => '896660', 'default_value' => '', 'id' => 10],
            ];
        }
    }

    class ServerVariable
    {
        public static function updateOrCreate(array $keys, array $values): void {}
    }

    class Backup
    {
        /** Steht fuer ein Backup, das noch nicht fertig ist. */
        public const RUNNING = 77;

        public $completed_at = null;

        public static function find($id)
        {
            $b = new self();
            $b->completed_at = $id === self::RUNNING ? null : 'fertig';

            return $b;
        }
    }
}

namespace App\Services\Backups {
    class InitiateBackupService {}
}

namespace Meigrafd\ModAutoRestart\Services {
    class ModScanner
    {
        /** @var array<int,array<string,mixed>> */
        public static array $installed = [
            ['full_name' => 'Autor-ModA', 'namespace' => 'Autor', 'name' => 'ModA',
                'version' => '1.0.0', 'folder' => 'Autor-ModA', 'tracked' => true],
        ];

        public static bool $ok = true;

        public function index($server, array $profile, bool $fresh = false): array
        {
            return ['ok' => self::$ok, 'mods' => self::$installed, 'note' => 'Test'];
        }
    }

    class RegistryClient
    {
        /** @var array<string,array<string,mixed>> was das Repository zurueckmeldet */
        public static array $latest = [
            'Autor-ModA' => ['version' => '1.0.0', 'updated' => 1000, 'source' => 'thunderstore'],
        ];

        /** Gezaehlt, weil die Zahl der Anfragen selbst eine Anforderung ist. */
        public static int $calls = 0;

        public static bool $degraded = false;

        public function latest(array $packages, array $sources, bool $fresh = false): array
        {
            self::$calls++;

            return self::$latest;
        }

        public function degraded(): bool
        {
            return self::$degraded;
        }

        public function degradedSources(): array
        {
            return self::$degraded ? ['thunderstore'] : [];
        }

        public function clearBackoff(array $sources): void {}

        public function pageUrl(array $profile, ?string $source, string $ns, string $name): string
        {
            return '';
        }
    }

    class Messenger
    {
        /** @var array<int,string> alles, was an die Spieler ging */
        public static array $said = [];

        /** null heisst "Spielerzahl unbekannt" - der vorsichtige Fall. */
        public static ?int $players = 0;

        /** Simuliert einen kaputten Ansageweg, etwa RCON nach einem Update. */
        public static bool $working = true;

        public static function reset(): void
        {
            self::$said = [];
        }

        public function broadcast($server, array $profile, array $auto, string $text): bool
        {
            if (!self::$working) {
                return false;
            }
            self::$said[] = $text;

            return true;
        }

        public function save($server, array $profile, array $auto): void
        {
            self::$said[] = '[save]';
        }

        public function players($server, array $profile, array $auto): ?int
        {
            return self::$players;
        }

        public function available(array $profile, array $auto): bool
        {
            return self::$working;
        }
    }

    class GameBuild
    {
        public static array $result = ['outdated' => false, 'installed' => 100, 'latest' => 100];

        public static ?int $installedBuild = 100;

        public function compare($server, ?string $appId, bool $fresh = false): array
        {
            return self::$result;
        }

        public function installed($server, ?string $appId): ?int
        {
            return self::$installedBuild;
        }
    }

    class PowerService
    {
        /** @var array<int,string> */
        public static array $sent = [];

        public static function reset(): void
        {
            self::$sent = [];
        }

        public function setServer($server): self
        {
            return $this;
        }

        public function send(string $action): void
        {
            self::$sent[] = $action;
        }
    }
}

namespace {
    // Echt geladen: GameProfile ist reine Logik ohne Aussenwelt, und seine
    // Aufloesungsreihenfolge ist genau das, was die Phasen benutzen.
    require __DIR__ . '/../src/Services/GameProfile.php';
    require __DIR__ . '/../src/Services/StateStore.php';

    class MemoryStore extends Meigrafd\ModAutoRestart\Services\StateStore
    {
        public array $state;

        /** Ruft absichtlich nicht den Elternkonstruktor auf: hier gibt es keinen Daemon. */
        public function __construct(array $auto, array $run, array $history = [])
        {
            $this->state = ['auto' => $auto, 'run' => $run, 'history' => $history];
        }

        public function read($server): array
        {
            return $this->state;
        }

        public function write($server, array $state): void
        {
            $this->state = $state;
        }
    }
}
