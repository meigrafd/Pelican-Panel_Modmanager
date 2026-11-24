<?php

/**
 * Welche RCON-Befehle der Messenger je Einstellung "Ansage zeigen als" schickt.
 *
 * Der RCON-Client wird durch einen Ersatz ausgetauscht, der nur mitschreibt.
 * Die Phasentests koennen das nicht pruefen, weil sie den ganzen Messenger
 * ersetzen - genau die Stelle, die hier zaehlt.
 *
 * Aufruf:  php tests/MessengerTest.php
 */

namespace App\Models {
    class Server
    {
        public int $id = 1;

        public $allocation;

        public array $variables = [];

        public function __construct(string $ip = '0.0.0.0', int $port = 15636, ?string $queryPort = '15637')
        {
            $this->allocation = (object) ['ip' => $ip, 'port' => $port];
            if ($queryPort !== null) {
                $this->variables[] = (object) ['env_variable' => 'QUERY_PORT', 'server_value' => $queryPort, 'default_value' => ''];
            }
        }

        public function send(string $command): void {}
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static function __callStatic($name, $args) {}
    }

    /** Schreibt mit, was an Discord gegangen waere. */
    class Http
    {
        /** @var array<int,array{0:string,1:array}> */
        public static array $posts = [];

        public static int $status = 204;

        public static function timeout(int $s): self
        {
            return new self();
        }

        public function asJson(): self
        {
            return $this;
        }

        public function post(string $url, array $body): object
        {
            self::$posts[] = [$url, $body];
            $status = self::$status;

            return new class($status) {
                public function __construct(private int $status) {}

                public function successful(): bool
                {
                    return $this->status >= 200 && $this->status < 300;
                }
            };
        }
    }
}

namespace Meigrafd\ModAutoRestart\Services {
    /** Schreibt mit, was gesendet wuerde; antwortet je nach $failing. */
    class RconClient
    {
        /** @var array<int,string> */
        public static array $sent = [];

        /** @var array<int,string> Befehle (erstes Wort), die scheitern sollen */
        public static array $failing = [];

        public function configFor($server, array $profile, array $auto): array
        {
            return ['host' => '127.0.0.1', 'port' => 1, 'password' => 'x'];
        }

        public function send(array $config, string $command): ?string
        {
            self::$sent[] = $command;

            return in_array(explode(' ', $command)[0], self::$failing, true) ? null : '';
        }

        public function players(array $config, string $command): ?int
        {
            return null;
        }
    }
}

namespace {
    require __DIR__ . '/../src/Services/StateStore.php';
    require __DIR__ . '/../src/Services/A2sQuery.php';
    require __DIR__ . '/../src/Services/Messenger.php';

    use App\Models\Server;
    use Illuminate\Support\Facades\Http;
    use Meigrafd\ModAutoRestart\Services\A2sQuery;
    use Meigrafd\ModAutoRestart\Services\Messenger;
    use Meigrafd\ModAutoRestart\Services\RconClient;

    /** Merkt sich, wohin gefragt wurde, und antwortet mit einer festen Zahl. */
    class FakeQuery extends A2sQuery
    {
        public static array $asked = [];

        public static ?int $answer = 0;

        public function players(string $host, int $port, float $timeout = 2.0): ?int
        {
            self::$asked[] = $host . ':' . $port;

            return self::$answer;
        }
    }

    $failed = 0;
    $passed = 0;

    function ok(string $label, bool $condition, string $detail = ''): void
    {
        global $failed, $passed;
        if ($condition) {
            $passed++;
            printf("  %-6s %s\n", 'OK', $label);
        } else {
            $failed++;
            printf("  %-6s %s%s\n", 'FEHL', $label, $detail ? '  <- ' . $detail : '');
        }
    }

    $profile = ['messaging' => ['via' => 'rcon', 'broadcast' => 'showMessage :text', 'chat' => 'say :text']];
    $screenOnly = ['messaging' => ['via' => 'rcon', 'broadcast' => 'showMessage :text']];

    function say(array $profile, array $auto, string $text = 'Hallo'): bool
    {
        RconClient::$sent = [];

        return (new Messenger(new RconClient()))->broadcast(new Server(), $profile, $auto, $text);
    }

