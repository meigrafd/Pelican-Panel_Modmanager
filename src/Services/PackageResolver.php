<?php

namespace Meigrafd\ModAutoRestart\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Macht aus einer Eingabe des Operators eine Liste von Paketen, die
 * tatsaechlich installiert werden muessen.
 *
 * Eingabe darf alles sein, was man irgendwo herauskopiert:
 *
 *   https://thunderstore.io/c/valheim/p/Tristan/ValheimRcon/
 *   https://valheim.hexium.gg/mods/Tristan/ValheimRcon
 *   Tristan-ValheimRcon
 *   Tristan/ValheimRcon
 *
 * Der schwierige Teil sind die Abhaengigkeiten. Im Manifest stehen sie exakt
 * versioniert - "denikson-BepInExPack_Valheim-5.4.2333". Das sieht aus wie eine
 * feste Forderung, ist aber in der Praxis eine Mindestangabe: Jewelcrafting
 * nennt heute 5.4.2333, waehrend die aktuelle Fassung 5.4.2350 ist, und
 * funktioniert damit. Wuerde man die genannte Version woertlich nehmen, liesse
 * sich kaum ein Satz aus mehreren Mods ueberhaupt aufloesen - jede naehme eine
 * andere Nummer desselben Pakets.
 *
 * Deshalb, wie Gale und r2modman es auch machen: Die genannte Version ist die
 * Untergrenze, installiert wird die neueste. Und was schon in einer neueren
 * Fassung liegt, wird NICHT heruntergestuft.
 */
class PackageResolver
{
    /**
     * Tiefe der Abhaengigkeitssuche.
     *
     * Bricht Zyklen ab, auch wenn Thunderstore keine erlauben sollte. Eine
     * Endlosschleife im Panel ist teurer als eine abgelehnte Installation, und
     * echte Ketten sind selten tiefer als drei.
     */
    private const MAX_DEPTH = 10;

    /** @var array<string,array<string,mixed>|null> in diesem Durchlauf geholte Pakete */
    private array $cache = [];

