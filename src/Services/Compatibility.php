<?php

namespace Meigrafd\ModAutoRestart\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sagt, was gegen die Installation eines Pakets spricht.
 *
 * Wichtig vorweg, weil der Name mehr verspricht, als er halten kann: Es gibt in
 * keinem der Repositorys ein Feld fuer die Spielversion. Kein Mod sagt, gegen
 * welche Fassung es gebaut wurde. Eine echte Kompatibilitaetspruefung ist also
 * nicht moeglich - nur Indizien. Deshalb blockiert hier nur eines, und das ist
 * eine Tatsache: der Autor hat das Paket zurueckgezogen. Alles andere ist ein
 * Hinweis, und der Operator entscheidet.
 *
 * Die beiden Repositorys fuehren UNTERSCHIEDLICHE Indizien, und das ist der
 * Grund, warum diese Klasse beide befragen darf:
 *
 *   Hexium       hat einen ausdruecklichen Tag "Valheim 1.0".
 *   Thunderstore hat "Client-side" und "Server-side", dafuer keinen
 *                Versionstag - nur Epochen wie "Bog Witch Update", die alle vor
 *                1.0 liegen.
 *
 * Versionsnummern zu mischen waere gefaehrlich (der Server startete zwischen
 * zwei Quellen im Kreis neu). Hinweise zu mischen ist genau richtig: welche
 * Version installiert wird, entscheidet weiterhin genau eine Quelle.
 *
 * Wie belastbar der 1.0-Tag ist, wurde an den 844 Paketen bei Hexium
 * nachgezaehlt:
 *
 *   280 tragen ihn, davon 273 seit dem 1. September aktualisiert.
 *   ABER 202 Pakete ohne den Tag wurden ebenfalls seit dem 9. September
 *   aktualisiert - die Autoren haben ihn schlicht nicht gesetzt.
 *
 * Daraus folgt die Regel unten: Tag vorhanden ist ein starkes Ja. Tag fehlend
 * sagt fast nichts und darf deshalb keine Warnung ausloesen. Sonst waere jedes
 * zweite gepflegte Mod als verdaechtig markiert, und eine Warnung, die staendig
 * faelschlich erscheint, liest bald niemand mehr.
 */
class Compatibility
{
    /** @var array<string,array<int,string>> Kategorien, die schon geholt wurden */
    private array $cache = [];

    /**
     * Alles pruefen, was ohne Herunterladen geht.
     *
     * @param  array<string,mixed>  $package  aus PackageResolver
     * @param  array<string,mixed>  $profile
     * @param  ?int  $gameBuildAt  Zeitpunkt des letzten Spiel-Updates, oder null
     * @return array<int,array{level:string,text:string}>  level: 'stop', 'warn' oder 'good'
     */
    public function check(array $package, array $profile = [], ?int $gameBuildAt = null): array
    {
        $notes = [];
        $name = (string) ($package['namespace'] ?? '') . '-' . (string) ($package['name'] ?? '');

        if ($package['deprecated'] ?? false) {
            // Das einzige 'stop'. Der Autor selbst sagt, man solle es nicht
            // mehr benutzen - eine Tatsache, kein Verdacht.
            $notes[] = ['level' => 'stop', 'text' => $name . ' ist vom Autor zurueckgezogen worden.'];
        }

        $signals = (array) ($profile['signals'] ?? []);
        $categories = $this->categories($package, $profile, $signals);

        $tag = (array) ($signals['current_tag'] ?? []);
        $tagName = trim((string) ($tag['name'] ?? ''));
        $tagged = $tagName !== '' && $this->has($categories, $tagName);

        // Ausdruecklicher Tag fuer die aktuelle Spielgeneration. Nur als
        // Bestaetigung verwertbar, nie als Verdacht - siehe Klassenkopf.
        if ($tagged) {
            $notes[] = ['level' => 'good', 'text' => $name . ' ist als "' . $tagName . '" ausgezeichnet.'];
        }

        $updated = $package['updated'] ?? null;

        // Ueber das Alter wird nur gemeckert, wenn der Tag NICHT da ist. Ein
        // ausgezeichnetes Mod, das seit dem Spiel-Update nicht angefasst wurde,
        // ist genau der Fall, in dem nichts anzufassen war.
        if (!$tagged && $updated !== null && $gameBuildAt !== null && $updated < $gameBuildAt) {
            $days = (int) floor(($gameBuildAt - $updated) / 86400);
            $notes[] = ['level' => 'warn', 'text' => $name . ' wurde seit dem letzten Spiel-Update nicht mehr angefasst'
                . ($days > 0 ? ' (' . $days . ' Tage aelter)' : '') . '. Kann trotzdem laufen, muss aber nicht.'];
        }

        $client = $this->has($categories, 'Client-side');
        $server = $this->has($categories, 'Server-side');

        if ($client && !$server) {
            $notes[] = ['level' => 'warn', 'text' => $name . ' ist als rein clientseitig eingeordnet. Auf dem Server bringt es nichts.'];
        }

        return $notes;
    }

