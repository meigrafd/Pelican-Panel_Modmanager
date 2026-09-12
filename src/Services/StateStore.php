<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;

/**
 * Kleine JSON-Datei neben den Serverdateien. Haelt:
 *
 *  - `auto`: die Einstellungen von der Seite und die Phase eines laufenden
 *    Neustarts.
 *  - `history`: was das Plugin wann neu gestartet hat und was sich dabei
 *    geaendert hat.
 *
 * Bewusst nicht im Cache: hier haengt dran, ob ein Server sich selbst neu
 * startet, und `artisan optimize:clear` ist auf einem Panel Alltag. Ein
 * geleerter Cache darf die Funktion nicht heimlich wieder einschalten, einen
 * halbfertigen Neustart nicht vergessen und einen bereits gescheiterten Versuch
 * nicht verlieren.
 */
class StateStore
{
    private const FILE = '.mod-auto-restart.json';

    public const HISTORY_MAX = 20;

    /**
     * Einstellungen und ihre Vorgaben. Alles, was ein Operator aendern kann,
     * steht genau einmal hier; read() zwingt die Datei auf diese Typen, damit
     * eine von Hand bearbeitete Datei keinen Text in einen Countdown und keine
     * negative Zahl in einen Timer schieben kann.
     */
    public const AUTO_DEFAULTS = [
        // Aus, bis jemand es einschaltet. Diese Funktion startet Server neu.
        'enabled' => false,
        // Spielprofil. Leer heisst: am Egg-Namen erkennen.
        'profile' => '',
        // Ueberschreibt die App-Id aus Profil und Egg. Leer heisst: nicht
        // ueberschreiben.
        'app_id' => '',
        // Ueberschreibt den Mod-Ordner aus dem Profil.
        'mods_path' => '',
        // Global gewaehlte Mod-Quelle. Leer heisst: die erste des Profils.
        'source' => '',
        'check_minutes' => 5,
        'warn_minutes' => 5,
        'countdown_seconds' => 15,
        'backup' => true,
        'backup_wait_seconds' => 120,
        // Was ueberwacht wird: Mods, Spiel, oder beides.
        'check_mods' => true,
        'check_game' => true,
        'cooldown_minutes' => 30,
        'msg_warn' => 'Server-Neustart in :minutes Minuten fuer ein :reason-Update.',
        'msg_final' => 'Server-Neustart in einer Minute. Sucht euch einen sicheren Platz.',
        'msg_countdown' => 'Neustart in :seconds',
        'msg_back' => 'Update eingespielt. Willkommen zurueck.',
        // RCON (ValheimRcon). Ohne Passwort keine Ansagen - der Neustart
        // findet trotzdem statt.
        'rcon_host' => '',
        'rcon_port' => 0,
        'rcon_password' => '',
    ];

    /**
     * Ausnahmen pro Mod: full_name => Quellenname.
     *
     * Bewusst nicht in AUTO_DEFAULTS, weil das eine Abbildung ist und kein
     * Einzelwert - die Typangleichung dort kann damit nichts anfangen. Geprueft
     * wird sie in modSources().
     */
    public const MOD_SOURCES_MAX = 500;

    private const AUTO_MIN = [
        'check_minutes' => 1,
        'warn_minutes' => 0,
        'countdown_seconds' => 0,
        'backup_wait_seconds' => 0,
        'cooldown_minutes' => 0,
        'rcon_port' => 0,
    ];

    private const AUTO_MAX = [
        'check_minutes' => 240,
        'warn_minutes' => 60,
        'countdown_seconds' => 60,
        'backup_wait_seconds' => 900,
        'cooldown_minutes' => 1440,
        'rcon_port' => 65535,
    ];

    public function __construct(private DaemonFileRepository $files) {}

    /** @return array{auto:array<string,mixed>,run:array<string,mixed>,history:array<int,array<string,mixed>>} */
    public function read(Server $server): array
    {
        try {
            $raw = (string) $this->files->setServer($server)->getContent(self::FILE, 200_000);
            $data = json_decode($raw, true);
        } catch (\Throwable $e) {
            $data = null;
        }

        $auto = $this->auto(is_array($data['auto'] ?? null) ? $data['auto'] : []);
        $auto['mod_sources'] = $this->modSources(
            is_array($data['auto']['mod_sources'] ?? null) ? $data['auto']['mod_sources'] : []
        );

        return [
            'auto' => $auto,
            'run' => is_array($data['run'] ?? null) ? $data['run'] : [],
            'history' => $this->history(is_array($data['history'] ?? null) ? $data['history'] : []),
        ];
    }

