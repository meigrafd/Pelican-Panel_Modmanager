<?php

namespace Meigrafd\ModAutoRestart\Services;

use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Services\Backups\InitiateBackupService;
use Illuminate\Support\Facades\Log;

/**
 * Startet einen Server von selbst neu, wenn ein Mod-Repository eine neuere
 * Version einer installierten Mod fuehrt oder Steam einen neueren Server-Build.
 *
 * Das Problem ist eng umrissen. Bei fast allen moddbaren Spielen muessen Client
 * und Server dieselbe Version haben. Aktualisiert sich eine Mod oder das Spiel
 * waehrend der Server laeuft, haelt der Server die alten Dateien und jeder
 * Client die neuen: wer schon drin ist, merkt nichts, wer neu verbinden will,
 * kommt nicht rein. Der Server sieht dabei kerngesund aus. Nur ein Neustart
 * loest das.
 *
 * Alles hier ist um eine einzige Sorge herum gebaut: Ein Plugin, das Server nach
 * einer Uhr neu startet, ist eine falsche Annahme davon entfernt, einen vollen
 * Server die ganze Nacht alle fuenf Minuten neu zu starten. Die Sicherungen
 * unten sind keine Zierde.
 *
 * **Ohne AUTO_UPDATE geht es nicht.** Die SteamCMD-Images laden beim Start nur
 * dann neu, wenn die Egg-Variable AUTO_UPDATE auf 1 steht. Ohne sie laedt ein
 * Neustart nichts, das Update steht danach immer noch aus, und das Plugin
 * startet erneut neu. Endlos.
 *
 * **Ein Versuch, dann Schluss.** Kam das Update nach dem Neustart nicht an,
 * schaltet sich die Funktion ab und sagt warum. Ein Server, der einen Menschen
 * braucht, ist besser als ein Server in der Neustart-Schleife.
 *
 * **Aus einem Fehlschlag wird nie etwas gefolgert.** Repository nicht
 * erreichbar, Mod-Ordner nicht lesbar, Spielerzahl unbekannt: das heisst jedes
 * Mal "keine Information", und auf keine Information hin wird nichts neu
 * gestartet.
 *
 * **Der Ansageweg darf ausfallen.** Warnungen gehen je nach Spiel ueber ein
 * RCON-Mod oder die Konsole. Faellt das aus, gehen die Warnungen verloren und
 * der Neustart laeuft trotzdem - sonst blockierte ein kaputtes Mod ausgerechnet
 * den Neustart, der es repariert.
 *
 * Was am jeweiligen Spiel anders ist, steht im Spielprofil. Diese Klasse kennt
 * kein Spiel beim Namen.
 *
 * Phasen, getrieben vom Panel-Scheduler, ein Tick pro Minute:
 *
 *   idle      nichts zu tun; prueft alle `check_minutes`
 *   warning   Neustart geplant; Spieler werden informiert; Backup laeuft
 *   verifying neu gestartet, wartet auf Rueckkehr zur Kontrolle
 *   failed    Kontrolle fehlgeschlagen; Funktion ist aus und braucht einen Menschen
 */
class AutoUpdateService
{
    /**
     * Wie lange nach einem Neustart die Kontrolle ueberhaupt erst hinsieht.
     *
     * Ein Server braucht: SteamCMD, dann den Modlader, dann die Welt. Zu frueh
     * gefragt, ist noch nichts geschrieben und die Kontrolle "beweist" einen
     * Fehlschlag, den es nicht gibt.
     */
    private const VERIFY_GRACE_MINUTES = 5;

    /** Aufgeben und als Fehlschlag werten. */
    private const VERIFY_DEADLINE_MINUTES = 30;

    /** Laengste Wartezeit des Neustart-Knopfs auf sein Backup. */
    private const MANUAL_BACKUP_WAIT_SECONDS = 12;

    /** Rest fuer die Anfrage: Neustart absetzen und rendern. */
    private const REQUEST_HEADROOM_SECONDS = 15;

    /**
     * Was ein geplanter Neustart von Hand als ":reason" in die Ansagen
     * einsetzt ("... fuer ein Wartungs-Update"). Enthaelt absichtlich nicht
     * "Spiel": daran erkennt die Kontrolle ein Spiel-Update.
     */
    private const MANUAL_REASON = 'Wartungs';

    public function __construct(
        private GameProfile $profiles,
        private ModScanner $scanner,
        private RegistryClient $registry,
        private Messenger $messenger,
        private StateStore $store,
        private GameBuild $build,
        private PowerService $power,
    ) {}

    // ------------------------------------------------------------------ start

