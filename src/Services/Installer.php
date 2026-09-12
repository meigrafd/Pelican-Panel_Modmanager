<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Log;

/**
 * Laedt ein Paket auf den Server und legt es an die richtige Stelle.
 *
 * Der Download laeuft nicht ueber das Panel. Wings kann eine URL selbst ziehen
 * (`pull`) und ein Archiv selbst entpacken (`decompressFile`) - das Paket geht
 * also direkt vom Repository auf den Spielserver. Das spart nicht nur Zeit und
 * Bandbreite, es haelt auch grosse Dateien aus dem PHP-Speicher heraus.
 *
 * Der schwierige Teil ist NICHT der Download, sondern die Frage, wohin der
 * Inhalt gehoert. Thunderstore schreibt keinen Aufbau vor, und es gibt in der
 * Praxis drei - hier an echten Paketen nachgesehen:
 *
 *   flach        ValheimRcon: ValheimRcon.dll, manifest.json, icon.png im
 *                Wurzelverzeichnis
 *                -> alles nach BepInEx/plugins/<Autor-Paket>/
 *
 *   plugins/     Jotunn: plugins/Jotunn.dll neben manifest.json
 *                -> der INHALT von plugins/ nach BepInEx/plugins/<Autor-Paket>/
 *
 *   ganzer Baum  BepInExPack_Valheim: ein Ordner mit BepInEx/, doorstop_config,
 *                winhttp.dll darin
 *                -> ins Wurzelverzeichnis des Servers, NICHT nach plugins/
 *
 * Weil der Aufbau erst nach dem Entpacken sichtbar ist, wird in einen
 * Zwischenordner entpackt, dort nachgesehen und dann verschoben. Ein Raten
 * anhand des Paketnamens waere schneller und gelegentlich falsch - und falsch
 * heisst hier: ein Mod, das nie laedt, ohne dass irgendwo ein Fehler steht.
 */
class Installer
{
    /** Zwischenlager. Beginnt mit einem Punkt, damit es im Dateimanager nicht stoert. */
    private const TEMP = '.mod-auto-restart-tmp';

    /**
     * Dateien, die aus einem flachen Paket NICHT mitkopiert werden.
     *
     * Nur die reine Dokumentation. manifest.json bleibt ausdruecklich liegen -
     * sie ist die einzige Stelle, an der die installierte Version steht, und
     * ohne sie kann die Ueberwachung dieses Mod spaeter nicht mehr pruefen.
     */
    private const JUNK = ['icon.png', 'CHANGELOG.md', 'LICENSE', 'LICENSE.txt', 'THIRD-PARTY-NOTICES.txt'];

    public function __construct(private DaemonFileRepository $files) {}

    /**
     * Aus einem entpackten Verzeichnis ablesen, um welchen Aufbau es sich
     * handelt.
     *
     * Getrennt vom Rest und oeffentlich, weil das die einzige Stelle mit echter
     * Entscheidungslogik ist - und die einzige, die sich ohne Server testen
     * laesst.
     *
     * @param  array<int,array<string,mixed>>  $entries  Ergebnis von getDirectory
     * @return array{layout:string,from:?string}
     */
    public function classify(array $entries): array
    {
        $dirs = [];
        $files = [];

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if ((bool) ($entry['directory'] ?? false)) {
                $dirs[] = $name;
            } else {
                $files[] = $name;
            }
        }

        // Reihenfolge ist hier alles. Die benannten Ordner werden ZUERST
        // geprueft, weil Jotunn genau so aussieht wie ein verschachteltes
        // Paket: ein einziger Ordner (plugins/) und drumherum nur manifest,
        // README und Symbol. Stand die Verschachtelungs-Pruefung vorn, landete
        // Jotunn eine Ebene tiefer und alles darin im falschen Ziel.
        foreach ($dirs as $dir) {
            // BepInEx/ im Paket heisst: der Inhalt gehoert ins
            // Wurzelverzeichnis des Servers, nicht unter plugins/. Betrifft
            // BepInEx selbst und alles mit Patchern oder Kerndateien.
            if (strcasecmp($dir, 'BepInEx') === 0) {
                return ['layout' => 'root', 'from' => null];
            }
        }

        foreach ($dirs as $dir) {
            // plugins/ im Paket: nur dessen Inhalt gehoert in den Mod-Ordner.
            if (strcasecmp($dir, 'plugins') === 0) {
                return ['layout' => 'plugins', 'from' => $dir];
            }
        }

        // Ein einzelner, anders benannter Ordner und sonst nichts Wesentliches:
        // Das Paket verpackt seinen Inhalt noch einmal. Eine Ebene tiefer.
        $meaningful = array_values(array_diff($files, ['manifest.json', 'README.md', 'icon.png', 'CHANGELOG.md']));
        if (count($dirs) === 1 && $meaningful === []) {
            return ['layout' => 'nested', 'from' => $dirs[0]];
        }

