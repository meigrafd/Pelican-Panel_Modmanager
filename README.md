# Mod Watch & Auto-Restart

Ein Plugin für das [Pelican Panel](https://pelican.dev). Es verwaltet Mods von **Thunderstore** und **Hexium** auf Steam-Spielservern und startet den Server automatisch neu, wenn eine Mod oder das Spiel aktualisiert wurde.

Aufgebaut auf dem Auto-Neustart-Teil von [pz-mod-manager](https://github.com/WildBrianNL/pz-mod-manager) (MIT), aber spielunabhängig und für Thunderstore statt Steam Workshop.

---

## Das Problem

Bei fast allen moddbaren Spielen müssen Client und Server dieselbe Version haben. Aktualisiert sich eine Mod oder das Spiel im laufenden Betrieb, hält der Server die alten Dateien und jeder Client die neuen: wer schon drin ist, merkt nichts, wer neu verbinden will, kommt nicht rein. Der Server sieht dabei kerngesund aus.

Dazu kommt das zweite Problem: Wer Mods lokal mit Gale pflegt und den Server von Hand bestückt, hat irgendwann zwei verschiedene Modsätze.

---

## Was es kann

**Mods installieren**
- URL von Thunderstore oder Hexium einfügen, oder `Autor-Paket` tippen
- Abhängigkeiten werden aufgelöst und mitinstalliert, in der richtigen Reihenfolge
- Vor dem Installieren siehst du den kompletten Plan – nichts passiert auf einen Klick
- Kein Herunterstufen: was schon in einer neueren Fassung liegt, bleibt liegen
- Entfernen prüft vorher, ob eine andere installierte Mod davon abhängt

**Mods konfigurieren**
- Die Konfigurationsdateien der Mods aus `BepInEx/config` als Formular bearbeiten
- Beschreibung, Vorgabe und erlaubte Werte kommen aus der Datei selbst; kein Mod muss dem Plugin bekannt sein
- Gespeichert wird nur der Wert, der Rest der Datei bleibt unangetastet

**Kompatibilität prüfen**
- Vom Autor zurückgezogene Pakete werden als solche markiert
- Hexiums Tag „Valheim 1.0" wird ausgewertet, wenn vorhanden
- Thunderstores „Client-side"/„Server-side" wird ausgewertet
- Beide Quellen werden für Hinweise befragt, auch wenn nur aus einer installiert wird

**Automatisch neu starten**
- Prüft regelmäßig, ob eine installierte Mod oder der Spiel-Build neuer ist
- Warnt die Spieler im Spiel, sichert die Welt, zählt herunter, startet neu
- Prüft danach, ob das Update wirklich angekommen ist – wenn nicht, schaltet es sich ab
- Historie der letzten zwanzig Neustarts mit Versionen, Grund und Ergebnis

---

## Unterstützte Spiele

| Profil | Steam-App | Mod-Quellen | Ansage an Spieler |
|---|---|---|---|
| Valheim | 896660 | Thunderstore, Hexium | RCON über ValheimRcon |
| V Rising | 1829350 | Thunderstore | — |
| Core Keeper | 1963720 | Thunderstore | — |
| Sunkenland | 2667530 | Thunderstore, Hexium | — |
| Project Zomboid | 380870 | — (nur Spiel-Updates) | Panel-Konsole |
| Anderes Spiel | selbst eintragen | Thunderstore | — |

Alles Spielspezifische steht als Daten in `config/mod-auto-restart.php`. Ein neues Spiel ist ein Eintrag dort, kein Code. Steam-App-ID und Mod-Ordner lassen sich außerdem pro Server auf der Seite überschreiben.

---

## Installation

Ausführlich, mit Bildschirmweg und Fehlersuche: **[EINBAU.md](EINBAU.md)**
Gefahrlos testen, in fünf Stufen: **[INSTALL.md](INSTALL.md)**

Kurzfassung:

**Über die Panel-Oberfläche** — Zip aus den [Releases](../../releases) laden, im Panel unter Admin → Plugins auf **Import**, dann **Install**.

**Von Hand:**
```bash
cd /var/www/pelican/plugins
git clone https://github.com/meigrafd/Pelican-Panel_Modmanager.git mod-auto-restart
cd /var/www/pelican
php artisan p:plugin:install
php artisan optimize:clear
```

Der Zielordner am Ende des `git clone` ist **nicht optional**. Pelican verlangt, dass der Ordnername der `id` aus `plugin.json` entspricht, und die lautet `mod-auto-restart` – nicht wie das Repository. Ohne den Zusatz heißt der Ordner `Pelican-Panel_Modmanager`, und Pelican ignoriert das Plugin wortlos.

Dasselbe gilt für den Zip-Download über den grünen Code-Knopf: der packt einen Ordner mit `-main` am Ende. Für den Weg über die Oberfläche nimm das Zip aus den Releases, nicht den Code-Knopf.

**Voraussetzung:** Die Egg-Variable `AUTO_UPDATE` muss auf 1 stehen. Ohne sie lädt ein Neustart nichts nach, und das Plugin verweigert deshalb den Dienst.

---

## Updates

`plugin.json` enthält eine `update_url`. Pelican prüft sie beim Öffnen der Plugin-Liste und bietet einen Update-Knopf an, sobald eine neuere Version vorliegt.

Zum Veröffentlichen genügt:

```bash
# Version in plugin.json hochzählen, CHANGELOG ergänzen
git tag v0.3.1
git push --tags
```

Die GitHub-Action (`.github/workflows/release.yml`) baut daraus das Zip, hängt es ans Release und schreibt `update.json` auf die neue Version. Im Panel erscheint der Update-Knopf.

Sie bricht ab, wenn der Tag nicht zur Version in `plugin.json` passt – Pelican vergleicht die Version im Plugin, nicht den Tag, und ein Release mit abweichender Nummer würde nie als Update erkannt.

Das fertige Zip gehört **nicht** ins Repository. Die Action baut es bei jedem Tag; ein eingecheckter Stand veraltet mit dem ersten Commit danach, bleibt aber herunterladbar – und genau das merkt niemand.

---

## Entwicklung

```bash
./lint.sh
```

Läuft in drei Stufen:

1. **PHP-Syntax** aller Dateien. Ein Plugin, das nicht parst, nimmt das Panel mit einer weißen Seite runter.
2. **`check.py`** – was `php -l` nicht findet: fehlende Übersetzungsschlüssel, `wire:click` auf Methoden, die es nicht gibt, Einstellfelder, die nirgends gelesen werden. Diese Fehler werfen keine Ausnahme; die Seite rendert, und es fällt erst auf, wenn jemand klickt.
3. **141 Tests** ohne Panel, ohne Datenbank, ohne Spielserver:
   - `tests/PhaseTest.php` (47) – die Neustart-Logik. Vor allem: wann *nicht* neu gestartet wird.
   - `tests/InstallTest.php` (52) – Aufbau-Erkennung an echten Paketen, Abhängigkeiten, Kompatibilitätshinweise.
   - `tests/ConfigTest.php` (42) – BepInEx-Konfigurationsdateien lesen, prüfen und Byte-genau zurückschreiben.

---

## Entscheidungen, die man kennen sollte

**Eine Quelle je Mod, nie beide.** Dieselbe Mod hat in zwei Repositorys oft verschiedene Versionsnummern – AzuClock steht auf Thunderstore als 1.0.5 (März 2025) und auf Hexium als 1.1.0 (September 2026). Gemischt startete der Server zwischen beiden im Kreis neu. Hinweise dürfen aus beiden Quellen kommen, Versionsnummern nicht.

**Abhängigkeiten sind Untergrenzen, keine Festlegungen.** Im Manifest stehen sie exakt versioniert, aber Jewelcrafting fordert BepInEx 5.4.2333 und läuft mit 5.4.2350. Wörtlich genommen ließe sich kaum ein Satz aus mehreren Mods auflösen. Installiert wird die neueste Fassung, heruntergestuft wird nie.

**Ungleich, nicht „neuer als".** Ein zurückgezogenes Update würde sonst einen Neustart auslösen, der die ältere Version nie herbeiführen kann – danach schaltet sich das Plugin selbst ab.

**Der fehlende 1.0-Tag ist kein Verdacht.** Von 844 Paketen bei Hexium tragen 280 den Tag „Valheim 1.0". Aber 202 Pakete ohne den Tag wurden trotzdem nach dem 1.0-Release aktualisiert – die Autoren haben ihn nur nicht gesetzt. Tag vorhanden ist ein starkes Ja; Tag fehlend sagt fast nichts und löst deshalb keine Warnung aus.

**Aus einem Fehlschlag wird nie etwas gefolgert.** Repository nicht erreichbar, Mod-Ordner nicht lesbar, Spielerzahl unbekannt: das heißt jedes Mal „keine Information", und darauf hin wird nichts neu gestartet. Eine ausgefallene Quelle reißt die andere nicht mit.

**Der Ansageweg darf ausfallen.** Er hängt bei Valheim an einem Mod, und genau dieses Mod bricht bei Spiel-Updates. Fällt es aus, gehen die Warnungen verloren und der Neustart läuft trotzdem – sonst blockierte ein kaputtes Mod ausgerechnet den Neustart, der es repariert. In der Historie steht dann „ohne Vorwarnung".

**Eine echte Kompatibilitätsprüfung gibt es nicht.** Kein Repository hat ein Feld für die Spielversion. Was hier steht, sind Indizien. Blockiert wird nur bei einer Tatsache: Der Autor hat das Paket zurückgezogen.

---

## Was es nicht kann

- **Ladereihenfolge sortieren.** BepInEx lädt alphabetisch; Mods mit harten Reihenfolgeanforderungen müssen von Hand nachgezogen werden.
- **Client-Seite bedienen.** Dafür bleibt Gale zuständig. Das Plugin sorgt dafür, dass der Server aktuell ist, nicht dein PC.
- **Mods finden.** Es gibt keine Suche – du gibst eine URL ein. Zum Stöbern sind die Webseiten der Repositorys da.
- **Konfigurationsdateien anlegen.** Der Editor bearbeitet, was das Mod beim ersten Start geschrieben hat. Vorher gibt es nichts.
- **Garantieren, dass eine Mod läuft.** Siehe oben: die Information dafür existiert nirgends.

---

## Lizenz

MIT. Siehe [LICENSE](LICENSE).

Diese Software baut auf [pz-mod-manager](https://github.com/WildBrianNL/pz-mod-manager) von WildBrianNL auf. Der Aufbau der Phasenlogik, der Zustandsspeicher, die Teststruktur und `check.py` stammen von dort.
