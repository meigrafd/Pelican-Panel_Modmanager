<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Beantwortet eine Frage: ist der installierte Server aelter als der Build, den
 * Steam gerade ausliefert?
 *
 * Die installierte Seite ist exakt. SteamCMD legt neben den Spieldateien eine
 * `appmanifest_<appid>.acf` mit der zuletzt geladenen `buildid` ab, und die ist
 * ueber dieselbe Wings-Dateischnittstelle lesbar wie alles andere hier.
 *
 * Die oeffentliche Seite hat keine offizielle Quelle. Valve gibt die aktuelle
 * buildid einer App ueber die Web-API nicht heraus, ohne dass man sich als
 * Besitzer anmeldet. Deshalb wird api.steamcmd.net gefragt, ein
 * Gemeinschaftsspiegel von `app_info_print`. Das ist ein Dritter - und genau
 * deshalb liefert hier jeder Fehlschlag null und gilt als "keine Information",
 * nicht als "kein Update": ein Server darf nie auf das Wort einer
 * Schnittstelle hin neu gestartet werden, die vielleicht einfach ausgefallen
 * ist.
 *
 * Welche App ueberhaupt gemeint ist, entscheidet das Spielprofil. Diese Klasse
 * kennt kein Spiel.
 */
class GameBuild
{
    private const INFO = 'https://api.steamcmd.net/v1/info/';

    public function __construct(private DaemonFileRepository $files) {}

    /** Steams "Update noetig"-Bit in StateFlags. Gesetzt heisst: das letzte Update ist nicht durchgelaufen. */
    public const FLAG_UPDATE_REQUIRED = 2;

    /** Steams "vollstaendig installiert"-Bit in StateFlags. */
    public const FLAG_FULLY_INSTALLED = 4;

    /** Build-Id auf der Platte, oder null wenn das Manifest fehlt oder unlesbar ist. */
    public function installed(Server $server, ?string $appId): ?int
    {
        return $this->manifest($server, $appId)['build'] ?? null;
    }

    /**
     * Build-Id und Zustand aus dem appmanifest, oder null.
     *
     * StateFlags ist Steams eigene Zustandsangabe. Fuer dieses Plugin zaehlen
     * zwei Bits: 4 (vollstaendig installiert) und 2 (Update noetig). Steht
     * nach einem Start 6 drin, hat SteamCMD das Update erkannt und nicht
     * geschafft - das ist genau die Zeile "state is 0x6 after update job" in
     * der Konsole. SteamCMD liest diesen Zustand beim naechsten Start wieder
     * und bricht erneut ab, weshalb ein weiterer Neustart allein nie hilft.
     *
     * @return array{build:int,flags:?int}|null
     */
    public function manifest(Server $server, ?string $appId): ?array
    {
        if ($appId === null || !ctype_digit($appId)) {
            return null;
        }

        try {
            $raw = (string) $this->files->setServer($server)
                ->getContent("/steamapps/appmanifest_{$appId}.acf", 500_000);
        } catch (\Throwable $e) {
            return null;
        }

        // Das acf-Format ist Valves eigener Schluessel-Wert-Text:
        // "buildid" "24574884"
        if (!preg_match('/"buildid"\s+"(\d+)"/', $raw, $m)) {
            return null;
        }

        return [
            'build' => (int) $m[1],
            'flags' => preg_match('/"StateFlags"\s+"(\d+)"/i', $raw, $f) ? (int) $f[1] : null,
        ];
    }

    /**
     * Haengende appmanifest-Datei beiseitelegen, damit SteamCMD beim naechsten
     * Start neu prueft und laedt.
     *
     * Umbenannt, nicht geloescht: wer nachsehen will, was drinstand, findet
     * sie daneben. Der Zeitstempel im Namen verhindert, dass ein zweiter
     * Versuch an einer schon vorhandenen Sicherung scheitert. Die Welt liegt
     * woanders und wird nicht angefasst.
     */
    public function resetManifest(Server $server, ?string $appId): bool
    {
        if ($appId === null || !ctype_digit($appId)) {
            return false;
        }

        $file = "steamapps/appmanifest_{$appId}.acf";

        try {
            $this->files->setServer($server)->renameFiles(null, [[
                'from' => $file,
                'to' => $file . '.mar-' . date('Ymd-His') . '.bak',
            ]]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Build-Id auf dem oeffentlichen Zweig, oder null wenn nicht zu ermitteln.
     *
     * Ein paar Minuten zwischengespeichert, damit ein Fuenf-Minuten-Intervall
     * einen ehrenamtlich betriebenen Dienst nicht zerlegt. Fehlschlaege werden
     * NICHT zwischengespeichert - ein kurzer Ausfall soll die Pruefung nicht
     * fuer den Rest der Stunde blind machen.
     *
     * `$fresh` uebergeht den Cache. Das ist der "Jetzt pruefen"-Knopf und sonst
     * nichts: wer den drueckt, hat meistens gerade ein Update gesehen und will
     * eine Aussage ueber jetzt, nicht ueber vor zehn Minuten.
     */
    public function latest(?string $appId, bool $fresh = false): ?int
    {
        if ($appId === null || !ctype_digit($appId)) {
            return null;
        }

        $key = "mar:build:$appId";
        $cached = Cache::get($key);
        if (!$fresh && is_int($cached)) {
            return $cached;
        }

        try {
            $json = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'PelicanValheimModManager/0.1'])
                ->get(self::INFO . $appId)
                ->json();
        } catch (\Throwable $e) {
            return null;
        }

        $build = $json['data'][$appId]['depots']['branches']['public']['buildid'] ?? null;
        if (!is_numeric($build)) {
            return null;
        }

        Cache::put($key, (int) $build, now()->addMinutes(max(1, (int) config('mod-auto-restart.cache.build_minutes', 10))));

        return (int) $build;
    }

    /**
     * Wann der oeffentliche Build zuletzt geaendert wurde, als Unix-Zeit.
     *
     * Massstab fuer "dieses Mod wurde seit dem letzten Spiel-Update nicht mehr
     * angefasst". Nicht ermittelbar heisst null, und null heisst fuer den
     * Aufrufer ausdruecklich: kein Urteil faellen. Ein Altersurteil auf Basis
     * einer geratenen Zeit waere schlimmer als gar keines.
     */
    public function latestChangedAt(?string $appId): ?int
    {
        if ($appId === null || !ctype_digit($appId)) {
            return null;
        }

        $key = "mar:buildtime:$appId";
        $cached = Cache::get($key);
        if (is_int($cached)) {
            return $cached;
        }

        try {
            $json = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'PelicanModAutoRestart/0.3'])
                ->get(self::INFO . $appId)
                ->json();
        } catch (\Throwable $e) {
            return null;
        }

        $at = $json['data'][$appId]['depots']['branches']['public']['timeupdated'] ?? null;
        if (!is_numeric($at)) {
            return null;
        }

        Cache::put($key, (int) $at, now()->addMinutes(max(1, (int) config('mod-auto-restart.cache.build_minutes', 10))));

        return (int) $at;
    }

    /**
     * @return array{outdated:bool,installed:?int,latest:?int}
     *         `outdated` ist nur dann true, wenn BEIDE Zahlen bekannt sind.
     */
    public function compare(Server $server, ?string $appId, bool $fresh = false): array
    {
        $installed = $this->installed($server, $appId);
        $latest = $this->latest($appId, $fresh);

        return [
            'outdated' => $installed !== null && $latest !== null && $latest > $installed,
            'installed' => $installed,
            'latest' => $latest,
        ];
    }
}
