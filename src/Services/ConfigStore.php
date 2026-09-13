<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Holt und schreibt die Konfigurationsdateien der Mods ueber Wings.
 *
 * Die Liste liest jede Datei einmal, um Plugin-Name und GUID aus der Kopfzeile
 * zu bekommen - anders laesst sich eine Datei keinem Mod zuordnen. Das
 * Verzeichnis selbst wird bei jedem Aufruf gelesen (ein Wings-Aufruf), damit
 * eine Datei, die der Server gerade erst angelegt hat, sofort erscheint. Nur
 * die Kopfzeilen liegen im Cache, je Datei unter Groesse und Aenderungszeit:
 * eine geaenderte Datei wird neu gelesen, eine unveraenderte kostet nichts.
 * Geschrieben wird nie aus dem Cache, sondern aus dem, was gerade gelesen wurde.
 */
class ConfigStore
{
    /** Groesser ist keine Konfigurationsdatei, sondern ein Versehen. */
    private const MAX_BYTES = 500_000;

    public function __construct(private DaemonFileRepository $files, private ConfigFile $parser) {}

    /**
     * @return array<int,array{file:string,path:string,plugin:string,guid:string}>
     */
    public function list(Server $server, string $dir, bool $fresh = false): array
    {
        $dir = trim($dir, '/');
        if ($dir === '') {
            return [];
        }

        $out = [];
        try {
            $entries = $this->files->setServer($server)->getDirectory('/' . $dir);
        } catch (\Throwable $e) {
            // Kein config-Ordner: der Server lief noch nie mit dem Lader. Kein
            // Fehler, es gibt nur nichts zu bearbeiten.
            $entries = [];
        }

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || (bool) ($entry['directory'] ?? false) || !str_ends_with(strtolower($name), '.cfg')) {
                continue;
            }
            $row = ['file' => $name, 'path' => $dir . '/' . $name, 'plugin' => '', 'guid' => ''];
            $key = $this->headerKey($server, $row['path'], $entry);
            $head = $fresh ? null : Cache::get($key);
            if (!is_array($head)) {
                $head = ['plugin' => '', 'guid' => ''];
                try {
                    $doc = $this->read($server, $row['path']);
                    $head = ['plugin' => $doc['plugin'], 'guid' => $doc['guid']];
                } catch (\Throwable $e) {
                    // Unlesbar: trotzdem auflisten, nur ohne Zuordnung.
                }
                Cache::put($key, $head, now()->addDay());
            }
            $out[] = $row + $head;
        }

        usort($out, fn ($a, $b) => strcasecmp($a['file'], $b['file']));

        return $out;
    }

    /** @return array<string,mixed> siehe ConfigFile::parse() */
    public function read(Server $server, string $path): array
    {
        $raw = (string) $this->files->setServer($server)->getContent('/' . ltrim($path, '/'), self::MAX_BYTES);

        return $this->parser->parse($raw);
    }

    public function write(Server $server, string $path, string $raw): void
    {
        $this->files->setServer($server)->putContent('/' . ltrim($path, '/'), $raw);
        // Kein Cache zu leeren: die Aenderungszeit der Datei ist Teil des
        // Schluessels, die naechste Liste liest die Kopfzeile ohnehin neu.
    }

    /** @param  array<string,mixed>  $entry  Verzeichniseintrag von Wings */
    private function headerKey(Server $server, string $path, array $entry): string
    {
        return "mar:cfghead:{$server->id}:" . md5($path . '|' . ($entry['size'] ?? '') . '|' . ($entry['modified'] ?? ''));
    }
}
