<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Log;

/**
 * Spricht das Source-RCON-Protokoll.
 *
 * Welche Befehle darueber gehen, steht im Spielprofil, nicht hier - diese
 * Klasse kennt nur das Protokoll. Bei Valheim ist die Gegenstelle das Mod
 * ValheimRcon, bei anderen Spielen eine eingebaute Schnittstelle oder ein
 * anderes Mod.
 *
 * Grundregel: Nichts hier ist je toedlich. Haengt die Gegenstelle an einem Mod
 * und zerschiesst ein Spiel-Update dieses Mod, gehen die Warnungen verloren -
 * der Neustart muss trotzdem stattfinden. Sonst blockierte ausgerechnet das
 * kaputte Mod den Neustart, der es reparieren wuerde.
 */
class RconClient
{
    private const TYPE_AUTH = 3;
    private const TYPE_AUTH_RESPONSE = 2;
    private const TYPE_COMMAND = 2;
    private const TYPE_RESPONSE = 0;

    private const CONNECT_TIMEOUT = 5;
    private const READ_TIMEOUT = 5;

    /**
     * Befehl absetzen. Gibt die Antwort zurueck, oder null wenn irgendetwas
     * schiefging.
     *
     * @param  array{host:string,port:int,password:string}  $config
     */
    public function send(array $config, string $command): ?string
    {
        $host = trim($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 0);
        $password = (string) ($config['password'] ?? '');

        if ($host === '' || $port <= 0 || $password === '') {
            return null;
        }

        $socket = null;

        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, self::CONNECT_TIMEOUT);
            if ($socket === false) {
                throw new \RuntimeException($errstr !== '' ? $errstr : 'Verbindung abgelehnt');
            }
            stream_set_timeout($socket, self::READ_TIMEOUT);

            $authId = random_int(1, 0x3FFFFFFF);
            $this->write($socket, $authId, self::TYPE_AUTH, $password);

            $auth = $this->read($socket);
            // Das Protokoll antwortet auf ein falsches Passwort mit der ID -1.
            // Manche Server schieben vorher ein leeres RESPONSE_VALUE davor,
            // deshalb wird bis zur Auth-Antwort weitergelesen statt blind das
            // erste Paket zu nehmen.
            while ($auth !== null && $auth['type'] !== self::TYPE_AUTH_RESPONSE) {
                $auth = $this->read($socket);
            }
            if ($auth === null || $auth['id'] === -1) {
                throw new \RuntimeException('Anmeldung abgelehnt (Passwort oder IP-Filter)');
            }

            $commandId = random_int(1, 0x3FFFFFFF);
            $this->write($socket, $commandId, self::TYPE_COMMAND, $command);

            $body = '';
            $packet = $this->read($socket);
            while ($packet !== null && $packet['type'] === self::TYPE_RESPONSE) {
                $body .= $packet['body'];
                // Eine einzelne Antwort reicht fuer alles, was dieses Plugin
                // schickt. Mehrteilige Antworten (findObjects und Konsorten)
                // interessieren hier nicht.
                if (strlen($packet['body']) < 4000) {
                    break;
                }
                $packet = $this->read($socket);
            }

            return $body;
        } catch (\Throwable $e) {
            Log::info('mod-auto-restart: RCON fehlgeschlagen', [
                'command' => explode(' ', $command)[0],
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /**
     * Spielerzahl aus der Antwort auf den Statusbefehl des Profils, oder null.
     *
     * null heisst fuer die Aufrufer ausdruecklich "nimm an, es ist jemand da".
     * Eine Ansage auf einem leeren Server kostet nichts; ein Neustart ohne
     * Warnung, weil eine Abfrage fehlschlug, kostet einen Spieler seinen
     * Fortschritt.
     *
     * Der Ausdruck ist bewusst grob: die Antwortformate unterscheiden sich je
     * Spiel und Mod. Passt er nicht, kommt null heraus - und null ist der
     * vorsichtige Fall, nicht der gefaehrliche.
     */
    public function players(array $config, string $command): ?int
    {
        $stats = $this->send($config, $command);
        if ($stats === null) {
            return null;
        }

        if (preg_match('/players?\D{0,20}?(\d+)/i', $stats, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Verbindungsdaten: erst was der Operator eingetragen hat, dann das Profil.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $auto
     */
    public function configFor(Server $server, array $profile, array $auto): array
    {
        $host = trim((string) ($auto['rcon_host'] ?? ''));
        if ($host === '') {
            // Standard ist die primaere Allocation des Servers. Damit muss
            // niemand eine IP von Hand eintragen, und ein Umzug des Servers
            // bricht die Warnungen nicht.
            $host = (string) ($server->allocation->ip ?? '127.0.0.1');
        }

        $port = (int) ($auto['rcon_port'] ?? 0);
        if ($port <= 0) {
            // Versatz zum Spielport, wie ihn das Profil angibt.
            $offset = (int) (($profile['messaging'] ?? [])['port_offset'] ?? 0);
            $port = (int) ($server->allocation->port ?? 0) + $offset;
        }

        return [
            'host' => $host,
            'port' => $port,
            'password' => (string) ($auto['rcon_password'] ?? ''),
        ];
    }

    // ------------------------------------------------------------- protokoll

    private function write($socket, int $id, int $type, string $body): void
    {
        $payload = pack('VV', $id, $type) . $body . "\x00\x00";
        $packet = pack('V', strlen($payload)) . $payload;

        if (@fwrite($socket, $packet) === false) {
            throw new \RuntimeException('Senden fehlgeschlagen');
        }
    }

    /** @return array{id:int,type:int,body:string}|null */
    private function read($socket): ?array
    {
        $header = $this->readExactly($socket, 4);
        if ($header === null) {
            return null;
        }

        $size = unpack('V', $header)[1] ?? 0;
        // Ein Laengenfeld aus dem Netz ist nichts, worauf man einen Speicher-
        // block dimensioniert. 8 ist das Minimum (zwei Ints), die Obergrenze
        // haelt ein kaputtes oder fremdes Gegenueber davon ab, den PHP-Prozess
        // umzubringen.
        if ($size < 8 || $size > 65_536) {
            throw new \RuntimeException('Unplausible Paketlaenge: ' . $size);
        }

        $payload = $this->readExactly($socket, $size);
        if ($payload === null) {
            return null;
        }

        $parts = unpack('Vid/Vtype', substr($payload, 0, 8));

        return [
            // 'V' liest vorzeichenlos, die Id im Protokoll ist aber
            // vorzeichenbehaftet - und genau -1 ist die Absage auf ein falsches
            // Passwort. Ohne diese Umrechnung kommt 4294967295 heraus, die
            // Anmeldung gilt als geglueckt, und eine leere Antwort sieht fuer
            // den Aufrufer aus wie eine erfolgreich zugestellte Warnung.
            'id' => $this->signed((int) ($parts['id'] ?? 0)),
            'type' => (int) ($parts['type'] ?? 0),
            'body' => rtrim(substr($payload, 8), "\x00"),
        ];
    }

    /** 32-Bit vorzeichenlos in vorzeichenbehaftet. */
    private function signed(int $value): int
    {
        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }

    private function readExactly($socket, int $length): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = @fread($socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('Zeitueberschreitung beim Lesen');
                }

                return null;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
