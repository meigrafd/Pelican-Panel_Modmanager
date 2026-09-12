<?php

namespace Meigrafd\ModAutoRestart\Services;

/**
 * Liest und schreibt BepInEx-Konfigurationsdateien, ohne sie umzubauen.
 *
 * BepInEx schreibt zu jedem Wert seine Beschreibung, den Typ, die Vorgabe und
 * bei Aufzaehlungen die erlaubten Werte als Kommentare davor:
 *
 *   ## Port for RCON server
 *   # Setting type: Int32
 *   # Default value: 2458
 *   Port = 2458
 *
 * Daraus laesst sich ein Formular bauen, ohne das Mod zu kennen. Beim
 * Schreiben wird NUR die Wertzeile ersetzt; Kommentare, Reihenfolge, Leerzeilen
 * und Zeilenenden bleiben, wie sie sind. Ein Mod, das seine Datei beim
 * naechsten Start selbst wieder anfasst, findet sie so vor, wie es sie
 * hinterlassen hat.
 *
 * Reine Logik ohne Aussenwelt, deshalb ohne Panel testbar.
 */
class ConfigFile
{
    /** Typen, die BepInEx als Zahl schreibt. Alles andere ist Text oder Auswahl. */
    private const NUMBER_TYPES = ['Int16', 'Int32', 'Int64', 'UInt16', 'UInt32', 'UInt64', 'Byte', 'SByte',
        'Single', 'Double', 'Decimal'];

    private const INTEGER_TYPES = ['Int16', 'Int32', 'Int64', 'UInt16', 'UInt32', 'UInt64', 'Byte', 'SByte'];

