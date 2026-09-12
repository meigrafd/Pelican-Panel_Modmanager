<?php

namespace Meigrafd\ModAutoRestart\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Meigrafd\ModAutoRestart\Services\AutoUpdateService;

class ModAutoRestartPluginProvider extends ServiceProvider
{
    /** Cache-Schluessel der zuletzt gesehenen Plugin-Version. */
    private const VERSION_KEY = 'mar:seen-version';

    public function register(): void
    {
        $this->mergeConfigFrom(
            plugin_path('mod-auto-restart', 'config/mod-auto-restart.php'),
            'mod-auto-restart'
        );
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            // Ein Tick pro Minute fuer alle Server, statt einem Cron-Ausdruck je
            // Intervall. Wie oft ein einzelner Server tatsaechlich geprueft
            // wird, steht in seiner eigenen Datei - so koennen zwei Server auf
            // einem Panel unterschiedlich oft pruefen, und eine Aenderung wirkt
            // ab der naechsten Minute statt erst nach einem Cache-Neubau.
            //
            // withoutOverlapping ist hier wichtig: ein Tick kann einen
            // Countdown von bis zu einer Minute absitzen, und zwei
            // ueberlappende Ticks wuerden zweimal ansagen und zweimal neu
            // starten.
            $this->app->make(Schedule::class)
                ->call(fn () => app(AutoUpdateService::class)->tick())
                ->name('mar:auto-restart')
                ->everyMinute()
                ->withoutOverlapping();

            $this->refreshCachesAfterUpdate();
        });
    }

    /**
     * Nach Install, Update oder git pull einmal aufraeumen, was Pelican
     * liegen laesst.
     *
     * Pelican leert beim Plugin-Install nur die Filament-Komponenten. Die
     * kompilierten Blade-Views und ein gecachter Config-Stand bleiben - so
     * traf nach einem Update die neue Seitenklasse auf die alte kompilierte
     * View ("Undefined variable $crossplay"). Einen Install-Hook fuer
     * Plugins gibt es nicht. Deshalb hier: die zuletzt gesehene Version
     * steht im Cache, und bei jeder Abweichung wird einmal aufgeraeumt.
     * Nach optimize:clear ist die Marke weg und es passiert einmal
     * unnoetig - harmlos, view:clear kostet nichts.
     *
     * Die Marke wird VOR dem Aufraeumen gesetzt: config:cache bootet die
     * Anwendung frisch, und dabei liefe dieser Code noch einmal - ohne
     * Marke endlos.
     */
    private function refreshCachesAfterUpdate(): void
    {
        try {
            $raw = (string) @file_get_contents(plugin_path('mod-auto-restart', 'plugin.json'));
            $version = (string) (json_decode($raw, true)['version'] ?? '');
            if ($version === '' || Cache::get(self::VERSION_KEY) === $version) {
                return;
            }
            Cache::forever(self::VERSION_KEY, $version);

            Artisan::call('view:clear');
            if ($this->app->configurationIsCached()) {
                // Neu bauen, nicht nur leeren: wer Konfiguration cacht, soll
                // sie behalten - nur mit den Spielprofilen dieser Version.
                Artisan::call('config:cache');
            }

            Log::info('mod-auto-restart: Version ' . $version . ' erkannt, View-Cache geleert.');
        } catch (\Throwable $e) {
            Log::warning('mod-auto-restart: Aufraeumen nach Update fehlgeschlagen, bitte php artisan optimize:clear ausfuehren', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
