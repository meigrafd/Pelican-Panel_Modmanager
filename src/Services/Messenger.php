<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
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
 *   none     Keine Vorwarnung im Spiel moeglich. Der Neustart laeuft trotzdem.
 *
 * Unabhaengig davon Discord: ein Webhook, den der Operator auf der Seite
 * eintraegt. Der erreicht nicht die Spieler im Spiel, wohl aber die, die
 * gerade nicht reinkommen, weil ihr Client schon das Update hat - und genau
 * die fragen sonst "ist der Server kaputt?".
 *
 * Nichts hier ist je toedlich. Faellt der Ansageweg aus - und bei 'rcon' faellt
 * er aus, sobald ein Spiel-Update das Mod zerschiesst -, gehen die Warnungen
 * verloren und der Neustart findet statt. Andersherum blockierte ein kaputtes
 * Mod ausgerechnet den Neustart, der es reparieren wuerde.
 */
class Messenger
{
    public function __construct(
        private RconClient $rcon,
        private A2sQuery $query = new A2sQuery(),
    ) {}

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
        $count = null;

        if (($messaging['via'] ?? 'none') === 'rcon' && trim((string) ($messaging['stats'] ?? '')) !== '') {
            $count = $this->rcon->players(
                $this->rcon->configFor($server, $profile, $auto),
                (string) $messaging['stats']
            );
        }

        // Rueckfall: die Steam-Serverabfrage. Nur fuer Profile, die sie
        // ausdruecklich anbieten - ein Spiel, das auf A2S nicht oder falsch
        // antwortet, soll keine erfundene Null liefern.
        if ($count === null && is_array($profile['query'] ?? null)) {
            $target = $this->queryTarget($server, $profile, $auto);
            $count = $this->query->players($target['host'], $target['port']);
        }

        return $count;
    }

    /**
     * Wohin die Serverabfrage geht.
     *
     * Adresse: was der Operator eingetragen hat, sonst die IP der Allocation.
     * 0.0.0.0 ist keine Adresse, an die man schicken kann - das heisst "alle
     * Schnittstellen", und auf dem Wings-Rechner erreicht man den Server dann
     * ueber 127.0.0.1.
     *
     * Port: Eingabe, sonst die Egg-Variable aus dem Profil (Enshrouded:
     * QUERY_PORT), sonst Spielport plus Versatz.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $auto
     * @return array{host:string,port:int}
     */
    public function queryTarget(Server $server, array $profile, array $auto): array
    {
        $query = (array) ($profile['query'] ?? []);

        $host = trim((string) ($auto['query_host'] ?? ''));
        if ($host === '') {
            $host = trim((string) ($server->allocation->ip ?? ''));
        }
        if ($host === '' || $host === '0.0.0.0' || $host === '::') {
            $host = '127.0.0.1';
        }

        $port = (int) ($auto['query_port'] ?? 0);
        if ($port <= 0) {
            $variable = trim((string) ($query['port_variable'] ?? ''));
            foreach ($variable !== '' ? (array) ($server->variables ?? []) : [] as $row) {
                if (($row->env_variable ?? '') === $variable) {
                    $value = trim((string) ($row->server_value ?? $row->default_value ?? ''));
                    if (ctype_digit($value)) {
                        $port = (int) $value;
                    }
                    break;
                }
            }
        }
        if ($port <= 0) {
            $port = (int) ($server->allocation->port ?? 0) + (int) ($query['port_offset'] ?? 0);
        }

        return ['host' => $host, 'port' => $port];
    }

    // ---------------------------------------------------------------- discord

    /** Sieht das wie ein Discord-Webhook aus? Alles andere wird nicht angefragt. */
    public static function webhookValid(string $url): bool
    {
        return (bool) preg_match('#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#', trim($url));
    }

    /**
     * Nachricht an einen Discord-Kanal. Nie toedlich, wie alles hier.
     *
     * `allowed_mentions` leer: ein "@everyone" in einer Vorlage soll nicht
     * nachts den ganzen Server anpingen, nur weil ein Mod-Autor etwas
     * hochgeladen hat.
     */
    public function discord(string $webhook, string $text): bool
    {
        $webhook = trim($webhook);
        $text = trim($text);
        if ($text === '' || !self::webhookValid($webhook)) {
            return false;
        }

        try {
            return Http::timeout(5)
                ->asJson()
                ->post($webhook, [
                    'content' => mb_substr($text, 0, 1900),
                    'allowed_mentions' => ['parse' => []],
                ])
                ->successful();
        } catch (\Throwable $e) {
            Log::info('mod-auto-restart: Discord-Nachricht fehlgeschlagen', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** Ist ueberhaupt ein Ansageweg eingerichtet? Nur fuer die Anzeige. */
    public function available(array $profile, array $auto): bool
    {
        if (self::webhookValid((string) ($auto['discord_webhook'] ?? ''))) {
            return true;
        }

        return $this->inGame($profile, $auto);
    }

    /** Kann das Plugin im Spiel selbst etwas sagen? */
    public function inGame(array $profile, array $auto): bool
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
