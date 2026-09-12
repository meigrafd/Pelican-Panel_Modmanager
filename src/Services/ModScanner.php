<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Liest, welche Mods im Container liegen und in welcher Version.
 *
 * Gale, r2modman und die Thunderstore-CLI legen jedes Paket in einen eigenen
 * Ordner, benannt nach "Autor-Paket", mit einer manifest.json darin:
 *
 *   BepInEx/plugins/Tristan-ValheimRcon/manifest.json
 *     { "name": "ValheimRcon", "version_number": "1.6.2", ... }
 *
 * Der Autor steht NICHT in der manifest.json, nur im Ordnernamen. Deshalb ist
 * der Ordnername die Quelle fuer den Namensraum, und ein Ordner ohne Bindestrich
 * gilt als von Hand installiert: er wird angezeigt, aber nie gegen ein
 * Repository geprueft. Eine geratene Zuordnung waere schlimmer als gar keine -
 * eine falsch zugeordnete Mod meldet ewig ein Update, das nie ankommt, und das
 * Plugin schaltet sich am Ende selbst ab.
 *
 * Pfad und Aufbau kommen aus dem Spielprofil. Der Scanner selbst kennt kein
 * Spiel.
 */
class ModScanner
{
    public function __construct(private DaemonFileRepository $files) {}

    /**
     * @param  array<string,mixed>  $profile
     * @return array{ok:bool,mods:array<int,array<string,mixed>>,note:string}
     */
    public function index(Server $server, array $profile, bool $fresh = false): array
    {
        $path = trim((string) ($profile['mods_path'] ?? ''), '/');

        if ($path === '') {
            // Profil ohne Mod-Ordner (etwa Project Zomboid, dessen Mods aus dem
            // Workshop kommen). Kein Fehler: es gibt schlicht nichts zu pruefen.
            return ['ok' => true, 'mods' => [], 'loader' => ['present' => false, 'marker' => ''],
                'note' => 'Fuer dieses Profil ist keine Mod-Ueberwachung eingerichtet.'];
        }

        $key = "mar:index:{$server->id}:" . md5($path);

        if (!$fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->scan(
            $server,
            $path,
            (string) ($profile['layout'] ?? 'thunderstore'),
            trim((string) (($profile['loader'] ?? [])['marker'] ?? ''), '/')
        );

        Cache::put($key, $result, now()->addMinutes(
            max(1, (int) config('mod-auto-restart.cache.index_minutes', 10))
        ));

        return $result;
    }

    /** @return array{ok:bool,mods:array<int,array<string,mixed>>,loader:array{present:bool,marker:string},note:string} */
    private function scan(Server $server, string $path, string $layout, string $marker): array
    {
        // Der Modlader selbst liegt nicht im Mod-Ordner, sondern daneben
        // (BepInEx/core), und wenn ihn das Egg installiert hat, gibt es
        // keine manifest.json dazu. Da ist er trotzdem - und ein Mod, das
        // ihn als Abhaengigkeit nennt, darf ihn nicht noch einmal anfordern.
        $loader = ['present' => false, 'marker' => $marker];
        if ($marker !== '') {
            try {
                $this->files->setServer($server)->getDirectory('/' . $marker);
                $loader['present'] = true;
            } catch (\Throwable $e) {
                // Nicht vorhanden oder nicht lesbar: beides heisst "nicht gefunden",
                // und darauf hin wird nichts installiert, nur angezeigt.
            }
        }

        try {
            $entries = $this->files->setServer($server)->getDirectory('/' . $path);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'mods' => [],
                'loader' => $loader,
                'note' => $path . ' nicht lesbar. Stimmt der Pfad, und wurde der Server schon einmal gestartet?',
            ];
        }

        $mods = [];

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $isDirectory = (bool) ($entry['directory'] ?? ($entry['is_file'] ?? true) === false);
            if ($name === '' || !$isDirectory) {
                continue;
            }

            $manifest = $this->manifest($server, $path . '/' . $name);
            if ($manifest === null) {
                continue;
            }

            $namespace = '';
            $package = trim((string) ($manifest['name'] ?? '')) ?: $name;

            if ($layout === 'thunderstore' && str_contains($name, '-')) {
                // Nur am ERSTEN Bindestrich trennen: Paketnamen duerfen selbst
                // Bindestriche enthalten, Autorennamen enden am ersten.
                [$namespace, $rest] = explode('-', $name, 2);
                if (trim((string) ($manifest['name'] ?? '')) === '') {
                    $package = $rest;
                }
            }

            $version = trim((string) ($manifest['version_number'] ?? ''));

            $mods[] = [
                'full_name' => $namespace !== '' ? $namespace . '-' . $package : $package,
                'namespace' => $namespace,
                'name' => $package,
                'version' => $version,
                'folder' => $name,
                // Aus der manifest.json des installierten Pakets. Braucht die
                // Seite, um vor dem Loeschen zu pruefen, ob ein anderes Mod
                // davon abhaengt.
                'dependencies' => array_values(array_filter(
                    array_map('strval', (array) ($manifest['dependencies'] ?? [])),
                    fn ($d) => $d !== ''
                )),
                // Nur verfolgbare Mods loesen je einen Neustart aus.
                'tracked' => $namespace !== '' && $version !== '',
            ];
        }

        usort($mods, fn ($a, $b) => strcasecmp($a['full_name'], $b['full_name']));

        return [
            'ok' => true,
            'mods' => $mods,
            'loader' => $loader,
            'note' => $mods ? count($mods) . ' Mods in ' . $path . ' gefunden.' : 'Keine Mods in ' . $path . '.',
        ];
    }

    /** @return array<string,mixed>|null */
    private function manifest(Server $server, string $folder): ?array
    {
        foreach (['manifest.json', 'Manifest.json'] as $file) {
            try {
                $raw = (string) $this->files->setServer($server)->getContent('/' . $folder . '/' . $file, 200_000);
            } catch (\Throwable $e) {
                continue;
            }

            // Manifeste werden gelegentlich mit BOM ausgeliefert; json_decode
            // stolpert darueber, und die Mod verschwaende stillschweigend aus
            // der Liste.
            $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? '', true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }
}