    echo "\n=== Ansage zeigen als\n";

    RconClient::$failing = [];
    ok('both: Bildschirm und Chat', say($profile, ['announce_via' => 'both']) && RconClient::$sent === ['showMessage Hallo', 'say Hallo']);
    ok('ohne Einstellung: wie both', say($profile, []) && RconClient::$sent === ['showMessage Hallo', 'say Hallo']);
    ok('screen: nur Bildschirm', say($profile, ['announce_via' => 'screen']) && RconClient::$sent === ['showMessage Hallo']);
    ok('chat: nur Chat', say($profile, ['announce_via' => 'chat']) && RconClient::$sent === ['say Hallo']);
    ok('unbekannter Wert: wie both', say($profile, ['announce_via' => 'xyz']) && RconClient::$sent === ['showMessage Hallo', 'say Hallo']);
    ok('chat gewaehlt, Profil ohne Chat: Bildschirm statt Stille', say($screenOnly, ['announce_via' => 'chat']) && RconClient::$sent === ['showMessage Hallo']);
    ok('Profil ohne Befehle: nichts, false', !say(['messaging' => ['via' => 'rcon']], ['announce_via' => 'both']) && RconClient::$sent === []);
    ok('leerer Text: nichts, false', !say($profile, ['announce_via' => 'both'], '   ') && RconClient::$sent === []);

    echo "\n=== Was als zugestellt gilt\n";

    RconClient::$failing = ['showMessage'];
    ok('both, Bildschirm scheitert: nicht zugestellt', !say($profile, ['announce_via' => 'both']));
    ok('chat, Bildschirm scheitert: zugestellt, weil nur der Chat zaehlt', say($profile, ['announce_via' => 'chat']));
    RconClient::$failing = ['say'];
    ok('both, Chat scheitert: zugestellt, der Bildschirm zaehlt', say($profile, ['announce_via' => 'both']));
    ok('chat, Chat scheitert: nicht zugestellt', !say($profile, ['announce_via' => 'chat']));
    RconClient::$failing = [];

    echo "\n=== Text\n";

    say($profile, ['announce_via' => 'screen'], "Er sagt \"hi\"\nund geht");
    ok('Anfuehrungszeichen und Zeilenumbrueche werden entschaerft', RconClient::$sent === ["showMessage Er sagt 'hi' und geht"], implode(' | ', RconClient::$sent));

    echo "\n=== Spielerzahl: Query-Port als Rueckfall\n";

    $ens = ['messaging' => ['via' => 'none'], 'query' => ['port_variable' => 'QUERY_PORT', 'port_offset' => 0]];
    $m = new Messenger(new RconClient(), new FakeQuery());

    FakeQuery::$asked = [];
    FakeQuery::$answer = 3;
    ok('Profil mit query: Zahl kommt aus der Abfrage', $m->players(new Server(), $ens, []) === 3);
    ok('  an 127.0.0.1 statt 0.0.0.0, Port aus QUERY_PORT', FakeQuery::$asked === ['127.0.0.1:15637'], implode(',', FakeQuery::$asked));

    FakeQuery::$asked = [];
    $m->players(new Server('203.0.113.5', 15636, null), $ens, []);
    ok('ohne QUERY_PORT: echte IP, Spielport + Versatz', FakeQuery::$asked === ['203.0.113.5:15636'], implode(',', FakeQuery::$asked));

    FakeQuery::$asked = [];
    $m->players(new Server(), $ens, ['query_host' => '10.0.0.2', 'query_port' => 27015]);
    ok('Eingaben auf der Seite gewinnen', FakeQuery::$asked === ['10.0.0.2:27015'], implode(',', FakeQuery::$asked));

    FakeQuery::$asked = [];
    ok('Profil ohne query: keine Abfrage, unbekannt', $m->players(new Server(), ['messaging' => ['via' => 'none']], []) === null
        && FakeQuery::$asked === []);

    FakeQuery::$answer = null;
    ok('Abfrage ohne Antwort: unbekannt, nie 0', $m->players(new Server(), $ens, []) === null);

    echo "\n=== A2S gegen einen echten UDP-Server\n";

