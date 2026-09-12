{{--
    Aufbau wie die Zomboid-Vorlage: Statuskarte oben, Einstellungen darunter,
    Mods, Historie unten. Neu ist nur der Spiel-Block ganz oben in den
    Einstellungen - alles darunter ist unveraendert an seiner Stelle.
--}}
<x-filament-panels::page>

    {{-- ------------------------------------------------------ Statuskarte --}}
    <x-filament::section>
        <x-slot name="heading">{{ trans('mar::messages.status.heading') }}</x-slot>
        <x-slot name="description">
            {{ $profile['label'] ?? '?' }}@if (! empty($profile['app_id'])) · App {{ $profile['app_id'] }}@endif
            @if ($this->checkedAt()) · {{ trans('mar::messages.status.checked', ['ago' => $this->checkedAt()]) }}@endif
        </x-slot>

        <div class="space-y-3">
            <p @class([
                'text-sm',
                'text-success-600 dark:text-success-400' => $this->statusTone() === 'success',
                'text-warning-600 dark:text-warning-400' => $this->statusTone() === 'warning',
                'text-danger-600 dark:text-danger-400' => $this->statusTone() === 'danger',
            ])>
                {{ $run['note'] ?? trans('mar::messages.status.idle') }}
            </p>

            @if (($run['phase'] ?? '') === 'failed')
                {{-- Endstation: die Funktion hat sich selbst abgeschaltet und
                     braucht eine bewusste Entscheidung, bevor sie wieder
                     laeuft. --}}
                <div class="rounded-lg bg-danger-50 dark:bg-danger-900/20 p-3 text-sm space-y-2">
                    <p>{{ trans('mar::messages.status.failed_help') }}</p>
                    @if ($this->writable())
                        <x-filament::button size="sm" color="danger" wire:click="clearFailure">
                            {{ trans('mar::messages.action.clear_failure') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif

            @if (! $eggAutoUpdate)
                <div class="rounded-lg bg-warning-50 dark:bg-warning-900/20 p-3 text-sm space-y-2">
                    <p>{{ trans('mar::messages.status.no_auto_update') }}</p>
                    @if ($this->writable())
                        <x-filament::button size="sm" color="warning" wire:click="enableEggAutoUpdate">
                            {{ trans('mar::messages.action.set_flag') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif

            @if ($crossplay && ($auto['check_mods'] ?? true))
                {{-- Profilgebundene Warnung. Bei Valheim: Crossplay an heisst,
                     BepInEx laedt in 1.0 nicht - Mods zu ueberwachen, die gar
                     nicht laufen, meldet ewig Updates ohne Wirkung. --}}
                <div class="rounded-lg bg-warning-50 dark:bg-warning-900/20 p-3 text-sm">
                    {{ trans('mar::messages.status.crossplay') }}
                </div>
            @endif

            @if (($auto['enabled'] ?? false) && ! $canWarn)
                {{-- Kein Ansageweg eingerichtet. Kein Fehler, aber der
                     Unterschied zwischen "Neustart mit Vorwarnung" und
                     "Spieler fliegen ohne Ankuendigung raus" ist gross genug,
                     um ihn nicht stillschweigend hinzunehmen. --}}
                <div class="rounded-lg bg-warning-50 dark:bg-warning-900/20 p-3 text-sm">
                    {{ $this->messagingVia() === 'none'
                        ? trans('mar::messages.status.silent_profile')
                        : trans('mar::messages.status.silent_unconfigured') }}
                </div>
            @endif

            @if (! empty($run['detail']))
                <details class="text-sm">
                    <summary class="cursor-pointer select-none opacity-70">
                        {{ trans('mar::messages.status.detail') }}
                    </summary>
                    <ul class="mt-2 space-y-1 opacity-80">
                        @foreach ($run['detail'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif

            <div class="flex flex-wrap gap-2">
                <x-filament::button size="sm" color="gray" wire:click="checkNow">
                    {{ trans('mar::messages.action.check_now') }}
                </x-filament::button>

                @if ($this->restartable())
                    <x-filament::button
                        size="sm"
                        color="gray"
                        wire:click="restartNow"
                        wire:confirm="{{ trans('mar::messages.action.restart_confirm') }}"
                    >
                        {{ trans('mar::messages.action.restart_now') }}
                    </x-filament::button>
                @endif
            </div>
        </div>
    </x-filament::section>

    {{-- ---------------------------------------------------- Einstellungen --}}
    <x-filament::section collapsible>
        <x-slot name="heading">{{ trans('mar::messages.settings.heading') }}</x-slot>
        <x-slot name="description">{{ trans('mar::messages.settings.intro') }}</x-slot>

        <div class="space-y-6">

            {{-- ------------------------------------------------- Spiel --}}
            <div class="space-y-3">
                <p class="text-sm font-medium">{{ trans('mar::messages.settings.game_heading') }}</p>

                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="block text-sm mb-1">{{ trans('mar::messages.settings.profile') }}</label>
                        <select wire:model.live="auto.profile" @disabled(! $this->writable())
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="">{{ trans('mar::messages.settings.profile_auto') }}</option>
                            @foreach ($profileOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @if (! empty($profile['detected']) && ($auto['profile'] ?? '') === '')
                            <p class="mt-1 text-xs opacity-70">
                                {{ trans('mar::messages.settings.profile_detected', ['label' => $profile['label']]) }}
                            </p>
                        @endif
                    </div>

                    <div>
                        <label class="block text-sm mb-1">{{ trans('mar::messages.settings.app_id') }}</label>
                        <input type="text" inputmode="numeric" wire:model="auto.app_id" @disabled(! $this->writable())
                               placeholder="{{ $profile['app_id'] ?? trans('mar::messages.settings.app_id_unknown') }}"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        {{-- Wichtig: die App des DEDIZIERTEN Servers, nicht die
                             des Spiels. Mit der falschen Id gibt es kein
                             appmanifest, und die Pruefung meldet ewig
                             "uebersprungen". --}}
                        <p class="mt-1 text-xs opacity-70">{{ trans('mar::messages.settings.app_id_hint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm mb-1">{{ trans('mar::messages.settings.mods_path') }}</label>
                        <input type="text" wire:model="auto.mods_path" @disabled(! $this->writable())
                               placeholder="{{ $profile['mods_path'] ?? '—' }}"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <p class="mt-1 text-xs opacity-70">{{ trans('mar::messages.settings.mods_path_hint') }}</p>
                    </div>
                </div>

                @if (count($this->sources()) > 1)
                    <div class="md:w-1/3">
                        <label class="block text-sm mb-1">{{ trans('mar::messages.settings.source') }}</label>
                        <select wire:model="auto.source" @disabled(! $this->writable())
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach ($this->sources() as $name => $base)
                                <option value="{{ $name }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        {{-- Der Grund, warum es ueberhaupt eine Wahl gibt und
                             nicht einfach beide gleichzeitig: dieselbe Mod hat
                             in zwei Repositorys oft verschiedene
                             Versionsnummern. --}}
                        <p class="mt-1 text-xs opacity-70">{{ trans('mar::messages.settings.source_hint') }}</p>
                    </div>
                @elseif (count($this->sources()) === 1)
                    <p class="text-xs opacity-70">
                        {{ trans('mar::messages.settings.source_only', ['name' => array_key_first($this->sources())]) }}
                    </p>
                @endif
            </div>

            <hr class="border-gray-200 dark:border-gray-700">

            <label class="flex items-start gap-3 text-sm font-medium">
                <input type="checkbox" wire:model="auto.enabled" @disabled(! $this->writable())
                       class="mt-0.5 rounded border-gray-300 dark:border-gray-600">
                <span>{{ trans('mar::messages.settings.enabled') }}</span>
            </label>

            <div class="grid gap-4 md:grid-cols-4">
                <div>
                    <label class="block text-sm mb-1">{{ trans('mar::messages.settings.warn') }}</label>
                    <select wire:model="auto.warn_minutes" @disabled(! $this->writable())
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <option value="0">{{ trans('mar::messages.settings.warn_none') }}</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="5">{{ trans('mar::messages.settings.recommended', ['n' => 5]) }}</option>
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="30">30</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm mb-1">{{ trans('mar::messages.settings.interval') }}</label>
                    <select wire:model="auto.check_minutes" @disabled(! $this->writable())
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <option value="5">{{ trans('mar::messages.settings.recommended', ['n' => 5]) }}</option>
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="30">30</option>
                        <option value="60">60</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm mb-1">{{ trans('mar::messages.settings.backup') }}</label>
                    <select wire:model="auto.backup" @disabled(! $this->writable())
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <option value="1">{{ trans('mar::messages.settings.backup_always') }}</option>
                        <option value="0">{{ trans('mar::messages.settings.backup_never') }}</option>
                    </select>
                    <p class="mt-1 text-xs opacity-70">{{ trans('mar::messages.settings.backup_hint') }}</p>
                </div>

                <div>
                    <label class="block text-sm mb-1">{{ trans('mar::messages.settings.watch') }}</label>
                    @php
                        $watch = ($auto['check_mods'] ?? true) && ($auto['check_game'] ?? true)
                            ? 'both'
                            : (($auto['check_mods'] ?? true) ? 'mods' : 'game');
                    @endphp
                    <select
                        @disabled(! $this->writable())
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"
                        wire:change="$set('auto.check_mods', $event.target.value !== 'game')
                                   ; $set('auto.check_game', $event.target.value !== 'mods')"
                    >
                        <option value="both" @selected($watch === 'both')>{{ trans('mar::messages.settings.watch_both') }}</option>
                        <option value="mods" @selected($watch === 'mods')>{{ trans('mar::messages.settings.watch_mods') }}</option>
                        <option value="game" @selected($watch === 'game')>{{ trans('mar::messages.settings.watch_game') }}</option>
                    </select>
                </div>
            </div>

            {{-- ------------------------------------------------- Ansagen --}}
            <details class="space-y-3" @open($this->writable() && ! $canWarn && $this->messagingVia() !== 'none')>
                <summary class="cursor-pointer select-none text-sm font-medium">
                    {{ trans('mar::messages.settings.msg_heading') }}
                </summary>

                @if ($this->messagingVia() === 'none')
                    <p class="text-sm opacity-70">{{ trans('mar::messages.settings.msg_none') }}</p>
                @elseif ($this->messagingVia() === 'console')
                    <p class="text-sm opacity-70">{{ trans('mar::messages.settings.msg_console') }}</p>
                @else
                    <p class="text-sm opacity-70">
                        {{ $this->messagingMod()
                            ? trans('mar::messages.settings.msg_rcon_mod', ['mod' => $this->messagingMod()])
                            : trans('mar::messages.settings.msg_rcon') }}
                    </p>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="block text-sm mb-1">{{ trans('mar::messages.settings.rcon_host') }}</label>
                            <input type="text" wire:model="auto.rcon_host" @disabled(! $this->writable())
                                   placeholder="{{ trans('mar::messages.settings.rcon_host_placeholder') }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm mb-1">{{ trans('mar::messages.settings.rcon_port') }}</label>
                            <input type="number" wire:model="auto.rcon_port" @disabled(! $this->writable())
                                   placeholder="{{ trans('mar::messages.settings.rcon_port_placeholder', ['n' => ($profile['messaging']['port_offset'] ?? 0)]) }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm mb-1">{{ trans('mar::messages.settings.rcon_password') }}</label>
                            <input type="password" wire:model="auto.rcon_password" @disabled(! $this->writable())
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </div>

                    <p class="text-xs opacity-70">{{ trans('mar::messages.settings.rcon_warning') }}</p>
                @endif

                @if ($this->messagingVia() !== 'none')
                    <x-filament::button size="sm" color="gray" wire:click="testMessaging">
                        {{ trans('mar::messages.action.test_messaging') }}
                    </x-filament::button>
                @endif
            </details>

            {{-- -------------------------------------- Erweitert: Nachrichten --}}
            <details class="space-y-4">
                <summary class="cursor-pointer select-none text-sm font-medium">
                    {{ trans('mar::messages.settings.advanced') }}
                </summary>

                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="block text-sm mb-1">{{ trans('mar::messages.settings.countdown') }}</label>
                        <input type="number" min="0" max="60" wire:model="auto.countdown_seconds"
                               @disabled(! $this->writable())
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm mb-1">{{ trans('mar::messages.settings.cooldown') }}</label>
                        <input type="number" min="0" max="1440" wire:model="auto.cooldown_minutes"
                               @disabled(! $this->writable())
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm mb-1">{{ trans('mar::messages.settings.backup_wait') }}</label>
                        <input type="number" min="0" max="900" wire:model="auto.backup_wait_seconds"
                               @disabled(! $this->writable())
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                </div>

                <div class="space-y-3">
                    @foreach ([
                        'msg_warn' => 'settings.msg_warn',
                        'msg_final' => 'settings.msg_final',
                        'msg_countdown' => 'settings.msg_countdown',
                        'msg_back' => 'settings.msg_back',
                    ] as $field => $label)
                        <div>
                            <label class="block text-sm mb-1">{{ trans('mar::messages.' . $label) }}</label>
                            <input type="text" wire:model="auto.{{ $field }}" @disabled(! $this->writable())
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    @endforeach
                    <p class="text-xs opacity-70">{{ trans('mar::messages.settings.placeholders') }}</p>
                </div>
            </details>

            @if ($this->writable())
                <x-filament::button wire:click="save">
                    {{ trans('mar::messages.action.save') }}
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>

    {{-- ----------------------------------------------- installierte Mods --}}
    <x-filament::section collapsible>
        <x-slot name="heading">{{ trans('mar::messages.mods.heading') }}</x-slot>
        <x-slot name="description">{{ $modsNote }}</x-slot>

        {{-- --------------------------------------------- Mod hinzufuegen --}}
        @if ($this->writable() && $this->sources())
            <div class="mb-6 space-y-3">
                @if (! $plan)
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input
                            type="text"
                            wire:model="addInput"
                            wire:keydown.enter="preview"
                            @disabled(! $this->writable())
                            placeholder="{{ trans('mar::messages.add.placeholder') }}"
                            class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"
                        >
                        <x-filament::button wire:click="preview" wire:loading.attr="disabled">
                            {{ trans('mar::messages.action.preview') }}
                        </x-filament::button>
                    </div>
                    <p class="text-xs opacity-70">{{ trans('mar::messages.add.hint') }}</p>
                @else
                    {{-- Zweistufig mit Absicht: erst zeigen, was passieren
                         wuerde, dann auf einen zweiten Knopf hin tun. Eine
                         falsch kopierte URL oder eine unerwartete Abhaengigkeit
                         faellt sonst erst auf, wenn die Dateien schon liegen. --}}
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                        <p class="text-sm font-medium">
                            {{ trans('mar::messages.add.plan', ['source' => $plan['source']]) }}
                        </p>

                        @if ($plan['install'])
                            <ol class="space-y-1 text-sm">
                                @foreach ($plan['install'] as $i => $package)
                                    <li class="flex flex-wrap items-baseline gap-2">
                                        <span class="opacity-50 w-5">{{ $i + 1 }}.</span>
                                        <span class="font-medium">{{ $package['full_name'] }}</span>
                                        <span class="font-mono text-xs">{{ $package['version'] }}</span>
                                        @if ($package['action'] === 'update')
                                            <span class="text-xs text-warning-600 dark:text-warning-400">
                                                {{ trans('mar::messages.add.update_from', ['from' => $package['from']]) }}
                                            </span>
                                        @elseif ($i < count($plan['install']) - 1)
                                            {{-- Alles ausser dem letzten Eintrag
                                                 ist eine Abhaengigkeit: der
                                                 Plan ist so sortiert, dass
                                                 zuerst kommt, was zuerst liegen
                                                 muss. --}}
                                            <span class="text-xs opacity-60">{{ trans('mar::messages.add.dependency') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <p class="text-sm opacity-70">{{ trans('mar::messages.add.nothing') }}</p>
                        @endif

                        @foreach ($plan['skipped'] as $row)
                            <p class="text-xs opacity-60">{{ $row['full_name'] }} — {{ $row['why'] }}</p>
                        @endforeach

                        @foreach ($plan['good'] as $line)
                            <p class="text-xs text-success-600 dark:text-success-400">{{ $line }}</p>
                        @endforeach

                        @foreach ($plan['warnings'] as $line)
                            <p class="text-xs text-warning-600 dark:text-warning-400">{{ $line }}</p>
                        @endforeach

                        @foreach ($plan['stop'] as $line)
                            <p class="text-xs text-danger-600 dark:text-danger-400">{{ $line }}</p>
                        @endforeach

                        <div class="flex flex-wrap gap-2 pt-1">
                            @if ($plan['install'])
                                <x-filament::button
                                    wire:click="install"
                                    wire:loading.attr="disabled"
                                    :color="$plan['stop'] ? 'danger' : 'primary'"
                                >
                                    {{ $plan['stop']
                                        ? trans('mar::messages.action.install_anyway')
                                        : trans('mar::messages.action.install') }}
                                </x-filament::button>
                            @endif
                            <x-filament::button color="gray" wire:click="clearPlan">
                                {{ trans('mar::messages.action.cancel') }}
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @if ($mods)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left opacity-70">
                        <tr>
                            <th class="py-1 pr-4">{{ trans('mar::messages.mods.name') }}</th>
                            <th class="py-1 pr-4">{{ trans('mar::messages.mods.version') }}</th>
                            @if (count($this->sources()) > 1)
                                <th class="py-1 pr-4">{{ trans('mar::messages.mods.source') }}</th>
                            @endif
                            <th class="py-1 pr-4">{{ trans('mar::messages.mods.tracked') }}</th>
                            @if ($this->writable())
                                <th class="py-1"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($mods as $mod)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="py-1 pr-4">{{ $mod['full_name'] }}</td>
                                <td class="py-1 pr-4 font-mono text-xs">{{ $mod['version'] ?: '–' }}</td>

                                @if (count($this->sources()) > 1)
                                    <td class="py-1 pr-4">
                                        @if ($mod['tracked'])
                                            {{-- Ausnahme fuer genau diese Mod.
                                                 Leer heisst: die globale Wahl
                                                 oben gilt. --}}
                                            <select
                                                wire:model="auto.mod_sources.{{ $mod['full_name'] }}"
                                                @disabled(! $this->writable())
                                                class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs py-0.5"
                                            >
                                                <option value="">{{ trans('mar::messages.mods.source_global') }}</option>
                                                @foreach ($this->sources() as $name => $base)
                                                    <option value="{{ $name }}">{{ $name }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="opacity-40">–</span>
                                        @endif
                                    </td>
                                @endif

                                <td class="py-1 pr-4">
                                    @if ($mod['tracked'])
                                        <span class="text-success-600 dark:text-success-400">{{ trans('mar::messages.mods.yes') }}</span>
                                    @else
                                        {{-- Kein Autor im Ordnernamen: eine
                                             geratene Zuordnung wuerde ewig ein
                                             Update melden, das nie ankommt. --}}
                                        <span class="opacity-60">{{ trans('mar::messages.mods.no') }}</span>
                                    @endif
                                </td>

                                @if ($this->writable())
                                    <td class="py-1 text-right">
                                        <button
                                            type="button"
                                            wire:click="remove('{{ $mod['full_name'] }}')"
                                            wire:confirm="{{ trans('mar::messages.mods.remove_confirm', ['mod' => $mod['full_name']]) }}"
                                            class="text-xs text-danger-600 dark:text-danger-400 hover:underline"
                                        >
                                            {{ trans('mar::messages.mods.remove') }}
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- ------------------------------------------------------- Historie --}}
    @if ($history)
        <x-filament::section collapsed collapsible>
            <x-slot name="heading">{{ trans('mar::messages.history.heading') }}</x-slot>
            <x-slot name="description">{{ trans('mar::messages.history.intro') }}</x-slot>

            <div class="space-y-3">
                @foreach ($history as $entry)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-sm space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $entry['at'] }}</span>
                            <span class="opacity-60">{{ $entry['ago'] }}</span>
                            <span @class([
                                'rounded px-1.5 py-0.5 text-xs',
                                'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300' => $entry['outcome'] === 'verified',
                                'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300' => $entry['outcome'] === 'failed',
                                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! in_array($entry['outcome'], ['verified', 'failed'], true),
                            ])>
                                {{ trans('mar::messages.outcome.' . $entry['outcome']) }}
                            </span>
                            @if ($entry['down'])
                                <span class="opacity-60">{{ trans('mar::messages.history.down', ['time' => $entry['down']]) }}</span>
                            @endif
                        </div>

                        <p class="opacity-80">
                            {{ $entry['trigger'] === 'manual'
                                ? trans('mar::messages.history.manual', ['by' => $entry['by'] ?: '?'])
                                : trans('mar::messages.history.auto', ['reason' => $entry['reason']]) }}
                            @if (! is_null($entry['players']))
                                · {{ trans('mar::messages.history.players', ['n' => $entry['players']]) }}
                            @endif
                            @if ($entry['trigger'] === 'auto' && ! $entry['warned'])
                                {{-- Ohne diese Zeile raet ein Operator, warum
                                     sich jemand ueber einen Neustart ohne
                                     Vorwarnung beschwert. --}}
                                · <span class="text-warning-600 dark:text-warning-400">{{ trans('mar::messages.history.not_warned') }}</span>
                            @endif
                        </p>

                        @foreach ($entry['changes'] as $change)
                            <p class="opacity-80">
                                @if ($change['url'])
                                    <a href="{{ $change['url'] }}" target="_blank" rel="noopener" class="underline">{{ $change['name'] }}</a>
                                @else
                                    {{ $change['name'] }}
                                @endif
                                @if ($change['pair'])
                                    <span class="font-mono text-xs">{{ $change['pair'][0] }} → {{ $change['pair'][1] }}</span>
                                @elseif ($change['from_only'])
                                    <span class="font-mono text-xs opacity-60">{{ $change['from_only'] }}</span>
                                @endif
                                @if ($change['source'])
                                    {{-- Welche Quelle den Vergleich geliefert
                                         hat. Ohne diese Angabe ist ein
                                         spaeterer Streit ueber eine
                                         Versionsnummer nicht aufzuloesen. --}}
                                    <span class="text-xs opacity-50">({{ $change['source'] }})</span>
                                @endif
                            </p>
                        @endforeach

                        @if ($entry['note'])
                            <p class="text-danger-600 dark:text-danger-400">{{ $entry['note'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

</x-filament-panels::page>
