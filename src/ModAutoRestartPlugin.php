<?php

namespace Meigrafd\ModAutoRestart;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;
use Meigrafd\ModAutoRestart\Filament\Server\Pages\AutoRestart;

class ModAutoRestartPlugin implements Plugin
{
    public function getId(): string
    {
        return 'mod-auto-restart';
    }

    public function register(Panel $panel): void
    {
        $root = plugin_path($this->getId());

        View::addNamespace('mod-auto-restart', $root . '/resources/views');
        Lang::addNamespace('mar', $root . '/lang');

        $panel->pages([
            AutoRestart::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
