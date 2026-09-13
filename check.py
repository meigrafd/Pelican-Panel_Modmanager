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

    # Die Seite wird als Filament-Schema gebaut, nicht als eigenes HTML. Ein
    # Rueckfall auf handgeschriebene Tailwind-Klassen faellt optisch auf, aber
    # erst am fertigen Panel - deshalb hier eine harte Grenze: das Geruest darf
    # ausser der Seitenkomponente und dem Formular nichts enthalten.
    stray_html = re.findall(r"<(input|select|table|textarea|button)\b", blade)
    check(not stray_html, "das Blade-Geruest enthaelt kein eigenes Formular-HTML",
          ", ".join(sorted(set(stray_html))))
    check("{{ $this->form }}" in blade, "das Blade-Geruest rendert das Schema")

    # Nur feste Schluessel. Was per Verkettung gebaut wird, wird unten ueber
    # das Praefix geprueft - das Suffix steht nicht im Quelltext.
    used = set(re.findall(r"trans(?:_choice)?\('mar::messages\.([a-z_.]+)'\)", blade + page))
    used |= set(re.findall(r"trans(?:_choice)?\('mar::messages\.([a-z_.]+)',", blade + page))
    missing = sorted(k for k in used if k not in de and not k.endswith("."))
    check(not missing, "jeder feste Uebersetzungsschluessel existiert (%d)" % len(used),
          ", ".join(missing[:6]))

    # Verwaiste Schluessel: eine Funktion, die aus der Oberflaeche verschwindet,
    # hinterlaesst ihre Uebersetzung. Beim Umbau auf Filament-Bausteine sind auf
    # diese Weise die Quellenauswahl pro Mod und die Mod-Tabelle stillschweigend
    # weggefallen - ohne Fehler, ohne fehlgeschlagenen Test.
    all_src = (page + blade
               + "".join(p.read_text() for p in (ROOT / "src").rglob("*.php")))
    used_all = set(re.findall(r"mar::messages\.([a-z_.]+)", all_src))
    orphans = sorted(k for k in de
                     if k not in used_all
                     and not any(k.startswith(pre) for pre in
                                 re.findall(r"mar::messages\.([a-z_.]+\.)'\s*\.", all_src)))
    check(not orphans, "kein Uebersetzungsschluessel ist verwaist (%d)" % len(de),
          ", ".join(orphans[:8]))

    # Dynamische Schluessel: 'mar::messages.outcome.' . $outcome
    prefixes = set(re.findall(r"'mar::messages\.([a-z_.]+\.)'\s*\.", blade + page))
    for prefix in sorted(prefixes):
        check(any(k.startswith(prefix) for k in de), "Praefix %s hat Uebersetzungen" % prefix)

    # Jedes ->action('name') muss eine oeffentliche Methode der Seite sein.
    # Ein Tippfehler wirft keine Ausnahme beim Rendern - der Knopf erscheint
    # und tut beim Klick nichts.
    methods = set(re.findall(r"public function (\w+)\(", page))
    called = set(re.findall(r"->action\('(\w+)'\)", page))
    ghosts = sorted(called - methods)
    check(not ghosts, "jedes ->action() existiert als Methode (%d)" % len(called), ", ".join(ghosts))

    # Formular und Speicher muessen sich ueber die Feldnamen einig sein, sonst
    # landet ein gespeicherter Wert in einem Schluessel, den niemand liest.
    store = (ROOT / "src/Services/StateStore.php").read_text()
    block = store[store.index("AUTO_DEFAULTS"):store.index("AUTO_MIN")]
    defaults = set(re.findall(r"^\s+'(\w+)' => ", block, re.M))
    # Nur echte Eingabefelder. TextEntry ist Anzeige und hat im Speicher nichts
    # verloren; 'watch' und 'add_input' sind absichtlich nur Oberflaeche und
    # werden in toAuto() wieder entfernt.
    form = set(re.findall(r"(?:TextInput|Select|Toggle|Textarea)::make\('(\w+)'\)", page))
    surface = {"watch", "add_input", "config_file"}
    strays = sorted(f for f in form - surface if f not in defaults)
    check(not strays, "jedes Eingabefeld existiert in AUTO_DEFAULTS (%d)" % len(form), ", ".join(strays))

    # Und andersherum: was gespeichert wird, aber nirgends bedienbar ist, ist
    # entweder ein vergessenes Feld oder ein toter Eintrag im Speicher.
    internal = {"check_mods", "check_game", "mod_sources"}
    missing_field = sorted(d for d in defaults - form - internal)
    check(not missing_field, "jede Einstellung hat ein Eingabefeld (%d)" % len(defaults),
          ", ".join(missing_field))

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

    # Klassen, die eine Datei benutzt, ohne sie zu importieren. PHP loest den
    # Namen dann im eigenen Namensraum auf, php -l merkt nichts, und die
    # Seite stirbt erst beim Rendern: "Target class [...\Pages\RegistryClient]
    # does not exist". Genau so ist 0.5.5 auf dem Panel gestorben.
    unimported = []
    for php in sorted((ROOT / "src").rglob("*.php")):
        src = php.read_text()
        used = set(re.findall(r"app\((\w+)::class\)", src)) | set(re.findall(r"\bnew (\w+)\(", src))
        imported = set(re.findall(r"^use [\w\\]+\\(\w+)(?: as \w+)?;", src, re.M))
        local = {p.stem for p in php.parent.glob("*.php")}
        for name in sorted(used - imported - local - {"self", "static", "class"}):
            unimported.append("%s: %s" % (php.relative_to(ROOT).as_posix(), name))
    check(not unimported, "jede Klasse ist importiert, wo sie benutzt wird", "; ".join(unimported[:4]))

    print()
    if FAILED:
        sys.exit("ERGEBNIS: %d Pruefung(en) fehlgeschlagen" % len(FAILED))
    print("ERGEBNIS: alles ok")


if __name__ == "__main__":
    main()