    /**
     * @param  string  $input  URL, "Autor-Paket" oder "Autor/Paket"
     * @param  array<string,mixed>  $profile
     * @param  array<string,array<string,mixed>>  $installed  full_name => ['version' => ...]
     * @param  bool  $loaderPresent  Der Modlader liegt auf dem Server (siehe ModScanner)
     * @return array{
     *   ok: bool,
     *   error: ?string,
     *   root: ?array<string,mixed>,
     *   install: array<int,array<string,mixed>>,
     *   skipped: array<int,array<string,mixed>>,
     *   warnings: array<int,string>
     * }
     */
    public function resolve(string $input, array $profile, string $source, array $installed = [], bool $loaderPresent = false): array
    {
        $blank = ['ok' => false, 'error' => null, 'root' => null, 'install' => [], 'skipped' => [], 'warnings' => []];

        $parsed = $this->parse($input);
        if ($parsed === null) {
            $blank['error'] = 'Daraus kann ich keinen Paketnamen lesen. Erwartet wird eine Paketseite bei Thunderstore oder Hexium, oder "Autor-Paket".';

            return $blank;
        }

        $base = (string) (($profile['sources'] ?? [])[$source] ?? '');
        if ($base === '') {
            $blank['error'] = 'Fuer dieses Spiel ist die Quelle "' . $source . '" nicht hinterlegt.';

            return $blank;
        }

        $root = $this->fetch($base, $parsed['namespace'], $parsed['name'], $source);
        if ($root === null) {
            $blank['error'] = $parsed['namespace'] . '-' . $parsed['name'] . ' gibt es dort nicht (oder die Quelle ist gerade nicht erreichbar).';

            return $blank;
        }

        $install = [];
        $skipped = [];
        $warnings = [];

        $this->walk($root, $base, $installed, $install, $skipped, $warnings, 0, $profile, $loaderPresent);

        return [
            'ok' => true,
            'error' => null,
            'root' => $root,
            // Abhaengigkeiten zuerst. BepInEx und Bibliotheken muessen liegen,
            // bevor das Mod geladen wird, das sie braucht - und wer die Liste
            // liest, soll die Reihenfolge sehen, in der installiert wird.
            'install' => array_reverse($install),
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }

    /**
     * Ein Paket und alles, was daran haengt.
     *
     * @param  array<string,mixed>  $package
     * @param  array<string,array<string,mixed>>  $installed
     * @param  array<int,array<string,mixed>>  $install
     * @param  array<int,array<string,mixed>>  $skipped
     * @param  array<int,string>  $warnings
     */
    private function walk(array $package, string $base, array $installed, array &$install, array &$skipped, array &$warnings, int $depth, array $profile, bool $loaderPresent): void
    {
        $full = $package['namespace'] . '-' . $package['name'];

        foreach ($install as $row) {
            if ($row['full_name'] === $full) {
                return;                     // schon eingeplant
            }
        }

        $have = trim((string) ($installed[$full]['version'] ?? ''));
        $want = (string) $package['version'];

        if ($have !== '') {
            if ($have === $want) {
                $skipped[] = ['full_name' => $full, 'version' => $have, 'why' => 'schon in dieser Version installiert'];
            } elseif ($this->newerOrSame($have, $want)) {
                // Nicht herunterstufen. Eine aeltere Version ueber eine neuere
                // zu schreiben, weil ein anderes Mod sie nennt, ist die
                // haeufigste Art, einen funktionierenden Modsatz zu zerlegen.
                $skipped[] = ['full_name' => $full, 'version' => $have, 'why' => 'installierte Version ' . $have . ' ist neuer als ' . $want];
            } else {
                $install[] = $package + ['full_name' => $full, 'action' => 'update', 'from' => $have];
            }
        } elseif ($depth > 0 && $loaderPresent && $this->providesLoader((string) $package['name'], $profile)) {
            // Der Lader liegt schon da - vom Egg oder von Hand, ohne
            // manifest.json und damit ohne bekannte Version. Als Abhaengigkeit
            // gilt er als erfuellt: eine laufende Installation zu
            // ueberschreiben, weil ein Mod eine Versionsnummer nennt, waere
            // genau das Herunterstufen, das sonst ueberall verboten ist - nur
            // blind. Ausdruecklich per URL (Tiefe 0) wird er weiterhin
            // installiert, dann zusammengefuehrt.
            $skipped[] = ['full_name' => $full, 'version' => '?',
                'why' => 'Modlader liegt schon auf dem Server (vom Egg oder von Hand), Version unbekannt'];
        } else {
            $install[] = $package + ['full_name' => $full, 'action' => 'install', 'from' => null];
        }

        if ($depth >= self::MAX_DEPTH) {
            $warnings[] = 'Abhaengigkeiten von ' . $full . ' wurden nicht weiterverfolgt: zu tief verschachtelt.';

            return;
        }

        foreach ((array) ($package['dependencies'] ?? []) as $dependency) {
            $parts = $this->splitDependency((string) $dependency);
            if ($parts === null) {
                $warnings[] = 'Unlesbare Abhaengigkeit bei ' . $full . ': ' . $dependency;

                continue;
            }

            $child = $this->fetch($base, $parts['namespace'], $parts['name'], (string) ($package['source'] ?? ''));
            if ($child === null) {
                // Kein Abbruch. Das Hauptpaket laesst sich trotzdem
                // installieren, es faehrt nur vielleicht nicht hoch - und das
                // ist eine Information fuer den Operator, keine Entscheidung,
                // die dieser Code treffen sollte.
                $warnings[] = 'Abhaengigkeit ' . $parts['namespace'] . '-' . $parts['name']
                    . ' (von ' . $full . ') ist in dieser Quelle nicht auffindbar.';

                continue;
            }

            if (!$this->newerOrSame((string) $child['version'], $parts['version'])) {
                $warnings[] = $full . ' verlangt ' . $parts['namespace'] . '-' . $parts['name'] . ' ' . $parts['version']
                    . ', die Quelle fuehrt nur ' . $child['version'] . '.';
            }

            $this->walk($child, $base, $installed, $install, $skipped, $warnings, $depth + 1, $profile, $loaderPresent);
        }
    }

    /**
     * Liefert dieses Paket den Modlader des Profils?
     *
     * Praefix-Vergleich auf den Paketnamen, weil jedes Spiel sein eigenes
     * Paket hat (BepInExPack_Valheim, BepInExPack_V_Rising, ...) und der
     * Autor je nach Spiel wechselt.
     *
     * @param  array<string,mixed>  $profile
     */
    public function providesLoader(string $name, array $profile): bool
    {
        foreach ((array) (($profile['loader'] ?? [])['packages'] ?? []) as $prefix) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && stripos($name, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quelle aus einer eingegebenen URL, oder null bei "Autor-Paket".
     *
     * Wer einen Hexium-Link einfuegt, meint Hexium - auch wenn oben
     * Thunderstore gewaehlt ist. Der Host der URL wird gegen die Basis-URLs
     * der Profilquellen verglichen; passt keiner (etwa ein Hexium-Link bei
     * einem Spiel ohne Hexium), bleibt es bei der globalen Wahl.
     *
     * @param  array<string,mixed>  $profile
     */
    public function sourceFromInput(string $input, array $profile): ?string
    {
        $host = strtolower((string) parse_url(trim($input), PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        foreach ((array) ($profile['sources'] ?? []) as $name => $base) {
            $baseHost = strtolower((string) parse_url((string) $base, PHP_URL_HOST));
            if ($baseHost !== '' && ($host === $baseHost || str_ends_with($host, '.' . $baseHost))) {
                return (string) $name;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- eingabe

    /**
     * Autor und Paketname aus einer beliebigen Eingabe.
     *
     * @return array{namespace:string,name:string}|null
     */
    public function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // Paketseiten beider Repositorys. Thunderstore:
        //   /c/<spiel>/p/<autor>/<paket>/   und  /package/<autor>/<paket>/
        // Hexium:
        //   /mods/<autor>/<paket>
        // Begrenzer ist ~, nicht #. Mit # als Begrenzer beendet das # in der
        // Zeichenklasse [^/\s?#] den Ausdruck mitten im Satz, und preg_match
        // liefert wortlos false - jede URL waere unlesbar gewesen, ohne dass
        // irgendwo ein Fehler steht.
        if (preg_match('~/(?:p|package|mods)/([^/\s]+)/([^/\s?#]+)~', $input, $m)) {
            return ['namespace' => $m[1], 'name' => rtrim($m[2], '/')];
        }

        // "Autor/Paket"
        if (preg_match('#^([A-Za-z0-9_]+)/([A-Za-z0-9_\-.]+)$#', $input, $m)) {
            return ['namespace' => $m[1], 'name' => $m[2]];
        }

        // "Autor-Paket". Nur am ERSTEN Bindestrich trennen - Paketnamen duerfen
        // selbst welche enthalten, Autorennamen enden am ersten.
        if (preg_match('#^([A-Za-z0-9_]+)-([A-Za-z0-9_\-.]+)$#', $input, $m)) {
            return ['namespace' => $m[1], 'name' => $m[2]];
        }

        return null;
    }

    /**
     * "Autor-Paket-1.2.3" auseinandernehmen.
     *
     * Von hinten, nicht von vorn: die Version ist das letzte Feld und hat ein
     * festes Format, der Paketname dazwischen darf Bindestriche haben.
     *
     * @return array{namespace:string,name:string,version:string}|null
     */
    public function splitDependency(string $dependency): ?array
    {
        if (!preg_match('#^([A-Za-z0-9_]+)-(.+)-(\d+\.\d+\.\d+)$#', trim($dependency), $m)) {
            return null;
        }

        return ['namespace' => $m[1], 'name' => $m[2], 'version' => $m[3]];
    }

    /** True, wenn $a groesser oder gleich $b ist. */
    public function newerOrSame(string $a, string $b): bool
    {
        return version_compare($a, $b, '>=');
    }

    // -------------------------------------------------------------- abfrage

    /**
     * Ein Paket aus dem Repository holen.
     *
     * @return array<string,mixed>|null
     */
    private function fetch(string $base, string $namespace, string $name, string $source = ''): ?array
    {
        $key = $base . '|' . strtolower($namespace . '-' . $name);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $url = rtrim($base, '/') . '/api/experimental/package/'
                . rawurlencode($namespace) . '/' . rawurlencode($name) . '/';

            $response = Http::timeout(15)->withHeaders(['Accept' => 'application/json'])->get($url);

            if (!$response->successful()) {
                return $this->cache[$key] = null;
            }

            $data = $response->json();
            $latest = $data['latest'] ?? null;
            if (!is_array($latest) || trim((string) ($latest['version_number'] ?? '')) === '') {
                return $this->cache[$key] = null;
            }

            // Kategorien der Gemeinschaft, die zu diesem Spiel gehoert. Daraus
            // kommt spaeter die Unterscheidung Client- gegen Serverseite.
            $categories = [];
            foreach ((array) ($data['community_listings'] ?? []) as $listing) {
                foreach ((array) ($listing['categories'] ?? []) as $category) {
                    $categories[$category] = true;
                }
            }

            return $this->cache[$key] = [
                // Aus der Antwort, nicht aus der Anfrage: die API korrigiert
                // Gross- und Kleinschreibung, und der Ordnername auf der Platte
                // muss spaeter exakt passen.
                'namespace' => (string) ($data['namespace'] ?? $namespace),
                'name' => (string) ($data['name'] ?? $name),
                'version' => (string) $latest['version_number'],
                'download_url' => (string) ($latest['download_url'] ?? ''),
                'dependencies' => (array) ($latest['dependencies'] ?? []),
                'description' => (string) ($latest['description'] ?? ''),
                'deprecated' => (bool) ($data['is_deprecated'] ?? false),
                'updated' => strtotime((string) ($data['date_updated'] ?? '')) ?: null,
                'categories' => array_keys($categories),
                // Welche Quelle diese Angaben geliefert hat. Die
                // Kompatibilitaetspruefung befragt spaeter gezielt die ANDERE,
                // weil beide unterschiedliche Kategorien fuehren.
                'source' => $source,
            ];
        } catch (\Throwable $e) {
            Log::info('mod-auto-restart: Paket nicht abrufbar', [
                'package' => $namespace . '-' . $name,
                'error' => $e->getMessage(),
            ]);

            return $this->cache[$key] = null;
        }
    }
}
