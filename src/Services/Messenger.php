<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Log;

/**
 * Sagt den Spielern etwas - auf dem Weg, den das Spielprofil vorgibt.
 *
 * Drei Wege, weil es drei wirklich verschiedene Faelle gibt:
 *
 *   rcon     Source-RCON gegen ein Mod im Server (Valheim: ValheimRcon).
 *   console  Ueber die Panel-Konsole, also stdin des Servers. Kostet keinen
 *            Port und kein Passwort, setzt aber voraus, dass das Spiel einen
 *            Broadcast-Befehl auf stdin hat. Valheim hat keinen, Project
 *            Zomboid hat servermsg.
 *   none     Keine Vorwarnung moeglich. Der Neustart laeuft trotzdem.
 *
 * Nichts hier ist je toedlich. Faellt der Ansageweg aus - und bei 'rcon' faellt
 * er aus, sobald ein Spiel-Update das Mod zerschiesst -, gehen die Warnungen
 * verloren und der Neustart findet statt. Andersherum blockierte ein kaputtes
 * Mod ausgerechnet den Neustart, der es reparieren wuerde.
 */
class Messenger
{
    public function __construct(private RconClient $rcon) {}

    /**
     * Nachricht an alle. Gibt zurueck, ob sie zugestellt werden konnte.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $auto
     */
    public function broadcast(Server $server, array $profile, array $auto, string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $messaging = (array) ($profile['messaging'] ?? []);

        return match ((string) ($messaging['via'] ?? 'none')) {
            'rcon' => $this->viaRcon($server, $profile, $auto, $messaging, $text),
            'console' => $this->viaConsole($server, $messaging, $text),
            default => false,
        };
    }

    /**
     * Welt schreiben lassen, bevor das Backup startet.
     *
     * Ohne das haelt der Schnappschuss, was gerade im Speicher lag, statt eines
     * gesicherten Spielstands.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $auto
     */
    public function save(Server $server, array $profile, array $auto): void
    {
        $messaging = (array) ($profile['messaging'] ?? []);
        $command = trim((string) ($messaging['save'] ?? ''));
        if ($command === '') {
            return;
        }

        match ((string) ($messaging['via'] ?? 'none')) {
            'rcon' => $this->rcon->send($this->rcon->configFor($server, $profile, $auto), $command),
            'console' => $this->console($server, $command),
            default => null,
        };
    }

    /**
     * Spielerzahl, oder null wenn sie nicht zu ermitteln ist.
     *
     * null heisst fuer die Aufrufer ausdruecklich "nimm an, es ist jemand da".
     * Eine Ansage auf einem leeren Server kostet nichts; ein Neustart ohne
     * Warnung, weil eine Abfrage scheiterte, kostet einen Spieler seinen
     * Fortschritt.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $auto
     */
    public function players(Server $server, array $profile, array $auto): ?int
    {
        $messaging = (array) ($profile['messaging'] ?? []);
        if (($messaging['via'] ?? 'none') !== 'rcon' || trim((string) ($messaging['stats'] ?? '')) === '') {
            return null;
        }

        return $this->rcon->players(
            $this->rcon->configFor($server, $profile, $auto),
            (string) $messaging['stats']
        );
    }

    /** Ist ueberhaupt ein Ansageweg eingerichtet? Nur fuer die Anzeige. */
    public function available(array $profile, array $auto): bool
    {
        $messaging = (array) ($profile['messaging'] ?? []);

        return match ((string) ($messaging['via'] ?? 'none')) {
            'rcon' => trim((string) ($auto['rcon_password'] ?? '')) !== '',
            'console' => trim((string) ($messaging['broadcast'] ?? '')) !== '',
            default => false,
        };
    }

    // ------------------------------------------------------------------ wege

    private function viaRcon(Server $server, array $profile, array $auto, array $messaging, string $text): bool
    {
        $config = $this->rcon->configFor($server, $profile, $auto);
        $clean = $this->clean($text);

        $broadcast = trim((string) ($messaging['broadcast'] ?? ''));
        $chat = trim((string) ($messaging['chat'] ?? ''));

        // Wo die Ansage erscheint, entscheidet der Operator: Bildschirm,
        // Chat oder beides. Der Chat-Befehl bei Valheim zeigt sich als Zeile
        // im Chat UND als Einblendung oben - beides haengt am selben Befehl.
        $via = (string) ($auto['announce_via'] ?? 'both');
        if (!in_array($via, StateStore::ANNOUNCE_VIA, true)) {
            $via = 'both';
        }
        $useScreen = $via !== 'chat' && $broadcast !== '';
        $useChat = $via !== 'screen' && $chat !== '';
        if (!$useScreen && !$useChat) {
            // Die Wahl passt nicht zum Profil (etwa "nur Chat" ohne
            // Chat-Befehl): lieber der Bildschirm als gar keine Warnung.
            if ($broadcast === '') {
                return false;
            }
            $useScreen = true;
        }

        $shown = false;
        if ($useScreen) {
            $shown = $this->rcon->send($config, strtr($broadcast, [':text' => $clean])) !== null;
        }
        if ($useChat) {
            $said = $this->rcon->send($config, strtr($chat, [':text' => $clean])) !== null;
            if (!$useScreen) {
                $shown = $said;
            }
        }

        return $shown;
    }

    private function viaConsole(Server $server, array $messaging, string $text): bool
    {
        $broadcast = trim((string) ($messaging['broadcast'] ?? ''));
        if ($broadcast === '') {
            return false;
        }

        return $this->console($server, strtr($broadcast, [':text' => $this->clean($text)]));
    }

    private function console(Server $server, string $command): bool
    {
        try {
            $server->send($command);

            return true;
        } catch (\Throwable $e) {
            Log::info('mod-auto-restart: Konsolenbefehl fehlgeschlagen', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Anfuehrungszeichen und Zeilenumbrueche raus.
     *
     * Der Text landet in einem Befehl, dessen Zitierung das Spiel bestimmt.
     * Ein Zeilenumbruch auf stdin ist dort das Befehlsende - eine Nachricht mit
     * Umbruch wuerde also die zweite Haelfte als eigenen Befehl ausfuehren.
     */
    private function clean(string $text): string
    {
        return trim(str_replace(['"', "\n", "\r"], ["'", ' ', ' '], $text));
    }
}
