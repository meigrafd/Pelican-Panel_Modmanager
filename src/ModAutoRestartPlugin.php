<?php

namespace Meigrafd\ModAutoRestart;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;
use Meigrafd\ModAutoRestart\Filament\Server\Pages\AutoRestart;

/**
 * Einstellungen im Panel (Admin -> Plugins -> Zahnrad): Pelican zeigt den
 * Knopf, sobald die Plugin-Klasse HasPluginSettings erfuellt. Gespeichert
 * wird in der .env des Panels, ueber denselben Helfer wie Pelicans eigene
 * Einstellungen; der leert danach den Config-Cache. Die Konfigurationsdatei
 * liest die Werte von dort (MAR_*).
 *
 * Uebersetzungen hier ueber den Namensraum der Plugin-Id: den registriert
 * Pelican fuer jedes Panel, unser 'mar' nur dort, wo register() lief.
 */
class ModAutoRestartPlugin implements Plugin, HasPluginSettings
{
    use EnvironmentWriterTrait;

    private const CACHE_MIN = 1;

    private const CACHE_MAX = 240;

    /** @return array<string,mixed> */
    public function getSettingsFormData(): array
    {
        return [
            'MAR_IGNORED_EGGS' => implode("\n", (array) config('mod-auto-restart.ignored_eggs', [])),
            'MAR_REQUIRE_KNOWN_PROFILE' => (bool) config('mod-auto-restart.require_known_profile', false),
            'MAR_DRY_RUN' => (bool) config('mod-auto-restart.dry_run', false),
            'MAR_CACHE_MINUTES' => (int) config('mod-auto-restart.cache.index_minutes', 10),
        ];
    }

    /** @return array<int,\Filament\Schemas\Components\Component> */
    public function getSettingsForm(): array
    {
        return [
            Textarea::make('MAR_IGNORED_EGGS')
                ->label(trans('mod-auto-restart::messages.plugin.ignored_eggs'))
                ->helperText(trans('mod-auto-restart::messages.plugin.ignored_eggs_hint'))
                ->rows(3),

            Toggle::make('MAR_REQUIRE_KNOWN_PROFILE')
                ->label(trans('mod-auto-restart::messages.plugin.require_known'))
                ->helperText(trans('mod-auto-restart::messages.plugin.require_known_hint')),

            Toggle::make('MAR_DRY_RUN')
                ->label(trans('mod-auto-restart::messages.plugin.dry_run'))
                ->helperText(trans('mod-auto-restart::messages.plugin.dry_run_hint')),

            TextInput::make('MAR_CACHE_MINUTES')
                ->label(trans('mod-auto-restart::messages.plugin.cache_minutes'))
                ->helperText(trans('mod-auto-restart::messages.plugin.cache_minutes_hint'))
                ->numeric()
                ->minValue(self::CACHE_MIN)
                ->maxValue(self::CACHE_MAX),
        ];
    }

    /** @param  array<mixed,mixed>  $data */
    public function saveSettings(array $data): void
    {
        // Eine Zeile je Egg im Formular, kommagetrennt in der .env: ein
        // Zeilenumbruch hat in einer Umgebungsvariablen nichts verloren.
        $eggs = array_values(array_filter(array_map('trim',
            preg_split('~[\r\n,]+~', (string) ($data['MAR_IGNORED_EGGS'] ?? '')) ?: []
        ), fn ($s) => $s !== ''));

        $this->writeToEnvironment([
            'MAR_IGNORED_EGGS' => implode(',', $eggs),
            'MAR_REQUIRE_KNOWN_PROFILE' => ($data['MAR_REQUIRE_KNOWN_PROFILE'] ?? false) ? 'true' : 'false',
            'MAR_DRY_RUN' => ($data['MAR_DRY_RUN'] ?? false) ? 'true' : 'false',
            'MAR_CACHE_MINUTES' => (string) max(self::CACHE_MIN, min(self::CACHE_MAX, (int) ($data['MAR_CACHE_MINUTES'] ?? 10))),
        ]);
    }

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
