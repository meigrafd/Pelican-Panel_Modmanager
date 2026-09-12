<?php

namespace Meigrafd\ModAutoRestart\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Meigrafd\ModAutoRestart\Services\AutoUpdateService;

class ModAutoRestartPluginProvider extends ServiceProvider
{
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
        });
    }
}