    /**
     * @return array{
     *   plugin: string,
     *   version: string,
     *   guid: string,
     *   eol: string,
     *   lines: array<int,string>,
     *   entries: array<int,array{section:string,key:string,value:string,description:string,type:string,default:?string,acceptable:?array<int,string>,range:?array{0:string,1:string},flags:bool,line:int}>
     * }
     */
    public function parse(string $raw): array
    {
        $raw = (string) preg_replace('~^\xEF\xBB\xBF~', '', $raw);
        $eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('~\r\n|\n|\r~', $raw) ?: [];

        $doc = ['plugin' => '', 'version' => '', 'guid' => '', 'eol' => $eol, 'lines' => $lines, 'entries' => []];
        $fresh = ['type' => '', 'default' => null, 'acceptable' => null, 'range' => null, 'flags' => false];

        $section = '';
        $description = [];
        $meta = $fresh;

        foreach ($lines as $i => $line) {
            $t = trim($line);

            if ($t === '') {
                $description = [];
                $meta = $fresh;

                continue;
            }

            if (preg_match('~^\[(.+)\]$~', $t, $m)) {
                $section = trim($m[1]);
                $description = [];
                $meta = $fresh;

                continue;
            }

            if (str_starts_with($t, '##')) {
                $text = trim(substr($t, 2));
                if (preg_match('~^Settings file was created by plugin (.+?) v(\S+)$~', $text, $m)) {
                    $doc['plugin'] = trim($m[1]);
                    $doc['version'] = $m[2];
                } elseif (preg_match('~^Plugin GUID: (\S+)$~', $text, $m)) {
                    $doc['guid'] = $m[1];
                } else {
                    $description[] = $text;
                }

                continue;
            }

            if (str_starts_with($t, '#')) {
                $text = trim(substr($t, 1));
                if (preg_match('~^Setting type: (.+)$~', $text, $m)) {
                    $meta['type'] = trim($m[1]);
                } elseif (preg_match('~^Default value:(.*)$~', $text, $m)) {
                    $meta['default'] = trim($m[1]);
                } elseif (preg_match('~^Acceptable values: (.+)$~', $text, $m)) {
                    $meta['acceptable'] = array_values(array_filter(array_map('trim', explode(',', $m[1])), fn ($v) => $v !== ''));
                } elseif (preg_match('~^Acceptable value range: From (\S+) to (\S+)$~', $text, $m)) {
                    $meta['range'] = [$m[1], $m[2]];
                } elseif (str_starts_with($text, 'Multiple values can be set')) {
                    $meta['flags'] = true;
                }

                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            if ($key === '') {
                continue;
            }

            $doc['entries'][] = [
                'section' => $section,
                'key' => $key,
                'value' => trim(substr($line, $eq + 1)),
                'description' => implode(' ', $description),
                'line' => $i,
            ] + $meta;

            $description = [];
            $meta = $fresh;
        }

        return $doc;
    }

    /**
     * Die Datei mit neuen Werten, sonst unveraendert.
     *
     * @param  array<string,mixed>  $doc  aus parse()
     * @param  array<int,string>  $values  Eintragsindex => neuer Wert, schon als Text
     */
    public function render(array $doc, array $values): string
    {
        $lines = $doc['lines'];
        foreach ($values as $index => $value) {
            $entry = $doc['entries'][$index] ?? null;
            if ($entry === null) {
                continue;
            }
            $lines[$entry['line']] = $entry['key'] . ' = ' . $value;
        }

        return implode($doc['eol'], $lines);
    }

    /**
     * Welches Eingabefeld zu einem Eintrag passt.
     *
     * @param  array<string,mixed>  $entry
     * @return 'toggle'|'select'|'number'|'text'
     */
    public function kind(array $entry): string
    {
        if ($entry['type'] === 'Boolean') {
            return 'toggle';
        }
        // Mehrfachauswahl (Flags) bleibt Text: die Werte stehen kommagetrennt
        // in einer Zeile, und ein Auswahlfeld koennte nur einen davon halten.
        if ($entry['acceptable'] && !$entry['flags']) {
            return 'select';
        }
        if (in_array($entry['type'], self::NUMBER_TYPES, true)) {
            return 'number';
        }

        return 'text';
    }

    /**
     * Formularwert in die Schreibweise der Datei bringen und pruefen.
     *
     * @param  array<string,mixed>  $entry
     * @return array{value:string,error:?string}
     */
    public function normalize(array $entry, mixed $input): array
    {
        $kind = $this->kind($entry);

        if ($kind === 'toggle') {
            return ['value' => $input ? 'true' : 'false', 'error' => null];
        }

        $value = trim((string) $input);

        if ($kind === 'select') {
            if (!in_array($value, $entry['acceptable'], true)) {
                return ['value' => $value, 'error' => $entry['key'] . ': "' . $value . '" ist nicht erlaubt (' . implode(', ', $entry['acceptable']) . ').'];
            }

            return ['value' => $value, 'error' => null];
        }

        if ($kind === 'number') {
            $value = str_replace(',', '.', $value);
            if (!is_numeric($value)) {
                return ['value' => $value, 'error' => $entry['key'] . ': "' . $value . '" ist keine Zahl.'];
            }
            if (in_array($entry['type'], self::INTEGER_TYPES, true) && !preg_match('~^-?\d+$~', $value)) {
                return ['value' => $value, 'error' => $entry['key'] . ': "' . $value . '" ist keine ganze Zahl.'];
            }
            if ($entry['range'] !== null
                && ((float) $value < (float) $entry['range'][0] || (float) $value > (float) $entry['range'][1])) {
                return ['value' => $value, 'error' => $entry['key'] . ': ' . $value . ' liegt nicht zwischen '
                    . $entry['range'][0] . ' und ' . $entry['range'][1] . '.'];
            }

            return ['value' => $value, 'error' => null];
        }

        // Text: Zeilenumbrueche wuerden die Datei zerlegen - eine Zeile je Wert.
        return ['value' => (string) preg_replace('~[\r\n]+~', ' ', $value), 'error' => null];
    }

    /**
     * Gehoert die Datei eines Plugins zu einem Mod aus dem Mod-Ordner?
     *
     * Der Dateiname ist die Plugin-GUID (org.tristan.rcon), der Ordner heisst
     * Autor-Paket (Tristan-ValheimRcon) - beides verraet nichts Sicheres.
     * Verglichen wird der Plugin-Name aus der Kopfzeile mit dem Paketnamen,
     * ohne Gross-/Kleinschreibung und Sonderzeichen. Enthaelt der eine den
     * anderen, gilt das als Treffer; unter fuenf Zeichen nicht, sonst passt
     * "Core" auf die halbe Modliste.
     */
    public function matches(string $pluginName, string $modName): bool
    {
        $a = $this->squash($pluginName);
        $b = $this->squash($modName);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $short = strlen($a) < strlen($b) ? $a : $b;

        return strlen($short) >= 5 && (str_contains($a, $b) || str_contains($b, $a));
    }

    private function squash(string $s): string
    {
        return strtolower((string) preg_replace('~[^a-z0-9]~i', '', $s));
    }
}
