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

        public function send(string $command): void {}
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static function __callStatic($name, $args) {}
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
    require __DIR__ . '/../src/Services/Messenger.php';

    use App\Models\Server;
    use Meigrafd\ModAutoRestart\Services\Messenger;
    use Meigrafd\ModAutoRestart\Services\RconClient;

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

    echo "\n";
    printf("ERGEBNIS: %d bestanden, %d fehlgeschlagen\n", $passed, $failed);
    exit($failed > 0 ? 1 : 0);
}