    /**
     * Einmal pro Minute vom Panel-Scheduler aufgerufen.
     *
     * Zwei Ebenen Fehlerfang, und beide werden gebraucht. Innen, damit ein
     * kaputter Server die anderen nicht aufhaelt. Aussen, weil schon das
     * Zusammenstellen der Serverliste scheitern kann - und eine Ausnahme, die
     * bis in den Scheduler durchschlaegt, ist ein Plugin, das den geplanten
     * Lauf des Panels mit sich reisst. Ein Plugin darf hoechstens sich selbst
     * kaputtmachen.
     */
    public function tick(): void
    {
        try {
            $servers = $this->servers();
        } catch (\Throwable $e) {
            Log::error('mod-auto-restart: Serverliste nicht lesbar, Tick uebersprungen', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($servers as $server) {
            try {
                $this->tickServer($server);
            } catch (\Throwable $e) {
                // Ein kaputter Server darf die anderen nicht aufhalten.
                Log::error('mod-auto-restart: Tick fehlgeschlagen', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Ein Server. Oeffentlich, damit ein Test oder ein Operator direkt treiben kann. */
    public function tickServer(Server $server): void
    {
        $state = $this->store->read($server);

        // Ein von Hand geplanter Neustart laeuft auch bei ausgeschaltetem
        // Auto-Neustart durch: der Operator hat ihn ausdruecklich angestossen,
        // und ein Ablauf, der nach der Warnung einfach stehen bliebe, waere
        // schlimmer als gar keiner.
        $manual = ($state['run']['trigger'] ?? '') === 'manual'
            && in_array($state['run']['phase'] ?? '', ['warning', 'verifying'], true);
        if (!($state['auto']['enabled'] ?? false) && !$manual) {
            return;
        }

        $profile = $this->profiles->for($server, $state['auto']);

        match ($state['run']['phase'] ?? 'idle') {
            'warning' => $this->duringWarning($server, $profile, $state),
            'verifying' => $this->duringVerify($server, $profile, $state),
            // 'failed' ist absichtlich endgueltig: der letzte Neustart hat
            // nichts repariert, ein weiterer wuerde nur wieder Spieler werfen.
            'failed' => null,
            default => $this->whenIdle($server, $profile, $state),
        };
    }

    // ----------------------------------------------------------------- phasen

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $state
     */
    private function whenIdle(Server $server, array $profile, array $state): void
    {
        $auto = $state['auto'];
        $run = $state['run'];
        $now = now()->timestamp;

        // Zwei Uhren: wie lange der letzte Neustart her ist, und wie lange die
        // letzte Pruefung. Die erste verhindert, dass aus einer Welle von
        // Mod-Updates eine Welle von Neustarts wird; die zweite ist nur das
        // Abfrageintervall.
        $cooldownUntil = (int) ($run['last_restart_at'] ?? 0) + $auto['cooldown_minutes'] * 60;
        if ($now < $cooldownUntil || $now < (int) ($run['next_check_at'] ?? 0)) {
            return;
        }

        $run['next_check_at'] = $now + max(60, $auto['check_minutes'] * 60);
        $run['checked_at'] = $now;

        $found = $this->detect($server, $profile, $auto);
        $run['note'] = $found['note'];
        // Wird mitgefuehrt, damit die Seite den Status danach einfaerben kann.
        // Eine Pruefung, die ein Repository nicht erreicht hat, darf nicht im
        // selben Gruen mit demselben Haken erscheinen wie eine, die alles
        // verglichen und nichts gefunden hat.
        $run['degraded'] = $found['degraded'];

        if (!$found['reason']) {
            $this->save($server, $state, $run);

            return;
        }

        // Was waehrend des Warnfensters noch dazukommt, faehrt beim selben
        // Neustart mit. Ein Mod-Autor, der fuenf Versionen hintereinander
        // veroeffentlicht, kostet so einen Neustart statt fuenf. Genau deshalb
        // gibt es keine eigene Sammelphase: das Warnfenster ist die Sammelphase.
        $players = $this->messenger->players($server, $profile, $auto);
        // null heisst "unbekannt", und unbekannt wird wie "es ist jemand da"
        // behandelt.
        $warnMinutes = $players === 0 ? 0 : $auto['warn_minutes'];

        $run = [
            'phase' => 'warning',
            'reason' => $found['reason'],
            'detail' => $found['detail'],
            'restart_at' => $now + $warnMinutes * 60,
            'started_at' => $now,
            'announced' => [],
            'players_at_start' => $players,
            'stale_ids' => $found['ids'],
            // Die "von"-Seite der Historie, jetzt festgehalten. Nach dem
            // Neustart sind die alten Versionsnummern von der Platte weg.
            'stale_before' => $found['stale'] ?? [],
            'build_before' => $found['build'],
            'last_restart_at' => $run['last_restart_at'] ?? 0,
            'note' => $found['note'],
            'warned' => false,
        ];

        // Welt schreiben lassen, bevor das Backup startet - sonst haelt der
        // Schnappschuss, was gerade im Speicher lag.
        if ($auto['backup']) {
            $this->messenger->save($server, $profile, $auto);
            $run['backup_id'] = $this->startBackup($server, 'Auto-Update ' . $found['reason']);
        }

        if ($warnMinutes > 0) {
            $run['warned'] = $this->announce($server, $profile, $auto, $auto['msg_warn'], $found['reason'], $warnMinutes, $warnMinutes * 60);
            $run['announced'][] = $warnMinutes;
        }

        $this->save($server, $state, $run);
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $state
     */
    private function duringWarning(Server $server, array $profile, array $state): void
    {
        $auto = $state['auto'];
        $run = $state['run'];
        $now = now()->timestamp;
        $left = (int) $run['restart_at'] - $now;

        if ($left > 30) {
            // Eine zweite Warnung kurz vor Schluss. Wer nach der ersten Ansage
            // dazugekommen ist, haette sonst gar keine bekommen.
            $minutes = (int) ceil($left / 60);
            if ($minutes <= 1 && !in_array(1, $run['announced'] ?? [], true)) {
                $warned = $this->announce($server, $profile, $auto, $auto['msg_final'], (string) ($run['reason'] ?? ''), 1, 60);
                $run['warned'] = ($run['warned'] ?? false) || $warned;
                $run['announced'][] = 1;
                $this->save($server, $state, $run);
            }

            return;
        }

        // Die Zeit ist um, das Backup vielleicht nicht. Das Warten ist begrenzt:
        // ein langsames Backup darf einen Neustart verzoegern, nicht verhindern.
        if ($auto['backup'] && isset($run['backup_id'])) {
            $waited = $now - (int) $run['started_at'];
            if (!$this->backupSettled((int) $run['backup_id']) && $waited < $auto['backup_wait_seconds']) {
                $run['restart_at'] = $now + 60;
                $run['note'] = 'Wartet auf das Backup, bevor neu gestartet wird.';
                $this->save($server, $state, $run);

                return;
            }
        }

        // Probelauf: alles tun, nur nicht neu starten. Gedacht fuer den ersten
        // Tag auf einem echten Panel - die Erkennung laeuft vollstaendig, die
        // Historie fuellt sich, und man sieht an echten Daten, ob das Plugin
        // das Richtige tun WUERDE, bevor man ihm erlaubt, es zu tun.
        if (config('mod-auto-restart.dry_run', false)) {
            Log::info('mod-auto-restart: Probelauf, Neustart unterdrueckt', [
                'server_id' => $server->id,
                'reason' => $run['reason'] ?? '',
            ]);

            $state['history'] = $this->store->remember($state['history'] ?? [], [
                'at' => $now,
                'trigger' => (string) ($run['trigger'] ?? 'auto'),
                'by' => $run['by'] ?? null,
                'reason' => (string) ($run['reason'] ?? ''),
                'changes' => $this->changesBefore($run),
                'players' => $run['players_at_start'] ?? null,
                'warned' => (bool) ($run['warned'] ?? false),
                'outcome' => 'dry-run',
                'note' => 'Probelauf: hier waere neu gestartet worden.',
            ]);

            // Zurueck auf idle, mit Abklingzeit. Ohne die meldete derselbe
            // Befund im naechsten Tick wieder einen Probelauf, und die Historie
            // liefe in Minuten voll.
            $this->save($server, $state, [
                'phase' => 'idle',
                'last_restart_at' => $now,
                'next_check_at' => $now + max(60, $auto['check_minutes'] * 60),
                'checked_at' => $now,
                'note' => 'Probelauf: Update erkannt (' . ($run['reason'] ?? '') . '), nicht neu gestartet.',
                'degraded' => false,
            ]);

            return;
        }

        $this->countdown($server, $profile, $auto, (string) ($run['reason'] ?? ''));

        // Wird vor dem Neustart geschrieben, nicht nach der Kontrolle. Faellt
        // das Panel dazwischen aus, sieht der Operator immerhin, dass ein
        // Neustart versucht wurde, statt einer Luecke in der Liste.
        $state['history'] = $this->store->remember($state['history'] ?? [], [
            'at' => $now,
            'trigger' => (string) ($run['trigger'] ?? 'auto'),
            'by' => $run['by'] ?? null,
            'reason' => (string) ($run['reason'] ?? ''),
            'changes' => $this->changesBefore($run),
            'players' => $run['players_at_start'] ?? null,
            'backup_id' => $run['backup_id'] ?? null,
            // Ob die Spieler ueberhaupt gewarnt werden konnten. Bei kaputtem
            // Ansageweg steht hier false, und genau das will ein Operator am
            // Morgen sehen, statt zu raten, warum sich jemand beschwert hat.
            'warned' => (bool) ($run['warned'] ?? false),
            'outcome' => 'pending',
        ]);

        $manual = ($run['trigger'] ?? 'auto') === 'manual';
        $run = [
            'phase' => 'verifying',
            'trigger' => $run['trigger'] ?? 'auto',
            'by' => $run['by'] ?? null,
            'reason' => $run['reason'],
            'detail' => $run['detail'] ?? [],
            'restarted_at' => $now,
            'verify_after' => $now + self::VERIFY_GRACE_MINUTES * 60,
            'verify_before' => $now + self::VERIFY_DEADLINE_MINUTES * 60,
            'last_restart_at' => $now,
            // Durchgereicht, nicht neu gebaut: die Kontrolle muss nach genau den
            // Mods fragen, fuer die neu gestartet wurde.
            'stale_ids' => $run['stale_ids'] ?? [],
            'stale_before' => $run['stale_before'] ?? [],
            'build_before' => $run['build_before'] ?? null,
            'note' => $manual
                ? 'Neu gestartet von Hand. Wartet auf die Rueckkehr des Servers.'
                : 'Neu gestartet fuer ein ' . $run['reason'] . '-Update. Wird kontrolliert.',
        ];
        $this->save($server, $state, $run);

        $this->power->setServer($server)->send('restart');
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $state
     */
    private function duringVerify(Server $server, array $profile, array $state): void
    {
        $run = $state['run'];
        $auto = $state['auto'];
        $now = now()->timestamp;

        if ($now < (int) $run['verify_after']) {
            return;
        }

        // Ob der Server wieder oben ist, wird an den Dateien abgelesen, nicht am
        // Ansageweg. Der haengt bei vielen Spielen an einem Mod, und wenn das
        // Spiel-Update genau dieses Mod zerschossen hat, ist "keine Antwort"
        // kein Beweis fuer einen toten Server - es ist der Normalfall nach so
        // einem Update.
        $index = $this->scanner->index($server, $profile, true);
        $installedBuild = $this->build->installed($server, $profile['app_id'] ?? null);
        $backUp = $index['ok'] || $installedBuild !== null;

        if (!$backUp) {
            if ($now < (int) $run['verify_before']) {
                return;
            }

            $this->stop($server, $state, 'Der Server ist nach dem Neustart nicht zurueckgekommen. Auto-Neustart ist aus.');

            return;
        }

        $found = $this->detect($server, $profile, $auto, true);

        // Nur die Mods, fuer die dieser Neustart war. Eine Mod, die waehrend des
        // Neustarts aktualisiert wurde, ist ein Grund fuer den naechsten
        // Durchlauf und kein Beleg, dass dieser gescheitert ist.
        $stillStale = array_key_exists('stale_ids', $run)
            ? array_values(array_intersect($found['ids'] ?? [], $run['stale_ids']))
            : ($found['ids'] ?? []);

        $gameStuck = str_contains((string) ($run['reason'] ?? ''), 'Spiel')
            && $found['build'] !== null
            && $found['build'] === ($run['build_before'] ?? null);

        if ($stillStale || $gameStuck) {
            // Nichts hat sich bewegt, ein zweiter Neustart wuerde es auch nicht.
            // Meistens: AUTO_UPDATE aus, oder das Paket wurde zurueckgezogen.
            $this->stop(
                $server,
                $state,
                'Neu gestartet, aber ' . ($gameStuck ? 'der Spiel-Build' : implode(', ', $stillStale))
                . ' ist immer noch nicht aktuell. Auto-Neustart ist aus, damit der Server nicht erneut neu startet.'
            );

            return;
        }

        $this->announce($server, $profile, $auto, $auto['msg_back'], (string) ($run['reason'] ?? ''), 0, 0);

        $state['history'] = $this->settleHistory($server, $profile, $state, $run, 'verified', '', $found);

        $this->save($server, $state, [
            'phase' => 'idle',
            'last_restart_at' => (int) $run['last_restart_at'],
            'next_check_at' => $now + max(60, $auto['check_minutes'] * 60),
            'checked_at' => $now,
            'note' => ($run['trigger'] ?? 'auto') === 'manual'
                ? 'Neustart von Hand abgeschlossen, der Server ist zurueck.'
                : 'Update eingespielt und kontrolliert: ' . $run['reason'] . '.',
            'verified_at' => $now,
        ]);
    }

    /**
     * Funktion abschalten und den Grund hinterlassen.
     *
     * @param array<string,mixed> $state
     */
    private function stop(Server $server, array $state, string $why): void
    {
        Log::warning('mod-auto-restart: Auto-Neustart hat sich selbst abgeschaltet', [
            'server_id' => $server->id,
            'reason' => $why,
        ]);

        $state['auto']['enabled'] = false;
        // Ein Neustart, der nichts gebracht hat, ist der Eintrag, den ein
        // Operator morgens am dringendsten sucht. Also abgeschlossen mit Grund,
        // statt fuer immer auf "pending" stehen zu lassen.
        $state['history'] = $this->settleHistory($server, [], $state, $state['run'] ?? [], 'failed', $why);
        $this->save($server, $state, [
            'phase' => 'failed',
            'note' => $why,
            'failed_at' => now()->timestamp,
            'last_restart_at' => $state['run']['last_restart_at'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------- historie

    /**
     * Die "von"-Haelfte eines Historieneintrags.
     *
     * @param  array<string,mixed>  $run
     * @return array<int,array<string,mixed>>
     */
    private function changesBefore(array $run): array
    {
        $changes = [];

        foreach (is_array($run['stale_before'] ?? null) ? $run['stale_before'] : [] as $row) {
            $changes[] = [
                'kind' => 'mod',
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'from' => (string) ($row['version'] ?? ''),
                'to' => (string) ($row['latest'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                // Aus welchem Repository verglichen wurde. Steht in der
                // Historie, weil dieselbe Mod aus zwei Quellen verschiedene
                // Versionsnummern haben kann - ohne diese Spalte ist ein
                // spaeterer Streit darueber nicht aufzuloesen.
                'source' => (string) ($row['source'] ?? ''),
            ];
        }

        if (str_contains((string) ($run['reason'] ?? ''), 'Spiel') && ($run['build_before'] ?? null) !== null) {
            $changes[] = [
                'kind' => 'game',
                'name' => 'Server-Build',
                'from' => (string) $run['build_before'],
            ];
        }

        return $changes;
    }

    /**
     * Die "nach"-Haelfte und das Ergebnis in den neuesten Eintrag schreiben.
     *
     * Zugeordnet ueber den Zeitpunkt des Neustarts statt ueber die Position: ein
     * manueller Neustart waehrend der Kontrolle wuerde den automatischen sonst
     * nach unten schieben und das Ergebnis in die falsche Zeile schreiben.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $state
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>  $found
     * @return array<int,array<string,mixed>>
     */
    private function settleHistory(Server $server, array $profile, array $state, array $run, string $outcome, string $note, array $found = []): array
    {
        $at = (int) ($run['restarted_at'] ?? 0);
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        $after = ($outcome === 'verified' && $profile) ? $this->snapshot($server, $profile) : [];

        foreach ($history as $i => $entry) {
            if ((int) ($entry['at'] ?? 0) !== $at || ($entry['outcome'] ?? '') !== 'pending') {
                continue;
            }

            foreach ($entry['changes'] ?? [] as $j => $change) {
                if (($change['kind'] ?? '') === 'game') {
                    $history[$i]['changes'][$j]['to'] = (string) ($found['build'] ?? '');

                    continue;
                }
                $now = $after[$change['id'] ?? ''] ?? null;
                if ($now !== null) {
                    $history[$i]['changes'][$j]['to'] = (string) $now;
                }
            }

            $history[$i]['outcome'] = $outcome;
            $history[$i]['note'] = $note;
            // Nur bei Erfolg. Der haeufigste Fehlschlag ist, dass der Server gar
            // nicht zurueckkommt - in dieser Zeile wuerde die Zahl also die
            // Wartezeit auf etwas messen, das nie passiert ist.
            $history[$i]['down'] = $outcome === 'verified' ? max(0, now()->timestamp - $at) : 0;
            break;
        }

        return $history;
    }

    /**
     * Installierte Versionen, wie sie jetzt auf der Platte stehen.
     *
     * @return array<string,string>  full_name => version
     */
    private function snapshot(Server $server, array $profile): array
    {
        try {
            $index = $this->scanner->index($server, $profile, true);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($index['mods'] ?? [] as $mod) {
            $out[(string) $mod['full_name']] = (string) $mod['version'];
        }

        return $out;
    }

    // ----------------------------------------------------------------- manuell

    /**
     * Der Neustart-Knopf auf der Seite: sichern, wenn das eingeschaltet ist,
     * neu starten, und einen Eintrag hinterlassen.
     *
     * Die Backup-Einstellung teilt sich bewusst mit dem automatischen Weg. Wer
     * "vor jedem Neustart sichern" angehakt hat, meint das unabhaengig davon,
     * wer den Knopf drueckt.
     *
     * @return array{restarted:bool,wanted_backup:bool,backup_id:?int,backup_done:bool}
     */
    /**
     * Neustart von Hand, aber mit dem vollen Ablauf.
     *
     * Setzt den Server in dieselbe Warnphase wie ein erkanntes Update; ab
     * da uebernimmt der Scheduler: Warnungen, Backup, Countdown, Neustart,
     * Kontrolle der Rueckkehr, Willkommensnachricht. Das ist der Weg fuer
     * "Server heute Abend neu starten, aber die Spieler sollen es vorher
     * wissen" - und zugleich die Generalprobe fuer den Automatikweg, ohne
     * auf ein echtes Update warten zu muessen.
     *
     * Laeuft auch bei ausgeschaltetem Auto-Neustart (siehe tickServer).
     * Nichts zu kontrollieren gibt es nicht: die Kontrolle prueft, dass der
     * Server zurueckkommt, und das ist bei einem Neustart von Hand genauso
     * die Frage.
     *
     * @return array{ok:bool,minutes:int}  ok=false: es laeuft schon einer
     */
    public function scheduleRestart(Server $server, string $by): array
    {
        $state = $this->store->read($server);
        if (in_array($state['run']['phase'] ?? 'idle', ['warning', 'verifying'], true)) {
            return ['ok' => false, 'minutes' => 0];
        }

        $auto = $state['auto'];
        $profile = $this->profiles->for($server, $auto);
        $now = now()->timestamp;

        $players = $this->messenger->players($server, $profile, $auto);
        $warnMinutes = $players === 0 ? 0 : (int) $auto['warn_minutes'];

        $run = [
            'phase' => 'warning',
            'trigger' => 'manual',
            'by' => $by,
            'reason' => self::MANUAL_REASON,
            'detail' => [],
            'restart_at' => $now + $warnMinutes * 60,
            'started_at' => $now,
            'announced' => [],
            'players_at_start' => $players,
            'stale_ids' => [],
            'stale_before' => [],
            'build_before' => null,
            'last_restart_at' => $state['run']['last_restart_at'] ?? 0,
            'checked_at' => $state['run']['checked_at'] ?? 0,
            'note' => $warnMinutes > 0
                ? 'Neustart von Hand geplant, Spieler werden gewarnt.'
                : 'Neustart von Hand geplant, laeuft mit dem naechsten Tick.',
            'warned' => false,
        ];

        if ($auto['backup']) {
            $this->messenger->save($server, $profile, $auto);
            $run['backup_id'] = $this->startBackup($server, 'Neustart von Hand');
        }

        if ($warnMinutes > 0) {
            $run['warned'] = $this->announce($server, $profile, $auto, $auto['msg_warn'], self::MANUAL_REASON, $warnMinutes, $warnMinutes * 60);
            $run['announced'][] = $warnMinutes;
        }

        $this->save($server, $state, $run);

        return ['ok' => true, 'minutes' => $warnMinutes];
    }

    /**
     * Einen geplanten Neustart von Hand zuruecknehmen, solange noch gewarnt
     * wird. Nur den von Hand: einen automatischen brauchte die naechste
     * Pruefung ohnehin wieder an.
     */
    public function cancelScheduledRestart(Server $server): bool
    {
        $state = $this->store->read($server);
        $run = $state['run'];
        if (($run['phase'] ?? '') !== 'warning' || ($run['trigger'] ?? '') !== 'manual') {
            return false;
        }

        $this->save($server, $state, [
            'phase' => 'idle',
            'last_restart_at' => $run['last_restart_at'] ?? 0,
            'checked_at' => $run['checked_at'] ?? 0,
            'note' => 'Geplanter Neustart abgebrochen.',
        ]);

        return true;
    }

    public function restartManually(Server $server, string $why, string $by): array
    {
        $state = $this->store->read($server);
        $auto = $state['auto'];
        $profile = $this->profiles->for($server, $auto);
        $wanted = (bool) ($auto['backup'] ?? true);
        $backupId = null;
        $settled = false;

        if ($wanted) {
            $this->messenger->save($server, $profile, $auto);
            $backupId = $this->startBackup($server, 'Vor Neustart');

            // Ein Panel, das der Anfrage weniger Zeit laesst, als wir warten
            // wollen, bekommt weniger Warten - keine halbfertige Anfrage.
            $budget = (int) ini_get('max_execution_time');
            $allowed = $budget > 0
                ? max(0, min(self::MANUAL_BACKUP_WAIT_SECONDS, $budget - self::REQUEST_HEADROOM_SECONDS))
                : self::MANUAL_BACKUP_WAIT_SECONDS;

            $waited = 0;
            while ($backupId !== null && $waited < $allowed) {
                if ($this->backupSettled($backupId)) {
                    $settled = true;
                    break;
                }
                sleep(2);
                $waited += 2;
            }
        }

        // Erst neu starten, dann festhalten. Der automatische Weg schreibt
        // vorher, weil das Panel danach vielleicht nicht mehr da ist; hier ist
        // der Aufrufer eine Seitenanfrage, die den Fehler abfangen kann, und
        // eine Historie, die einen nie erfolgten Neustart behauptet, ist
        // schlimmer als gar keine.
        $this->power->setServer($server)->send('restart');

        $state['history'] = $this->store->remember($state['history'] ?? [], [
            'at' => now()->timestamp,
            'trigger' => 'manual',
            'reason' => 'manuell',
            'by' => $by,
            'changes' => [['kind' => 'mod', 'name' => $why]],
            'backup_id' => $backupId,
            // Einen manuellen Neustart kontrolliert nichts, und ihn "geprueft"
            // zu nennen waere eine Luege in einer Spalte, der ein Operator
            // vertrauen soll.
            'outcome' => 'unverified',
        ]);
        $this->store->write($server, $state);

        return ['restarted' => true, 'wanted_backup' => $wanted, 'backup_id' => $backupId, 'backup_done' => $settled];
    }

    // -------------------------------------------------------------- erkennung

    /**
     * Woran dieser Server, wenn ueberhaupt, hinterherhaengt.
     *
     * `$fresh` ist der "Jetzt pruefen"-Knopf. Der holt alles neu, statt eine
     * zwischengespeicherte Antwort zu wiederholen: wer den Knopf drueckt, hat
     * meistens gerade ein Update gesehen und will eine Aussage ueber jetzt. Der
     * Scheduler uebergibt ihn nie - der Cache ist das, was ein
     * Fuenf-Minuten-Intervall ueber ein ganzes Panel ertraeglich macht.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $auto
     * @return array{reason:?string,detail:array<int,string>,ids:string[],stale:array<string,array<string,mixed>>,build:?int,degraded:bool,note:string}
     */
    public function detect(Server $server, array $profile, array $auto, bool $fresh = false): array
    {
        $reasons = [];
        $detail = [];
        $degraded = false;
        $game = [];
        $mods = ['ids' => [], 'stale' => [], 'versions' => []];

        if ($auto['check_mods'] ?? true) {
            $mods = $this->outdatedMods($server, $profile, $auto, $fresh);
            $detail = $mods['detail'];
            $degraded = $mods['degraded'];
            if ($mods['ids']) {
                $reasons[] = 'Mod';
            }
        }

        if ($auto['check_game'] ?? true) {
            $appId = $profile['app_id'] ?? null;
            $game = $this->build->compare($server, $appId, $fresh);
            if ($appId === null) {
                $detail[] = 'Spiel-Build: keine Steam-App-Id bekannt, uebersprungen.';
                $degraded = true;
            } elseif ($game['installed'] === null) {
                $detail[] = 'Spiel-Build: kein appmanifest_' . $appId . '.acf auf der Platte, uebersprungen.';
                $degraded = true;
            } elseif ($game['latest'] === null) {
                $detail[] = 'Spiel-Build: installiert ' . $game['installed'] . ', Steam nicht erreichbar, uebersprungen.';
                $degraded = true;
            } else {
                $detail[] = 'Spiel-Build: installiert ' . $game['installed'] . ', oeffentlich ' . $game['latest'] . '.';
                if ($game['outdated']) {
                    $reasons[] = 'Spiel';
                }
            }
        }

        $reason = $reasons ? implode(' und ', $reasons) : null;

        // Drei Ausgaenge, nicht zwei. "Nichts gefunden" und "konnten nicht
        // nachsehen" ergaben frueher denselben Satz - genau so kam es dazu, dass
        // "Jetzt pruefen" alles als aktuell meldete, waehrend der naechste
        // Neustart Updates nachlud.
        if ($reason !== null) {
            $note = 'Update gefunden: ' . $reason . '.';
        } elseif ($degraded) {
            $note = 'Konnte nicht alles pruefen. Ein Teil wurde nicht verglichen.';
        } else {
            $note = 'Alles aktuell.';
        }

        return [
            'reason' => $reason,
            'detail' => $detail,
            'ids' => $mods['ids'],
            'stale' => $mods['stale'],
            // Stand je verfolgtem Mod fuer die Liste auf der Seite:
            // current, update (mit Nummer) oder unknown.
            'versions' => $mods['versions'] ?? [],
            'build' => $game['installed'] ?? null,
            'degraded' => $degraded,
            'note' => $note,
        ];
    }

    /**
     * Mods, deren Version im Repository von der auf der Platte abweicht.
     *
     * Verglichen wird die Versionsnummer, nicht der Zeitstempel. Thunderstore
     * und Hexium liefern echte Nummern, damit entfaellt jede Toleranzrechnerei.
     *
     * Jede Mod wird gegen GENAU EINE Quelle geprueft - die aus ihrer Ausnahme
     * oder die global gewaehlte. Wuerde gemischt, meldete dieselbe Mod je nach
     * Quelle eine andere Version und der Server startete zwischen beiden im
     * Kreis neu.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $auto
     * @return array{ids:string[],stale:array<string,array<string,mixed>>,detail:array<int,string>,degraded:bool}
     */
    private function outdatedMods(Server $server, array $profile, array $auto, bool $fresh = false): array
    {
        $sources = (array) ($profile['sources'] ?? []);
        if (!$sources) {
            return ['ids' => [], 'stale' => [], 'degraded' => false,
                'detail' => ['Fuer dieses Profil ist kein Mod-Repository hinterlegt.']];
        }

        $index = $this->scanner->index($server, $profile, $fresh);
        if (!$index['ok']) {
            return ['ids' => [], 'stale' => [], 'degraded' => true, 'detail' => [$index['note']]];
        }

        $tracked = array_values(array_filter($index['mods'], fn ($mod) => $mod['tracked']));
        $untracked = array_values(array_filter($index['mods'], fn ($mod) => !$mod['tracked']));

        if (!$tracked) {
            // Nichts zu vergleichen ist nicht dasselbe wie vergleichen
            // gescheitert: ein Server ohne Mods ist tatsaechlich aktuell.
            return ['ids' => [], 'stale' => [], 'degraded' => false,
                'detail' => ['Keine verfolgbaren Mods installiert.']];
        }

        if ($fresh) {
            $this->registry->clearBackoff($sources);
        }

        $packages = [];
        foreach ($tracked as $mod) {
            $packages[] = [
                'namespace' => $mod['namespace'],
                'name' => $mod['name'],
                'source' => $this->profiles->sourceFor($profile, $auto, $mod['full_name']),
            ];
        }

        $latest = $this->registry->latest($packages, $sources, $fresh);
        $degraded = $this->registry->degraded();

        $ids = [];
        $stale = [];
        $unknown = [];
        $versions = [];

        foreach ($tracked as $mod) {
            $key = $mod['full_name'];
            $remote = $latest[$key]['version'] ?? null;
            $versions[$key] = [
                'state' => $remote === null ? 'unknown' : ($remote === $mod['version'] ? 'current' : 'update'),
                'latest' => $remote,
                'source' => (string) ($latest[$key]['source'] ?? ''),
            ];

            if ($remote === null) {
                // Nicht im Repository oder nicht erreichbar. Beides heisst
                // "keine Information" und fuehrt nie zu einem Neustart.
                $unknown[] = $key;

                continue;
            }

            // Reiner Gleichheitsvergleich, kein "neuer als". version_compare
            // wuerde bei einem zurueckgezogenen Update einen Neustart ausloesen,
            // der die aeltere Version nie herbeifuehren kann - und die Funktion
            // schaltet sich danach selbst ab.
            if ($remote === $mod['version']) {
                continue;
            }

            $source = $latest[$key]['source'] ?? null;
            $ids[] = $key;
            $stale[$key] = [
                'id' => $key,
                'name' => $mod['name'],
                'version' => $mod['version'],
                'latest' => $remote,
                'source' => (string) $source,
                'url' => $this->registry->pageUrl($profile, $source, $mod['namespace'], $mod['name']),
            ];
        }

        $detail = [count($tracked) . ' Mods verglichen, ' . count($ids) . ' abweichend.'];

        if ($degraded) {
            $detail[] = 'Nicht erreichbar: ' . implode(', ', $this->registry->degradedSources())
                . '. Ein Teil der Antworten ist aelter.';
        }
        if ($untracked) {
            $detail[] = 'Nicht verfolgt (kein Autor im Ordnernamen): '
                . implode(', ', array_slice(array_column($untracked, 'folder'), 0, 5)) . '.';
        }
        if ($unknown) {
            $detail[] = 'Im Repository nicht gefunden: ' . implode(', ', array_slice($unknown, 0, 5)) . '.';
        }
        foreach (array_slice($stale, 0, 10) as $row) {
            $detail[] = 'Abweichend: ' . $row['name'] . ' ' . $row['version'] . ' -> ' . $row['latest']
                . ' (' . $row['source'] . ').';
        }

        return ['ids' => $ids, 'stale' => $stale, 'versions' => $versions, 'detail' => $detail, 'degraded' => $degraded];
    }

    // -------------------------------------------------------------- ansagen

    /**
     * Ansage an alle im Spiel.
     *
     * Jede Nachricht bekommt jeden Platzhalter, egal zu welcher Phase sie
     * gehoert. Die Einstellungen bieten `:minutes`, `:seconds` und `:reason` an,
     * ohne zu sagen, welche Nachricht welchen benutzen darf, und ein nicht
     * ersetzter Platzhalter ist kein stiller Leerlauf: dann geht der Text
     * ":reason" woertlich an jeden Spieler raus.
     *
     * Nie toedlich. Der Rueckgabewert landet in der Historie, damit ein Operator
     * sieht, ob ueberhaupt gewarnt werden konnte.
     */
    private function announce(Server $server, array $profile, array $auto, string $template, string $reason, int $minutes, int $seconds): bool
    {
        $text = strtr(trim($template), [
            ':minutes' => (string) $minutes,
            ':seconds' => (string) $seconds,
            ':reason' => $reason,
        ]);

        return $text !== '' && $this->messenger->broadcast($server, $profile, $auto, $text);
    }

    /** Die letzten Sekunden, eine Ansage pro Sekunde, dann startet der Aufrufer neu. */
    private function countdown(Server $server, array $profile, array $auto, string $reason): void
    {
        $seconds = (int) $auto['countdown_seconds'];
        if ($seconds <= 0 || trim((string) $auto['msg_countdown']) === '') {
            return;
        }

        // Bricht der Ansageweg hier weg, wird nicht weiter im Sekundentakt
        // dagegen gelaufen - jeder Versuch hat sein eigenes Zeitlimit, und 60
        // Fehlversuche zu je fuenf Sekunden wuerden den Tick sprengen.
        for ($i = $seconds; $i > 0; $i--) {
            if (!$this->announce($server, $profile, $auto, $auto['msg_countdown'], $reason, 0, $i)) {
                return;
            }
            sleep(1);
        }
    }

    // ----------------------------------------------------------------- backup

    /**
     * Backup dieses Servers anstossen und die Id zurueckgeben.
     *
     * Derselbe Dienst, den der Backups-Knopf des Panels aufruft - das Ergebnis
     * ist ein ganz normales Server-Backup in derselben Liste und gegen dasselbe
     * Limit.
     */
    private function startBackup(Server $server, string $label): ?int
    {
        try {
            $backup = app(InitiateBackupService::class)->handle(
                $server,
                $label . ' ' . now()->format('Y-m-d H:i'),
                true
            );

            return $backup->id;
        } catch (\Throwable $e) {
            // Schliesst die Drossel des Panels ein. Ein fehlendes Backup ist ein
            // Grund, es zu vermerken, kein Grund, den Server unbetretbar zu
            // lassen.
            Log::warning('mod-auto-restart: Backup konnte nicht gestartet werden', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** True, sobald das Backup nicht mehr laeuft - erfolgreich oder nicht. */
    private function backupSettled(int $backupId): bool
    {
        $backup = Backup::find($backupId);

        return $backup === null || $backup->completed_at !== null;
    }

    // -------------------------------------------------------- egg AUTO_UPDATE

    /**
     * Laedt der Server beim Start ueber SteamCMD nach?
     *
     * Das ist der Unterschied zwischen einem Neustart, der das Update holt, und
     * einem, der nichts aendert.
     */
    public function autoUpdateEnabled(Server $server): bool
    {
        foreach ($server->variables as $variable) {
            if ($variable->env_variable === 'AUTO_UPDATE') {
                return trim((string) ($variable->server_value ?? $variable->default_value ?? '')) === '1';
            }
        }

        // Diese Variable gibt es im Egg nicht. Manche Images aktualisieren
        // immer; lieber dem Operator sein Egg zutrauen als die Funktion
        // rundheraus sperren.
        return true;
    }

    /** @return bool false, wenn das Egg keine AUTO_UPDATE-Variable hat. */
    public function enableAutoUpdateVariable(Server $server): bool
    {
        foreach ($server->variables as $variable) {
            if ($variable->env_variable === 'AUTO_UPDATE') {
                ServerVariable::updateOrCreate(
                    ['server_id' => $server->id, 'variable_id' => $variable->id],
                    ['variable_value' => '1'],
                );
                $server->load('variables');

                return true;
            }
        }

        return false;
    }

    /**
     * Eine Egg-Variable, vor der das Profil warnt, steht auf 1.
     *
     * Bei Valheim ist das ENABLE_CROSSPLAY: mit Crossplay laedt BepInEx in 1.0
     * nicht, die installierten Mods laufen also gar nicht, und eine
     * Mod-Ueberwachung meldete ewig Updates ohne Wirkung.
     *
     * @param  array<string,mixed>  $profile
     */
    public function noteFlag(Server $server, array $profile, string $note): bool
    {
        $name = (string) (($profile['notes'] ?? [])[$note] ?? '');
        if ($name === '') {
            return false;
        }

        foreach ($server->variables as $variable) {
            if ($variable->env_variable === $name) {
                return trim((string) ($variable->server_value ?? $variable->default_value ?? '')) === '1';
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ state

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $run
     */
    /**
     * Eine Pruefung von Hand im Zustand vermerken.
     *
     * Nur Zeitpunkt, Ergebnis und Vollstaendigkeit - die Phase eines
     * laufenden Neustarts bleibt unangetastet, sonst koennte ein Klick auf
     * "Jetzt pruefen" mitten in einer Warnung den Zaehler verstellen.
     *
     * @param  array<string,mixed>  $found  aus detect()
     */
    public function noteManualCheck(Server $server, array $found): void
    {
        $state = $this->store->read($server);
        $run = $state['run'];
        $run['checked_at'] = now()->timestamp;
        $run['note'] = (string) ($found['note'] ?? '');
        $run['degraded'] = (bool) ($found['degraded'] ?? false);
        $this->save($server, $state, $run);
    }

    private function save(Server $server, array $state, array $run): void
    {
        $state['run'] = $run;
        $this->store->write($server, $state);
    }

    /**
     * Server, die dieses Plugin ueberhaupt anfasst.
     *
     * Ohne `require_known_profile` sind das alle mit eingeschalteter Funktion -
     * die Einstellung liegt beim Server, nicht in einer Liste hier. Das ist der
     * Unterschied zwischen einem Plugin fuer ein Spiel und einem fuer beliebige.
     *
     * @return \Illuminate\Support\Collection<int,Server>
     */
    private function servers()
    {
        $servers = Server::query()->with(['egg', 'variables', 'allocation'])->get();

        if (config('mod-auto-restart.require_known_profile', false)) {
            $servers = $servers->filter(fn (Server $s) => $this->profiles->detect($s) !== null);
        }

        return $servers->values();
    }
}
