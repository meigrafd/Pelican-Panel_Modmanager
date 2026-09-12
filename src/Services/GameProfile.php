<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Server;

/**
 * Loest auf, nach welchem Spielprofil ein Server behandelt wird.
 *
 * Das ist die einzige Stelle im Plugin, die ueberhaupt weiss, dass es
 * verschiedene Spiele gibt. Alles andere bekommt ein fertiges Profil-Array und
 * fragt nie, um welches Spiel es geht.
 *
 * Reihenfolge, absichtlich so herum:
 *   1. Was der Operator auf der Seite eingestellt hat. Seine Wahl schlaegt jede
 *      Erkennung - ein eigenes Egg mit ungewoehnlichem Namen ist der Normalfall
 *      auf einem gewachsenen Panel, kein Sonderfall.
 *   2. Egg-Name gegen `egg_match` der Profile.
 *   3. 'generic'.
 *
 * Einzelne Werte aus dem Profil kann der Operator ueberschreiben (App-Id,
 * Mod-Pfad). Ueberschrieben wird nur, was er tatsaechlich eingetragen hat -
 * ein leeres Feld heisst "nimm das Profil", nicht "nimm nichts".
 */
class GameProfile
{
    /**
     * @param  array<string,mixed>  $auto  Gespeicherte Einstellungen des Servers
     * @return array<string,mixed>
     */
    public function for(Server $server, array $auto = []): array
    {
        $profiles = (array) config('mod-auto-restart.profiles', []);
        $key = (string) ($auto['profile'] ?? '');

        if ($key === '' || !isset($profiles[$key])) {
            $key = $this->detect($server) ?? 'generic';
        }

        $profile = (array) ($profiles[$key] ?? $profiles['generic'] ?? []);
        $profile['key'] = $key;
        $profile['detected'] = $this->detect($server);

        // Vom Operator gesetzte Werte gewinnen, leere nicht.
        $appId = trim((string) ($auto['app_id'] ?? ''));
        if ($appId !== '') {
            $profile['app_id'] = $appId;
        }

        $path = trim((string) ($auto['mods_path'] ?? ''));
        if ($path !== '') {
            $profile['mods_path'] = trim($path, '/');
        }

        // Die Egg-Variable hat Vorrang vor dem Profil, aber nicht vor einer
        // ausdruecklichen Eingabe. Sie steht naeher an der Wahrheit als eine
        // Liste in einer Konfigurationsdatei: sie ist die Id, mit der SteamCMD
        // tatsaechlich laedt.
        if ($appId === '') {
            $fromEgg = $this->appIdFromEgg($server);
            if ($fromEgg !== null) {
                $profile['app_id'] = $fromEgg;
            }
        }

        return $profile + [
            'label' => 'Unbekannt',
            'app_id' => null,
            'mods_path' => null,
            'layout' => 'thunderstore',
            'sources' => [],
            'page_url' => [],
            'messaging' => ['via' => 'none'],
            'notes' => [],
        ];
    }

    /** Profil-Schluessel anhand des Egg-Namens, oder null. */
    public function detect(Server $server): ?string
    {
        $egg = strtolower((string) ($server->egg->name ?? ''));
        if ($egg === '') {
            return null;
        }

        foreach ((array) config('mod-auto-restart.profiles', []) as $key => $profile) {
            foreach ((array) ($profile['egg_match'] ?? []) as $needle) {
                if ($needle !== '' && str_contains($egg, strtolower((string) $needle))) {
                    return (string) $key;
                }
            }
        }

        return null;
    }

    /** @return array<string,string> Schluessel => Beschriftung, fuer die Auswahl */
    public function options(): array
    {
        $out = [];
        foreach ((array) config('mod-auto-restart.profiles', []) as $key => $profile) {
            $out[(string) $key] = (string) ($profile['label'] ?? $key);
        }

        return $out;
    }

    /**
     * Quelle, aus der eine bestimmte Mod geprueft wird.
     *
     * Erst die Ausnahme fuer genau diese Mod, dann die globale Wahl, dann die
     * erste Quelle des Profils. Eine Ausnahme auf eine Quelle, die es im Profil
     * nicht gibt, wird ignoriert statt in einen Fehler zu laufen - sonst
     * verliert ein Profilwechsel die Einstellungen aller uebrigen Mods gleich
     * mit.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $auto
     */
    public function sourceFor(array $profile, array $auto, string $fullName): ?string
    {
        $sources = (array) ($profile['sources'] ?? []);
        if (!$sources) {
            return null;
        }

        $perMod = (array) ($auto['mod_sources'] ?? []);
        $chosen = (string) ($perMod[$fullName] ?? '');
        if ($chosen !== '' && isset($sources[$chosen])) {
            return $chosen;
        }

        $global = (string) ($auto['source'] ?? '');
        if ($global !== '' && isset($sources[$global])) {
            return $global;
        }

        return (string) array_key_first($sources);
    }

    /** Steam-App-Id aus den Egg-Variablen, oder null. */
    private function appIdFromEgg(Server $server): ?string
    {
        foreach ($server->variables as $variable) {
            if (in_array($variable->env_variable, ['SRCDS_APPID', 'STEAM_APPID', 'APP_ID'], true)) {
                $value = trim((string) ($variable->server_value ?? $variable->default_value ?? ''));
                if (ctype_digit($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
