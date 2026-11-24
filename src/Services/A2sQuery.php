<?php

namespace Meigrafd\ModAutoRestart\Services;

/**
 * Spielerzahl ueber die Steam-Serverabfrage (A2S_INFO) auf dem Query-Port.
 *
 * Dieselbe Abfrage, mit der die Steam-Serverliste einen Server anzeigt. Sie
 * braucht kein Mod, kein Passwort und keinen offenen Konsolenkanal - genau das,
 * was Spielen wie Enshrouded fehlt, die weder RCON noch Befehle auf stdin
 * kennen.
 *
 * Seit Ende 2020 verlangen viele Server zuerst eine Challenge: die erste
 * Antwort ist dann 'A' mit vier Bytes, und die Anfrage muss mit diesen vier
 * Bytes am Ende wiederholt werden. Beides wird hier abgedeckt.
 *
 * Wie ueberall im Plugin: jeder Fehlschlag ist null, und null heisst fuer die
 * Aufrufer "unbekannt, nimm an, es ist jemand da". Eine UDP-Abfrage, die im
 * Netz verloren geht, darf nie wie ein leerer Server aussehen.
 *
 * Protokoll: https://developer.valvesoftware.com/wiki/Server_queries
 */
class A2sQuery
{
    private const HEADER = "\xFF\xFF\xFF\xFF";

    private const REQUEST = "\x54Source Engine Query\x00";

    /** Wie oft eine Challenge hoechstens beantwortet wird, bevor aufgegeben wird. */
    private const MAX_ROUNDS = 3;

    /** Spieler auf dem Server, oder null wenn nicht zu ermitteln. */
    public function players(string $host, int $port, float $timeout = 2.0): ?int
    {
        $info = $this->info($host, $port, $timeout);

        return $info === null ? null : $info['players'];
    }

    /**
     * @return array{name:string,players:int,max:int,bots:int}|null
     */
    public function info(string $host, int $port, float $timeout = 2.0): ?array
    {
        $host = trim($host);
        if ($host === '' || $port <= 0 || $port > 65535) {
            return null;
        }

        // IPv6-Adressen muessen in eckige Klammern, sonst liest PHP den
        // letzten Abschnitt der Adresse als Port.
        $target = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;

        $socket = @stream_socket_client('udp://' . $target . ':' . $port, $errno, $error, $timeout);
        if ($socket === false) {
            return null;
        }

        try {
            $seconds = (int) floor($timeout);
            stream_set_timeout($socket, $seconds, (int) (($timeout - $seconds) * 1_000_000));

            $request = self::HEADER . self::REQUEST;
            for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                if (@fwrite($socket, $request) === false) {
                    return null;
                }

                $reply = @fread($socket, 1400);
                // false: Port zu (ICMP), '': Zeitlimit. Beides "unbekannt".
                if (!is_string($reply) || strlen($reply) < 5 || !str_starts_with($reply, self::HEADER)) {
                    return null;
                }

                $type = $reply[4];
                if ($type === "\x41") {
                    // Challenge: dieselbe Anfrage, die vier Bytes hinten dran.
                    if (strlen($reply) < 9) {
                        return null;
                    }
                    $request = self::HEADER . self::REQUEST . substr($reply, 5, 4);

                    continue;
                }

                return $type === "\x49" ? $this->parse($reply) : null;
            }

            return null;
        } finally {
            fclose($socket);
        }
    }

    /**
     * Antwort 'I' zerlegen. Oeffentlich, damit ein Test die Zerlegung ohne
     * Netz pruefen kann.
     *
     * Aufbau: Kopf (4) Typ (1) Protokoll (1) Name\0 Karte\0 Ordner\0 Spiel\0
     * App-Id (2) Spieler (1) Maximum (1) Bots (1) ...
     *
     * @return array{name:string,players:int,max:int,bots:int}|null
     */
    public function parse(string $reply): ?array
    {
        if (strlen($reply) < 6 || !str_starts_with($reply, self::HEADER) || $reply[4] !== "\x49") {
            return null;
        }

        $pos = 6;
        $strings = [];
        for ($i = 0; $i < 4; $i++) {
            $end = strpos($reply, "\0", $pos);
            if ($end === false) {
                return null;
            }
            $strings[] = substr($reply, $pos, $end - $pos);
            $pos = $end + 1;
        }

        if (strlen($reply) < $pos + 5) {
            return null;
        }

        // Die Spielerzahl wird so genommen, wie der Server sie meldet - ohne
        // die Bots abzuziehen. Manche Spiele zaehlen Bots mit, manche nicht;
        // abziehen koennte also einen echten Spieler wegrechnen, und dann
        // startete der Server unter ihm neu. Zu viele zu zaehlen kostet
        // hoechstens etwas Wartezeit.
        return [
            'name' => $strings[0],
            'players' => ord($reply[$pos + 2]),
            'max' => ord($reply[$pos + 3]),
            'bots' => ord($reply[$pos + 4]),
        ];
    }
}
