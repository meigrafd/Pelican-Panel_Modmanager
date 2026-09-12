#!/usr/bin/env python3
"""Pruefungen, die php -l nicht machen kann.

Ein fehlender Uebersetzungsschluessel wirft keinen Fehler: Blade druckt den
rohen Schluessel, die Seite rendert weiter, und der Fehler geht in Produktion.
Dasselbe gilt fuer ein wire:click auf eine Methode, die es nicht gibt - das
faellt erst auf, wenn jemand klickt. Beides ist billig zu finden, indem man die
Dateien liest, und teuer zu finden, wenn der Server schon laeuft.

Angepasst aus check.py von pz-mod-manager.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
FAILED = []


def check(ok, label, detail=""):
    print("  %-4s %s%s" % ("PASS" if ok else "FAIL", label, "" if ok else "  <- " + detail))
    if not ok:
        FAILED.append(label)


def lang_keys(path):
    """Eine Laravel-Sprachdatei auf punktierte Schluessel flachklopfen."""
    keys, stack = set(), []
    for line in path.read_text().splitlines():
        m = re.match(r"^(\s+)'([^']+)' => (\[)?", line)
        if not m:
            continue
        depth = len(m.group(1)) // 4
        stack = stack[: depth - 1] + [m.group(2)]
        if not m.group(3):
            keys.add(".".join(stack))
    return keys


def main():
    de = lang_keys(ROOT / "lang/de/messages.php")
    en = lang_keys(ROOT / "lang/en/messages.php")
    check(de == en, "de und en haben dieselben Schluessel (%d)" % len(de),
          "nur de: %s / nur en: %s" % (sorted(de - en)[:4], sorted(en - de)[:4]))

    blade = (ROOT / "resources/views/auto-restart.blade.php").read_text()
    page = (ROOT / "src/Filament/Server/Pages/AutoRestart.php").read_text()

    # Nur feste Schluessel. Was per Verkettung gebaut wird, wird unten ueber
    # das Praefix geprueft - das Suffix steht nicht im Quelltext.
    used = set(re.findall(r"trans(?:_choice)?\('mar::messages\.([a-z_.]+)'\)", blade + page))
    used |= set(re.findall(r"trans(?:_choice)?\('mar::messages\.([a-z_.]+)',", blade + page))
    missing = sorted(k for k in used if k not in de and not k.endswith("."))
    check(not missing, "jeder feste Uebersetzungsschluessel existiert (%d)" % len(used),
          ", ".join(missing[:6]))

    # Dynamische Schluessel: 'mar::messages.outcome.' . $outcome
    prefixes = set(re.findall(r"'mar::messages\.([a-z_.]+\.)'\s*\.", blade + page))
    for prefix in sorted(prefixes):
        check(any(k.startswith(prefix) for k in de), "Praefix %s hat Uebersetzungen" % prefix)

    # Jedes wire:click-Ziel muss eine oeffentliche Methode der Seite sein.
    methods = set(re.findall(r"public function (\w+)\(", page))
    # Auch Aufrufe MIT Argument erfassen: wire:click="remove('Autor-Mod')".
    # Der frueher benutzte Ausdruck endete am Methodennamen und haette solche
    # Aufrufe zwar mitgezaehlt, aber nur zufaellig - ein Tippfehler im Namen
    # waere durchgerutscht, wenn er auf ein Praefix einer echten Methode fiel.
    called = set(re.findall(r'wire:(?:click|keydown\.enter|poll\.\d+s)="(\w+)\s*(?:\(|")', blade))
    ghosts = sorted(called - methods)
    check(not ghosts, "jedes wire:click existiert als Methode (%d)" % len(called), ", ".join(ghosts))

    # Livewire bindet daran; ein Tippfehler bindet still ins Leere.
    props = set(re.findall(r"public (?:\w+ )?\$(\w+)", page))
    bound = set(re.findall(r'wire:model(?:\.[\w.]+)?="(\w+)', blade))
    unbound = sorted(bound - props)
    check(not unbound, "jedes wire:model existiert als Property (%d)" % len(bound), ", ".join(unbound))

    # Livewire haengt wire:id an das ERSTE Element, das die Ansicht ausgibt.
    # Alles, was vor der Seitenkomponente gerendert wird, wird zur Wurzel, und
    # jedes wire:model und wire:click der echten Seite landet ausserhalb davon
    # und hoert still auf zu funktionieren.
    head = blade[: blade.index("<x-filament-panels::page>")]
    stray = re.sub(r"@php.*?@endphp|\{\{--.*?--\}\}|\s", "", head, flags=re.S)
    check(not stray, "nichts rendert vor der Wurzelkomponente", "gefunden: " + stray[:60])

    # Formular und Speicher muessen sich ueber die Feldnamen einig sein, sonst
    # landet ein gespeicherter Wert in einem Schluessel, den niemand liest.
    store = (ROOT / "src/Services/StateStore.php").read_text()
    block = store[store.index("AUTO_DEFAULTS"):store.index("AUTO_MIN")]
    defaults = set(re.findall(r"^\s+'(\w+)' => ", block, re.M))
    form = set(re.findall(r'wire:model(?:\.\w+)?="auto\.(\w+)"', blade))
    # mod_sources ist eine Abbildung, kein Einzelwert - es steht absichtlich
    # nicht in AUTO_DEFAULTS, sondern wird in modSources() geprueft.
    strays = sorted(f for f in form if f not in defaults and f != "mod_sources")
    check(not strays, "jedes Einstellfeld existiert in AUTO_DEFAULTS (%d)" % len(form), ", ".join(strays))

    # Jedes Ergebnis, das der Speicher durchlaesst, muss eine Uebersetzung
    # haben - die Ansicht baut den Schluessel zur Laufzeit zusammen, ein
    # fehlender faellt also erst auf, wenn genau dieses Ergebnis auftritt.
    outcomes = re.search(r"in_array\(\$entry\['outcome'\] \?\? '', \[(.*?)\]", store, re.S)
    listed = re.findall(r"'([\w-]+)'", outcomes.group(1)) if outcomes else []
    missing_out = sorted(o for o in listed if "outcome." + o not in de)
    check(not missing_out, "jedes erlaubte Ergebnis hat eine Uebersetzung (%d)" % len(listed),
          ", ".join(missing_out))

    # Jeder Profilschluessel, den der Code fest erwartet, muss existieren.
    config = (ROOT / "config/mod-auto-restart.php").read_text()
    profiles = re.findall(r"^        '([a-z0-9-]+)' => \[", config, re.M)
    check("generic" in profiles, "Profil 'generic' existiert (Rueckfall)", ", ".join(profiles))

    # Jedes Profil mit Quellen braucht fuer jede Quelle eine page_url, sonst
    # steht in der Historie ein Modname ohne Link statt eines Links.
    for block_match in re.finditer(r"^        '([a-z0-9-]+)' => \[(.*?)^        \],", config, re.M | re.S):
        name, body = block_match.group(1), block_match.group(2)
        srcs = set(re.findall(r"'(\w+)' => 'https://", body.split("'sources' => [")[1].split("],")[0])) \
            if "'sources' => [" in body else set()
        urls = set(re.findall(r"'(\w+)' => 'https://", body.split("'page_url' => [")[1].split("],")[0])) \
            if "'page_url' => [" in body else set()
        check(srcs <= urls, "Profil %s: jede Quelle hat eine page_url" % name,
              "ohne URL: %s" % sorted(srcs - urls))

    print()
    if FAILED:
        sys.exit("ERGEBNIS: %d Pruefung(en) fehlgeschlagen" % len(FAILED))
    print("ERGEBNIS: alles ok")


if __name__ == "__main__":
    main()