    $q = new A2sQuery();
    $info = "\xFF\xFF\xFF\xFF\x49\x11" . "Mein Server\0" . "Embervale\0" . "enshrouded\0" . "Enshrouded\0"
        . "\x00\x00" . "\x04" . "\x10" . "\x00" . "d" . "w" . "\x00\x01" . "1.0\0";
    $parsed = $q->parse($info);
    ok('Antwort wird zerlegt: 4 von 16', ($parsed['players'] ?? null) === 4 && ($parsed['max'] ?? null) === 16
        && ($parsed['name'] ?? '') === 'Mein Server', json_encode($parsed));
    ok('abgeschnittene Antwort: null', $q->parse(substr($info, 0, 20)) === null);
    ok('falscher Typ: null', $q->parse("\xFF\xFF\xFF\xFF\x41abcd") === null);

    // Kleiner Server in einem zweiten Prozess: verlangt erst eine Challenge,
    // antwortet danach mit 2 Spielern. Genau so verhalten sich viele echte.
    $port = random_int(40000, 60000);
    $script = <<<'PHP'
$sock = stream_socket_server('udp://127.0.0.1:' . $argv[1], $e, $m, STREAM_SERVER_BIND);
fwrite(STDOUT, "ready\n");
for ($i = 0; $i < 2; $i++) {
    $req = stream_socket_recvfrom($sock, 1400, 0, $peer);
    if (strlen($req) === 25) {
        stream_socket_sendto($sock, "\xFF\xFF\xFF\xFF\x41WXYZ", 0, $peer);
    } elseif (substr($req, -4) === 'WXYZ') {
        stream_socket_sendto($sock, "\xFF\xFF\xFF\xFF\x49\x11S\0M\0F\0G\0\x00\x00\x02\x10\x00dw\x00\x01", 0, $peer);
    }
}
PHP;
    $proc = proc_open([PHP_BINARY, '-r', $script, (string) $port], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);
    $count = $q->players('127.0.0.1', $port, 2.0);
    proc_terminate($proc);
    proc_close($proc);
    ok('Challenge beantwortet, danach 2 Spieler', $count === 2, var_export($count, true));

    $started = microtime(true);
    ok('niemand lauscht: null', $q->players('127.0.0.1', $port, 1.0) === null);
    ok('  und zwar schnell (unter 2 s)', microtime(true) - $started < 2.0);
    ok('Port 0: null ohne Versuch', $q->players('127.0.0.1', 0) === null);

    echo "\n=== Discord\n";

    $hook = 'https://discord.com/api/webhooks/123456/abc-DEF_9';
    ok('gueltige Webhook-Adresse', Messenger::webhookValid($hook));
    ok('discordapp.com geht auch', Messenger::webhookValid('https://discordapp.com/api/webhooks/1/x'));
    ok('fremde Adresse wird abgelehnt', !Messenger::webhookValid('https://example.com/api/webhooks/1/x'));
    ok('http ohne s wird abgelehnt', !Messenger::webhookValid('http://discord.com/api/webhooks/1/x'));

    Http::$posts = [];
    Http::$status = 204;
    ok('Nachricht geht raus', $m->discord($hook, '@everyone Update da') === true);
    ok('  ohne Erwaehnungen (kein @everyone-Ping)', (Http::$posts[0][1]['allowed_mentions'] ?? null) === ['parse' => []]);
    ok('  Text unveraendert', (Http::$posts[0][1]['content'] ?? '') === '@everyone Update da');

    Http::$posts = [];
    ok('fremde Adresse: nichts gesendet', $m->discord('https://example.com/x', 'Hallo') === false && Http::$posts === []);
    ok('leerer Text: nichts gesendet', $m->discord($hook, '  ') === false && Http::$posts === []);

    Http::$status = 404;
    ok('Discord lehnt ab: false', $m->discord($hook, 'Hallo') === false);
    Http::$status = 204;

    ok('Webhook zaehlt als Ansageweg', $m->available(['messaging' => ['via' => 'none']], ['discord_webhook' => $hook]));
    ok('ohne Webhook und ohne Weg im Spiel: keiner', !$m->available(['messaging' => ['via' => 'none']], []));

    echo "\n";
    printf("ERGEBNIS: %d bestanden, %d fehlgeschlagen\n", $passed, $failed);
    exit($failed > 0 ? 1 : 0);
}
