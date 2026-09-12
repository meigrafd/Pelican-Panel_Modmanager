<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Holt und schreibt die Konfigurationsdateien der Mods ueber Wings.
 *
 * Die Liste liest jede Datei einmal, um Plugin-Name und GUID aus der Kopfzeile
 * zu bekommen - anders laesst sich eine Datei keinem Mod zuordnen. Das sind so
 * viele Aufrufe wie Dateien, deshalb im Cache, wie der Mod-Index auch.
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

        $key = $this->cacheKey($server, $dir);
        if (!$fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
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
            try {
                $doc = $this->read($server, $row['path']);
                $row['plugin'] = $doc['plugin'];
                $row['guid'] = $doc['guid'];
            } catch (\Throwable $e) {
                // Unlesbar: trotzdem auflisten, nur ohne Zuordnung.
            }
            $out[] = $row;
        }

        usort($out, fn ($a, $b) => strcasecmp($a['file'], $b['file']));

        Cache::put($key, $out, now()->addMinutes(
            max(1, (int) config('mod-auto-restart.cache.index_minutes', 10))
        ));

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
        $this->forget($server, dirname($path));
    }

    public function forget(Server $server, string $dir): void
    {
        Cache::forget($this->cacheKey($server, trim($dir, '/')));
    }

    private function cacheKey(Server $server, string $dir): string
    {
        return "mar:cfg:{$server->id}:" . md5($dir);
    }
}