        // Alles andere: flach.
        return ['layout' => 'flat', 'from' => null];
    }

    /**
     * Ein Paket installieren.
     *
     * @param  array<string,mixed>  $package  aus PackageResolver
     * @param  array<string,mixed>  $profile
     * @return array{ok:bool,note:string,layout:?string}
     */
    public function install(Server $server, array $package, array $profile): array
    {
        $full = $package['namespace'] . '-' . $package['name'];
        $modsPath = trim((string) ($profile['mods_path'] ?? ''), '/');
        $url = (string) ($package['download_url'] ?? '');

        if ($url === '') {
            return ['ok' => false, 'note' => 'Kein Download-Link im Paket.', 'layout' => null];
        }
        if ($modsPath === '') {
            return ['ok' => false, 'note' => 'Fuer dieses Profil ist kein Mod-Ordner eingerichtet.', 'layout' => null];
        }

        $temp = self::TEMP . '/' . $full;
        $repo = $this->files->setServer($server);

        try {
            // Aufraeumen, falls ein frueherer Versuch abgebrochen ist. Sonst
            // mischt sich der Rest von damals unter das neue Paket.
            $this->wipe($server, $temp);
            $repo->createDirectory($full, '/' . self::TEMP);

            // foreground: warten, bis der Download durch ist. Ohne das kaeme
            // der naechste Schritt an ein Archiv, das es noch nicht gibt.
            $repo->pull($url, '/' . $temp, ['filename' => 'package.zip', 'foreground' => true]);
            $repo->decompressFile('/' . $temp, 'package.zip');
            $repo->deleteFiles('/' . $temp, ['package.zip']);

            $entries = $repo->getDirectory('/' . $temp);
            $shape = $this->classify($entries);

            // Eine Ebene tiefer, wenn das Paket sich selbst noch einmal
            // eingepackt hat. Nur einmal - wer drei Ebenen verschachtelt, hat
            // etwas anderes vor, und blindes Weitergraben landet irgendwann im
            // falschen Ordner.
            if ($shape['layout'] === 'nested') {
                $temp .= '/' . $shape['from'];
                $entries = $repo->getDirectory('/' . $temp);
                $shape = $this->classify($entries);
            }

            $target = match ($shape['layout']) {
                // Ins Wurzelverzeichnis. Bewusst ohne eigenen Unterordner:
                // BepInEx muss genau dort liegen, wo das Spiel es sucht.
                'root' => '',
                default => $modsPath . '/' . $full,
            };

            if ($shape['layout'] === 'plugins') {
                $temp .= '/' . $shape['from'];
                $entries = $repo->getDirectory('/' . $temp);
            }

            $this->moveInto($server, $temp, $target, $entries, $shape['layout']);
            $this->wipe($server, self::TEMP . '/' . $full);

            return [
                'ok' => true,
                'note' => $full . ' ' . $package['version'] . ' installiert'
                    . ($shape['layout'] === 'root' ? ' (ins Serververzeichnis)' : ' nach ' . $target) . '.',
                'layout' => $shape['layout'],
            ];
        } catch (\Throwable $e) {
            Log::warning('mod-auto-restart: Installation fehlgeschlagen', [
                'server_id' => $server->id,
                'package' => $full,
                'error' => $e->getMessage(),
            ]);

            // Zwischenordner stehen lassen waere unhoeflich, aber ein
            // Fehlschlag beim Aufraeumen darf die eigentliche Meldung nicht
            // ueberschreiben.
            try {
                $this->wipe($server, self::TEMP . '/' . $full);
            } catch (\Throwable $ignored) {
            }

            return ['ok' => false, 'note' => $full . ': ' . $e->getMessage(), 'layout' => null];
        }
    }

    /**
     * Inhalt eines Zwischenordners an sein Ziel schieben.
     *
     * @param  array<int,array<string,mixed>>  $entries
     */
    private function moveInto(Server $server, string $from, string $target, array $entries, string $layout): void
    {
        $repo = $this->files->setServer($server);

        if ($target !== '') {
            // Ein vorhandener Ordner wird ersetzt, nicht ergaenzt. Beim
            // Aktualisieren blieben sonst DLLs der alten Fassung liegen, und
            // BepInEx laedt beide - was zu Fehlern fuehrt, die aussehen, als
            // haette das Update nicht funktioniert.
            $this->wipe($server, $target);
            $repo->createDirectory(basename($target), '/' . dirname($target));
        }

        $moves = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || in_array($name, self::JUNK, true)) {
                continue;
            }
            // README bleibt bei flachen Paketen liegen: dort steht oft, welche
            // Einstellungen das Mod hat. Bei einer Installation ins
            // Serververzeichnis hat sie nichts verloren.
            if ($layout === 'root' && strcasecmp($name, 'README.md') === 0) {
                continue;
            }

            $moves[] = [
                'from' => $from . '/' . $name,
                'to' => ($target === '' ? '' : $target . '/') . $name,
            ];
        }

        if ($moves) {
            // Ein Aufruf fuer alles. Wings nimmt die Liste am Stueck, und ein
            // Aufruf je Datei waere bei BepInEx mit seinen hundert Dateien eine
            // spuerbare Wartezeit.
            $repo->renameFiles(null, $moves);
        }
    }

    /** Ordner weg, wenn er da ist. Fehlt er, ist auch gut. */
    private function wipe(Server $server, string $path): void
    {
        try {
            $this->files->setServer($server)->deleteFiles('/' . dirname($path), [basename($path)]);
        } catch (\Throwable $e) {
            // Nicht vorhanden ist der Normalfall, kein Fehler.
        }
    }

    /**
     * Ein Mod wieder entfernen.
     *
     * @return array{ok:bool,note:string}
     */
    public function remove(Server $server, string $fullName, array $profile): array
    {
        $modsPath = trim((string) ($profile['mods_path'] ?? ''), '/');
        if ($modsPath === '') {
            return ['ok' => false, 'note' => 'Kein Mod-Ordner eingerichtet.'];
        }

        try {
            $this->files->setServer($server)->deleteFiles('/' . $modsPath, [$fullName]);

            return ['ok' => true, 'note' => $fullName . ' entfernt.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'note' => $fullName . ': ' . $e->getMessage()];
        }
    }
}