    /**
     * Historie in eine Form bringen, die die Ansicht blind ausgeben kann.
     *
     * Die Ansicht laeuft diese Liste durch, um dem Operator zu zeigen, was sein
     * Server nachts getan hat. Ein abgeschnittener Schreibvorgang darf eine
     * kuerzere Liste ergeben, aber keine Seite, die auf halber Hoehe abbricht.
     *
     * @param  array<mixed>  $stored
     * @return array<int,array<string,mixed>>
     */
    private function history(array $stored): array
    {
        $out = [];

        foreach ($stored as $entry) {
            if (!is_array($entry) || !is_numeric($entry['at'] ?? null)) {
                continue;
            }

            $changes = [];
            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $changes[] = [
                    'kind' => ($change['kind'] ?? '') === 'game' ? 'game' : 'mod',
                    'id' => mb_substr(trim((string) ($change['id'] ?? '')), 0, 120),
                    'name' => mb_substr(trim((string) ($change['name'] ?? '')), 0, 200),
                    'from' => mb_substr(trim((string) ($change['from'] ?? '')), 0, 80),
                    'to' => mb_substr(trim((string) ($change['to'] ?? '')), 0, 80),
                    'url' => filter_var((string) ($change['url'] ?? ''), FILTER_VALIDATE_URL) ?: '',
                    'source' => mb_substr(trim((string) ($change['source'] ?? '')), 0, 40),
                ];
            }

            $out[] = [
                'at' => (int) $entry['at'],
                'trigger' => ($entry['trigger'] ?? '') === 'manual' ? 'manual' : 'auto',
                'reason' => mb_substr(trim((string) ($entry['reason'] ?? '')), 0, 40),
                'by' => mb_substr(trim((string) ($entry['by'] ?? '')), 0, 60),
                'changes' => array_slice($changes, 0, 40),
                'players' => is_numeric($entry['players'] ?? null) ? (int) $entry['players'] : null,
                'backup_id' => is_numeric($entry['backup_id'] ?? null) ? (int) $entry['backup_id'] : null,
                'warned' => (bool) ($entry['warned'] ?? false),
                'outcome' => in_array($entry['outcome'] ?? '', ['pending', 'verified', 'failed', 'unverified', 'dry-run'], true)
                    ? (string) $entry['outcome']
                    : 'unverified',
                'note' => mb_substr(trim((string) ($entry['note'] ?? '')), 0, 400),
                'down' => (int) ($entry['down'] ?? 0),
            ];
        }

        // Erst sortieren, dann kappen. Eine in falscher Reihenfolge
        // geschriebene Datei darf nicht ihre neuesten Eintraege verlieren.
        usort($out, fn ($a, $b) => $b['at'] <=> $a['at']);

        return array_slice($out, 0, self::HISTORY_MAX);
    }

    /**
     * @param  array<int,array<string,mixed>>  $history
     * @param  array<string,mixed>  $entry
     * @return array<int,array<string,mixed>>
     */
    public function remember(array $history, array $entry): array
    {
        array_unshift($history, $entry);

        return $this->history($history);
    }

    /**
     * Gespeicherte Einstellungen auf die Vorgaben zwingen.
     *
     * Zahlen werden begrenzt, nicht abgelehnt. Ein warn_minutes von 100000
     * woertlich gelesen wuerde einen Neustart in elf Wochen planen und aussehen,
     * als sei die Funktion schlicht kaputt.
     *
     * @param  array<string,mixed>  $stored
     * @return array<string,mixed>
     */
    private function auto(array $stored): array
    {
        $out = self::AUTO_DEFAULTS;

        foreach ($out as $key => $default) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            $value = $stored[$key];

            if (is_bool($default)) {
                $out[$key] = (bool) $value;
            } elseif (is_int($default)) {
                if (!is_numeric($value)) {
                    continue;
                }
                $out[$key] = max(
                    self::AUTO_MIN[$key] ?? 0,
                    min(self::AUTO_MAX[$key] ?? PHP_INT_MAX, (int) $value)
                );
            } elseif ($value === null || is_string($value)) {
                // Eine leere Nachricht heisst "nichts sagen" und ist eine
                // zulaessige Wahl, deshalb wird hier nur der Typ erzwungen.
                // Filament liefert fuer ein geleertes Textfeld null, nicht "".
                // Wuerde null uebersprungen, kaeme beim Zurueckladen die
                // Vorgabe wieder, und eine Nachricht liesse sich nie abschalten.
                $out[$key] = mb_substr(trim((string) $value), 0, 400);
            }
        }

        return $out;
    }

    /**
     * Ausnahmen pro Mod auf eine saubere Abbildung zwingen.
     *
     * Ein leerer Wert bedeutet "keine Ausnahme" und wird verworfen statt als
     * leerer Quellenname gespeichert - sonst sammelt die Datei mit jedem
     * Speichern einen Eintrag pro Mod an, den niemand gesetzt hat.
     *
     * @param  array<mixed>  $stored
     * @return array<string,string>
     */
    private function modSources(array $stored): array
    {
        $out = [];

        foreach ($stored as $mod => $source) {
            if (!is_string($mod) || !is_string($source)) {
                continue;
            }
            $mod = mb_substr(trim($mod), 0, 120);
            $source = mb_substr(trim($source), 0, 40);
            if ($mod === '' || $source === '') {
                continue;
            }
            $out[$mod] = $source;
            if (count($out) >= self::MOD_SOURCES_MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array{auto?:array<string,mixed>,run?:array<string,mixed>,history?:array<int,array<string,mixed>>} $state
     */
    public function write(Server $server, array $state): void
    {
        try {
            $this->files->setServer($server)->putContent(
                self::FILE,
                (string) json_encode([
                    'auto' => $this->auto($state['auto'] ?? [])
                        + ['mod_sources' => $this->modSources((array) (($state['auto'] ?? [])['mod_sources'] ?? []))],
                    'run' => $state['run'] ?? [],
                    'history' => $this->history($state['history'] ?? []),
                ], JSON_PRETTY_PRINT)
            );
        } catch (\Throwable $e) {
            // Nicht kritisch fuer die laufende Anfrage; der naechste Tick
            // liest, was da ist.
        }
    }
}
