<?php

/**
 * Ersatzteile fuer Resolver und Installer, nur fuer die Phasentests.
 *
 * Nicht in stubs.php, weil InstallTest.php die ECHTEN Klassen laedt - dort
 * waere ein Ersatz mit demselben Namen ein Fatal Error. Die Phasentests
 * brauchen nur: Welche Pakete wollte der Dienst einspielen, in welcher
 * Reihenfolge, und was passiert, wenn das schiefgeht.
 */

namespace Meigrafd\ModAutoRestart\Services;

class PackageResolver
{
    /** @var array<string,array<string,mixed>> Eingabe => fertige Antwort; ohne Eintrag: ein Plan, der genau dieses Paket installiert */
    public static array $plans = [];

    /** @var array<int,array{0:string,1:string}> [Eingabe, Quelle] je Aufruf */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$plans = [];
        self::$calls = [];
    }

    public function resolve(string $input, array $profile, string $source, array $installed = [], bool $loaderPresent = false): array
    {
        self::$calls[] = [$input, $source];

        if (isset(self::$plans[$input])) {
            return self::$plans[$input];
        }

        [$namespace, $name] = array_pad(explode('-', $input, 2), 2, '');

        return [
            'ok' => true,
            'error' => null,
            'root' => null,
            'install' => [[
                'namespace' => $namespace,
                'name' => $name,
                'full_name' => $input,
                'version' => '2.0.0',
                'download_url' => 'https://example.invalid/' . $input . '.zip',
                'source' => $source,
                'action' => 'update',
                'from' => $installed[$input]['version'] ?? null,
            ]],
            'skipped' => [],
            'warnings' => [],
        ];
    }
}

class Installer
{
    /** @var array<int,string> full_name je erfolgreicher Installation, in Reihenfolge */
    public static array $installed = [];

    /** Simuliert einen Fehlschlag beim naechsten Paket. */
    public static bool $ok = true;

    public static function reset(): void
    {
        self::$installed = [];
        self::$ok = true;
    }

    public function install($server, array $package, array $profile): array
    {
        if (!self::$ok) {
            return ['ok' => false, 'note' => $package['full_name'] . ': Download fehlgeschlagen (Test)', 'layout' => null];
        }
        self::$installed[] = (string) $package['full_name'];

        return ['ok' => true, 'note' => $package['full_name'] . ' ' . $package['version'] . ' installiert (Test)', 'layout' => 'flat'];
    }
}