    /**
     * Pruefung fuer einen ganzen Installationsplan.
     *
     * @param  array<int,array<string,mixed>>  $packages
     * @param  array<string,mixed>  $profile
     * @return array{stop:array<int,string>,warn:array<int,string>,good:array<int,string>}
     */
    public function checkAll(array $packages, array $profile = [], ?int $gameBuildAt = null): array
    {
        $out = ['stop' => [], 'warn' => [], 'good' => []];

        foreach ($packages as $package) {
            foreach ($this->check($package, $profile, $gameBuildAt) as $note) {
                $out[$note['level']][] = $note['text'];
            }
        }

        return $out;
    }

    /**
     * Kategorien des Pakets, zusammengetragen aus allen Quellen, die laut
     * Profil etwas dazu wissen.
     *
     * Eine Quelle, die gerade nicht erreichbar ist, faellt still weg. Ein
     * fehlender Hinweis ist kein Grund, eine Installation abzubrechen - und die
     * Alternative waere eine Warnung, die nur bedeutet, dass eine Website
     * langsam war.
     *
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $signals
     * @return array<int,string>
     */
    private function categories(array $package, array $profile, array $signals): array
    {
        $categories = (array) ($package['categories'] ?? []);
        $sources = (array) ($profile['sources'] ?? []);

        $wanted = [];
        $tag = (array) ($signals['current_tag'] ?? []);
        if (trim((string) ($tag['source'] ?? '')) !== '') {
            $wanted[] = (string) $tag['source'];
        }
        if (trim((string) ($signals['sides'] ?? '')) !== '') {
            $wanted[] = (string) $signals['sides'];
        }

        foreach (array_unique($wanted) as $source) {
            $base = (string) ($sources[$source] ?? '');
            if ($base === '') {
                continue;
            }
            // Die Quelle, aus der das Paket schon stammt, bringt ihre
            // Kategorien mit - kein zweiter Aufruf noetig.
            if (($package['source'] ?? '') === $source) {
                continue;
            }

            foreach ($this->remoteCategories($base, (string) $package['namespace'], (string) $package['name']) as $c) {
                $categories[] = $c;
            }
        }

        return array_values(array_unique($categories));
    }

    /** @return array<int,string> */
    private function remoteCategories(string $base, string $namespace, string $name): array
    {
        $key = $base . '|' . strtolower($namespace . '-' . $name);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $url = rtrim($base, '/') . '/api/experimental/package/'
                . rawurlencode($namespace) . '/' . rawurlencode($name) . '/';
            $response = Http::timeout(8)->withHeaders(['Accept' => 'application/json'])->get($url);

            if (!$response->successful()) {
                return $this->cache[$key] = [];
            }

            $out = [];
            foreach ((array) ($response->json()['community_listings'] ?? []) as $listing) {
                foreach ((array) ($listing['categories'] ?? []) as $category) {
                    $out[] = (string) $category;
                }
            }

            return $this->cache[$key] = array_values(array_unique($out));
        } catch (\Throwable $e) {
            Log::info('mod-auto-restart: Zweitmeinung nicht abrufbar', [
                'package' => $namespace . '-' . $name,
                'error' => $e->getMessage(),
            ]);

            return $this->cache[$key] = [];
        }
    }

    /** @param array<int,string> $categories */
    private function has(array $categories, string $needle): bool
    {
        foreach ($categories as $category) {
            if (strcasecmp(trim((string) $category), $needle) === 0) {
                return true;
            }
        }

        return false;
    }
}
