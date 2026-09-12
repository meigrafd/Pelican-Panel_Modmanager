<?php

namespace Meigrafd\ModAutoRestart\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fragt ein Mod-Repository nach der neuesten Version eines Pakets.
 *
 * Thunderstore und Hexium sprechen dieselbe API, deshalb reicht ein Client fuer
 * beide - es aendert sich nur die Basis-URL. Bei Thunderstore ist die sogar
 * fuer alle Spiele gleich: der Paket-Endpunkt kennt Autor und Paketname, aber
 * keine Community. Hexium gibt jedem Spiel eine eigene Subdomain.
 *
 * Jede Anfrage traegt ihre Quelle mit sich, weil dieselbe Mod in zwei
 * Repositorys unterschiedliche Versionsnummern haben kann. Wuerde hier
 * gemischt, startete der Server zwischen zwei Quellen im Kreis neu.
 *
 * Ein Fehlschlag liefert NIE eine Version, sondern null, und setzt degraded().
 * "Nicht erreichbar" darf nicht wie "nichts Neues" aussehen und erst recht
 * nicht wie "veraltet" - sonst startet der Server wegen eines DNS-Aussetzers
 * neu.
 */
class RegistryClient
{
    private const PACKAGE_PATH = '/api/experimental/package/{namespace}/{name}/';

    /** Ein Fehlschlag sperrt weitere Anfragen an DIESE Quelle fuer diese Zeit. */
    private const BACKOFF_SECONDS = 300;

    /** @var array<string,bool> Quellen, die in diesem Durchlauf versagt haben */
    private array $degraded = [];

    /**
     * Neueste Versionen fuer mehrere Pakete, jedes mit seiner eigenen Quelle.
     *
     * @param  array<int,array{namespace:string,name:string,source:?string}>  $packages
     * @param  array<string,string>  $sources  Quellenname => Basis-URL
     * @return array<string,array{version:?string,updated:?int,source:?string}>
     */
    public function latest(array $packages, array $sources, bool $fresh = false): array
    {
        $out = [];

        foreach ($packages as $package) {
            $namespace = trim((string) ($package['namespace'] ?? ''));
            $name = trim((string) ($package['name'] ?? ''));
            $source = (string) ($package['source'] ?? '');
            $base = (string) ($sources[$source] ?? '');

            if ($namespace === '' || $name === '' || $base === '') {
                continue;
            }

            $out[$namespace . '-' . $name] = $this->one($base, $source, $namespace, $name, $fresh)
                + ['source' => $source];
        }

        return $out;
    }

    /** @return array{version:?string,updated:?int} */
    private function one(string $base, string $source, string $namespace, string $name, bool $fresh): array
    {
        $blank = ['version' => null, 'updated' => null];
        $cacheKey = 'mar:pkg:' . md5($base . '|' . $namespace . '|' . $name);

        if (!$fresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Nach einem Fehlschlag nicht weiter gegen eine tote Schnittstelle
        // laufen. Kein Hoeflichkeitsgestus: eine minuetliche Tick-Schleife ueber
        // ein Panel mit mehreren Servern setzt bei einem Ausfall sonst hunderte
        // Anfragen ab, jede mit ihrem eigenen Zeitlimit.
        if (Cache::get($this->backoffKey($base))) {
            $this->degraded[$source] = true;

            return $blank;
        }

        try {
            $url = rtrim($base, '/') . strtr(self::PACKAGE_PATH, [
                '{namespace}' => rawurlencode($namespace),
                '{name}' => rawurlencode($name),
            ]);

            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url);

            if ($response->status() === 404) {
                // Das Paket gibt es dort nicht. Eine Tatsache, kein Ausfall -
                // und deshalb kein degraded: eine von Hand hochgeladene DLL ohne
                // Repository-Eintrag darf die Pruefung der uebrigen Mods nicht
                // rot faerben.
                Cache::put($cacheKey, $blank, now()->addMinutes($this->ttl()));

                return $blank;
            }

            if (!$response->successful()) {
                throw new \RuntimeException('HTTP ' . $response->status());
            }

            $data = $response->json();
            $version = trim((string) ($data['latest']['version_number'] ?? ''));
            if ($version === '') {
                throw new \RuntimeException('Antwort ohne version_number');
            }

            $result = [
                'version' => $version,
                'updated' => strtotime((string) ($data['date_updated'] ?? '')) ?: null,
            ];

            Cache::put($cacheKey, $result, now()->addMinutes($this->ttl()));

            return $result;
        } catch (\Throwable $e) {
            $this->degraded[$source] = true;
            Cache::put($this->backoffKey($base), true, now()->addSeconds(self::BACKOFF_SECONDS));
            Log::info('mod-auto-restart: Repository nicht erreichbar', [
                'source' => $source,
                'package' => $namespace . '-' . $name,
                'error' => $e->getMessage(),
            ]);

            // Ein alter Cache-Eintrag ist besser als nichts, solange oben
            // degraded gemeldet wird. Ein Neustart wird darauf nie gestuetzt.
            $stale = Cache::get($cacheKey);

            return is_array($stale) ? $stale : $blank;
        }
    }

    /** True, wenn in diesem Durchlauf mindestens eine Anfrage fehlschlug. */
    public function degraded(): bool
    {
        return $this->degraded !== [];
    }

    /** @return string[] Namen der Quellen, die versagt haben */
    public function degradedSources(): array
    {
        return array_keys($this->degraded);
    }

    /**
     * Sperren aufheben - nur fuer den "Jetzt pruefen"-Knopf.
     *
     * @param  array<string,string>  $sources
     */
    public function clearBackoff(array $sources): void
    {
        foreach ($sources as $base) {
            Cache::forget($this->backoffKey($base));
        }
        $this->degraded = [];
    }

    /** Seite des Pakets im jeweiligen Repository, fuer die Historie. */
    public function pageUrl(array $profile, ?string $source, string $namespace, string $name): string
    {
        $template = (string) (($profile['page_url'] ?? [])[$source] ?? '');
        if ($template === '') {
            return '';
        }

        return strtr($template, ['{namespace}' => $namespace, '{name}' => $name]);
    }

    private function backoffKey(string $base): string
    {
        return 'mar:backoff:' . md5($base);
    }

    private function ttl(): int
    {
        return max(1, (int) config('mod-auto-restart.cache.registry_minutes', 10));
    }
}
