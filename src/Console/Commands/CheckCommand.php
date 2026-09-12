<?php

namespace Meigrafd\ModAutoRestart\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Meigrafd\ModAutoRestart\Services\AutoUpdateService;
use Meigrafd\ModAutoRestart\Services\GameProfile;
use Meigrafd\ModAutoRestart\Services\ModScanner;
use Meigrafd\ModAutoRestart\Services\StateStore;

/**
 * Zeigt auf der Kommandozeile, was das Plugin sieht - ohne etwas zu tun.
 *
 * Gibt es, weil die Alternative beim Einrichten ist: Einstellung aendern,
 * bis zu einer Minute auf den Scheduler warten, in die Logs schauen, raten. Das
 * hier antwortet sofort und sagt genau, welches Profil gilt, welche App-Id
 * benutzt wird, welche Mods verfolgt werden und was ein Vergleich gerade
 * ergibt.
 *
 * Der Befehl startet NIE etwas neu. Er ruft nur die Erkennung auf, und die ist
 * lesend. Deshalb kann man ihn auf einem Panel im Betrieb bedenkenlos laufen
 * lassen.
 */
class CheckCommand extends Command
{
    protected $signature = 'mar:check
                            {server? : Server-Id oder Teil des Namens. Ohne Angabe: alle}
                            {--json : Rohausgabe zum Weiterverarbeiten}';

    protected $description = 'Zeigt, was Mod Auto-Restart fuer einen Server erkennt. Startet nichts neu.';

    public function handle(
        GameProfile $profiles,
        StateStore $store,
        ModScanner $scanner,
        AutoUpdateService $service,
    ): int {
        $servers = $this->pick();

        if ($servers->isEmpty()) {
            $this->error('Kein passender Server gefunden.');

            return self::FAILURE;
        }

        if (config('mod-auto-restart.dry_run', false)) {
            $this->warn('Probelauf ist panelweit eingeschaltet: es wird nichts neu gestartet.');
            $this->newLine();
        }

        $out = [];

        foreach ($servers as $server) {
            $state = $store->read($server);
            $auto = $state['auto'];
            $profile = $profiles->for($server, $auto);

            $this->line('<options=bold>' . $server->name . '</>  (Id ' . $server->id . ')');
            $this->line('  Egg              ' . ($server->egg->name ?? '?'));
            $this->line('  Profil           ' . $profile['label'] . ' [' . $profile['key'] . ']'
                . (($auto['profile'] ?? '') !== '' ? ' (von Hand gesetzt)' : ' (erkannt)'));
            $this->line('  Steam-App        ' . ($profile['app_id'] ?? '— keine, Spiel-Pruefung faellt aus'));
            $this->line('  Mod-Ordner       ' . ($profile['mods_path'] ?? '—'));
            $this->line('  Quellen          ' . (implode(', ', array_keys((array) $profile['sources'])) ?: '—'));
            $this->line('  Ansageweg        ' . (($profile['messaging']['via'] ?? 'none')));
            $this->line('  Auto-Neustart    ' . (($auto['enabled'] ?? false) ? 'AN' : 'aus')
                . ' · AUTO_UPDATE ' . ($service->autoUpdateEnabled($server) ? '1' : 'NICHT 1'));
            $this->line('  Phase            ' . ($state['run']['phase'] ?? 'idle'));

            $index = $scanner->index($server, $profile, true);
            $this->line('  Mods             ' . $index['note']);

            // Genau der Aufruf, den auch der Scheduler macht - nur ohne alles,
            // was danach kaeme. Was hier steht, wuerde dort einen Neustart
            // ausloesen oder eben nicht.
            $found = $service->detect($server, $profile, $auto, true);

            $this->newLine();
            $this->line('  <options=bold>' . $found['note'] . '</>');
            foreach ($found['detail'] as $line) {
                $this->line('    · ' . $line);
            }

            if ($found['reason']) {
                $this->newLine();
                $this->warn('  Im Betrieb wuerde das einen Neustart ausloesen (Grund: ' . $found['reason'] . ').');
            }

            $this->newLine();

            $out[] = [
                'server' => $server->name,
                'profile' => $profile['key'],
                'app_id' => $profile['app_id'],
                'enabled' => (bool) ($auto['enabled'] ?? false),
                'found' => $found,
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,Server> */
    private function pick()
    {
        $needle = (string) $this->argument('server');
        $query = Server::query()->with(['egg', 'variables', 'allocation']);

        if ($needle === '') {
            return $query->get();
        }

        if (ctype_digit($needle)) {
            return $query->where('id', (int) $needle)->get();
        }

        return $query->get()->filter(
            fn (Server $s) => str_contains(strtolower((string) $s->name), strtolower($needle))
        )->values();
    }
}
