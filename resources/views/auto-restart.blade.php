{{--
    Die ganze Seite ist ein Filament-Schema, das in der Seitenklasse gebaut
    wird. Hier steht deshalb nur das Geruest.

    Das ist kein Geiz, sondern der Kern der Sache: Ein Filament-Panel kompiliert
    sein CSS vorab und nimmt nur die Klassen auf, die es selbst benutzt. Eigene
    Tailwind-Klassen aus einem Plugin stehen nicht darin und wirken nicht - die
    Felder erscheinen untereinander und ungestylt, ohne dass irgendwo ein Fehler
    auftaucht. Section, Grid und die Formularfelder bringen Layout, Spalten,
    Dunkelmodus und Abstaende dagegen selbst mit.
--}}
<x-filament-panels::page
    id="mod-auto-restart"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
>
    {{ $this->form }}
</x-filament-panels::page>
