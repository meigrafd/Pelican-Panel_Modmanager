# Änderungen

## 0.5.5

- **Mods in der Liste verlinkt.** Der Name führt auf die Seite des Mods im
  Repository, aus dem es geprüft wird: die Ausnahme pro Mod, sonst die
  globale Quelle. Öffnet in einem neuen Tab. Nicht verfolgte Mods bleiben
  ohne Link, weil ohne Autor keine Seite bestimmbar ist.

## 0.5.4

- **Neuer Knopf „Neustart mit Vorwarnung“.** Löst denselben Ablauf aus wie
  ein erkanntes Update: erste Warnung mit der eingestellten Vorwarnzeit,
  Backup, Warnung eine Minute vorher, Countdown, Neustart, Kontrolle der
  Rückkehr, Willkommensnachricht. In der Historie steht „Von Hand durch …“
  mit dem Ergebnis „bestätigt“, sobald der Server zurück ist. Läuft auch bei
  ausgeschaltetem Auto-Neustart. Solange gewarnt wird, lässt er sich
  abbrechen. Braucht den Scheduler des Panels.
- **„Jetzt neu starten“ heißt jetzt „Sofort neu starten“**, und der
  Bestätigungsdialog sagt deutlich: ohne Vorwarnung, ohne Countdown. Der
  Knopf tat schon immer genau das, sah aber nach mehr aus.
- Tests für den geplanten Neustart: läuft bei ausgeschaltetem Auto-Neustart,
  kein zweiter Plan während der Warnung, Abbruch, Bestätigung nach der
  Rückkehr.

## 0.5.3

- **Englische Oberfläche vollständig übersetzt.** Die englische Sprachdatei
  war zu zwei Dritteln eine deutsche Kopie; auf einem englischen Panel stand
  „zuletzt geprueft 8 hours ago“.
- **„Jetzt prüfen“ schreibt in die Statuszeile.** Bisher zeigte sie nur den
  letzten Lauf des Schedulers, eine Prüfung von Hand blieb unsichtbar. Die
  Phase eines laufenden Neustarts bleibt dabei unangetastet.
- **„Auto-Neustart aus“ in der Statuszeile**, wenn er aus ist. Ein alter
  Zeitstempel sah sonst nach laufender Überwachung aus.
- **Neue Konfigurationsdateien erscheinen sofort.** Die Liste lag zehn
  Minuten im Cache; nach einem Serverstart, der Dateien anlegt, zeigte die
  Seite bis dahin die alte Liste. Jetzt wird das Verzeichnis bei jedem Aufruf
  gelesen, nur die Kopfzeilen liegen im Cache, je Datei unter Größe und
  Änderungszeit.
- Der RCON-Hinweis nennt jetzt Vorgabe und tatsächlichen Port: „Vorgabe des
  Mods ist Spielport + 2, hier also 2458.“

## 0.5.2

- **Die Seite lädt nach „entfernen“, „installieren“ und „Speichern“ neu.**
  Filament baut das Formular je Anfrage einmal, und zwar beim Suchen der
  angeklickten Aktion, also vor ihrer Wirkung. Eine gelöschte Mod stand
  deshalb bis zum nächsten Klick weiter in der Liste. Die Meldung überlebt
  die Weiterleitung.
- **Kein `optimize:clear` mehr nach einem Update.** Pelican leert beim
  Install und Update nur die Filament-Komponenten; kompilierte Views und ein
  gecachter Config-Stand blieben liegen (daher der Fehler „Undefined variable
  $crossplay“ nach 0.4.1). Das Plugin merkt sich jetzt die zuletzt gesehene
  Version und räumt bei einer Abweichung einmal selbst auf: Views leeren, und
  falls das Panel mit gecachter Konfiguration läuft, den Cache neu bauen.
  Schlägt das fehl, steht eine Warnung mit dem Hinweis auf `optimize:clear`
  im Log.

## 0.5.1

- **Mods mit `plugins/`-Aufbau fehlten nach der Installation in der Liste**
  (YamlDotNet, Jotunn). Ihre `manifest.json` liegt neben `plugins/`, nicht
  darin, und wurde nicht mitkopiert. Der Scanner fand deshalb keine Version
  und ließ den Ordner ganz weg; das Mod war installiert, aber unsichtbar und
  unüberwacht. Die Datei wird jetzt mitgenommen. Schon installierte Mods
  dieser Art einmal neu installieren, dann sind sie verfolgt.
- **Ordner ohne `manifest.json` werden angezeigt**, als „nicht verfolgt“.
  Vorher fehlten sie stillschweigend, was wie ein Fehler der Installation
  aussah.
- Spaltenüberschriften der Mod-Liste standen ab der zweiten Zeile als
  „Mod name 1“ da. Ein leerer Text reicht Filament nicht, es baut dann einen
  aus dem Feldnamen. Jetzt ausgeblendet.

## 0.5.0

Mods lassen sich jetzt über die Seite konfigurieren.

- **Konfigurations-Editor.** Neuer Abschnitt „Konfiguration der Mods“: Datei
  aus `BepInEx/config` wählen, Formular bearbeiten, speichern. Das Formular
  entsteht aus der Datei selbst, denn BepInEx schreibt zu jedem Wert
  Beschreibung, Typ, Vorgabe und erlaubte Werte als Kommentar. Schalter für
  Boolean, Auswahl für Aufzählungen, Textfeld für Zahlen und Text, mit
  Vorgabe und erlaubtem Bereich als Hilfetext. Kein Mod wird beim Namen
  gekannt.
- **Zurückgeschrieben wird nur die Wertzeile.** Kommentare, Reihenfolge,
  Leerzeilen und Zeilenenden bleiben Byte für Byte erhalten; die Tests prüfen
  genau das. Ein Mod, das seine Datei beim Start selbst anfasst, findet sie
  so vor, wie es sie hinterlassen hat.
- **Prüfung vor dem Schreiben.** Ganze Zahl, Zahl im erlaubten Bereich,
  erlaubter Aufzählungswert. Bei Fehlern wird nichts geschrieben, die Meldung
  nennt Feld und Grund.
- **Knopf an der Mod-Zeile.** Lässt sich eine Datei einem installierten Mod
  zuordnen (Plugin-Name aus der Kopfzeile gegen den Paketnamen), steht neben
  „entfernen“ ein Knopf „konfigurieren“, der die Datei direkt öffnet.
- Profile haben dafür `loader.config`; alle BepInEx-Spiele sind eingetragen.
- Neue Testdatei `tests/ConfigTest.php`, in `lint.sh` als vierte Stufe.

Nicht enthalten: Konfigurationsdateien anlegen. Sie entstehen beim ersten
Start des Servers mit dem Mod; vorher gibt es nichts zu bearbeiten.

## 0.4.3

BepInEx als Abhängigkeit, wenn es das Egg schon installiert hat.

- **Vorhandener Modlader wird erkannt.** Der Scanner prüft, ob `BepInEx/core`
  liegt, auch ohne `manifest.json`. Die Seite und `mar:check` zeigen es an.
- **Abhängigkeit gilt als erfüllt.** Nennt ein Mod `BepInExPack…` als
  Abhängigkeit und der Lader ist da, überspringt der Plan es mit dem Hinweis
  „Version unbekannt“. Vorher stand BepInExPack in jedem Plan, und die
  Installation brach mit „destination already exists“ ab, weil `BepInEx/`
  schon lag. Ausdrücklich per URL wird es weiterhin installiert.
- **Installation ins Serververzeichnis führt zusammen statt zu verschieben.**
  Ordner werden angelegt, nie gelöscht; Dateien einzeln ersetzt. `plugins/`
  mit den Mods bleibt unberührt, ebenso vorhandene Dateien in
  `BepInEx/config/`, damit ein Update die Einstellungen nicht überschreibt.
- Die `manifest.json` solcher Pakete landet als Marker unter
  `BepInEx/plugins/<Autor-Paket>/`, ein Ordner ohne DLL. Damit kennt der
  Scanner Namen und Version, Updates des Laders werden erkannt, und „kein
  Herunterstufen“ gilt auch für ihn. „Entfernen“ löscht nur diesen Marker; der
  Lader selbst bleibt liegen.
- Profile haben dafür einen Block `loader` (Marker-Ordner und Paket-Präfixe).
  Alle BepInEx-Spiele sind eingetragen.
- Tests: Zusammenführungsplan gegen einen nachgebauten Server, Lader-Erkennung
  am Paketnamen.

## 0.4.2

- Nachrichtenfelder ließen sich nicht leeren: Feld geleert, gespeichert, und
  die Vorgabe stand wieder da. Filament liefert für ein geleertes Textfeld
  `null`, der Speicher nahm aber nur Zeichenketten an und ließ `null` liegen.
  `null` gilt jetzt wie ein leerer Text, also „nichts sagen". Betroffen waren
  alle vier Nachrichten.
- Test dafür in `tests/PhaseTest.php`. Der Speicher war dort bisher nicht
  abgedeckt, weil die Phasen-Tests ihn durch einen Ersatz austauschen.

## 0.4.1

Beim Umbau auf Filament-Bausteine in 0.4.0 sind zwei Dinge stillschweigend
weggefallen. Beides ist zurück.

- **Quellenauswahl pro Mod** war verschwunden. Sie ist wieder da, jetzt als
  echtes Auswahlfeld in der Mod-Zeile. Leer heißt weiterhin „wie oben".
- **Die Mod-Tabelle** war zu einer flachen Textliste geworden. Wieder mit
  Spalten: Mod, Version, Quelle, geprüft, Löschen.
- Hinweis, wenn ein Spiel nur eine Mod-Quelle hat, ist wieder da.
- `check.py` prüft jetzt auf verwaiste Übersetzungsschlüssel. Genau dieser Test
  hat den Verlust gefunden: Eine Funktion, die aus der Oberfläche verschwindet,
  lässt ihre Übersetzung zurück — ohne Fehler und ohne fehlgeschlagenen Test.

## 0.4.0

Die Oberfläche neu gebaut — aus Filament-Bausteinen statt aus eigenem HTML.

Vorher waren die Formularfelder handgeschriebenes HTML mit Tailwind-Klassen. Das
sieht im Quelltext richtig aus, wirkt aber nicht: Ein Filament-Panel kompiliert
sein CSS vorab und nimmt nur die Klassen auf, die es selbst benutzt. Klassen, die
nur in einem Plugin vorkommen, fehlen darin. Die Folge war eine Seite, auf der
alle Felder ungestylt untereinander standen — ohne dass irgendwo ein Fehler
auftauchte.

- Die ganze Seite ist jetzt ein Filament-Schema: `Section`, `Fieldset`, `Grid`,
  `Select`, `TextInput`, `Toggle`, `TextEntry`, `Action`. Spalten, Abstände,
  Dunkelmodus und Zusammenklappen kommen damit vom Panel.
- Knöpfe sind Filament-Actions mit Bestätigungsdialog statt `wire:confirm`.
- Das Blade-Gerüst enthält nur noch die Seitenkomponente und das Formular.
- `check.py` wacht darüber: es lehnt eigenes Formular-HTML im Gerüst ab und
  prüft, dass jedes `->action()` eine Methode hat und jede gespeicherte
  Einstellung ein Eingabefeld — und umgekehrt.

## 0.3.0

Mod-Verwaltung dazu. Damit deckt das Plugin den ganzen Weg ab: Mod finden,
prüfen, installieren, überwachen, bei Updates neu starten.

- **Installieren über URL**: Thunderstore- oder Hexium-Link einfügen, oder
  `Autor-Paket` tippen. Abhängigkeiten werden aufgelöst und in der richtigen
  Reihenfolge mitinstalliert.
- **Zweistufig**: erst Plan anzeigen, dann auf einen zweiten Knopf hin
  ausführen. Eine falsch kopierte URL fällt so auf, bevor Dateien liegen.
- **Kein Herunterstufen**: was schon in einer neueren Fassung installiert ist,
  bleibt liegen.
- **Aufbau-Erkennung** für die drei Paketformen, die es in der Praxis gibt:
  flach, `plugins/`-Unterordner, und ganzer Baum ins Serververzeichnis
  (BepInEx selbst).
- **Entfernen** prüft vorher, ob eine andere installierte Mod davon abhängt.
- **Kompatibilitätshinweise** aus beiden Quellen: Hexiums Tag „Valheim 1.0",
  Thunderstores „Client-side"/„Server-side", zurückgezogene Pakete, und wie alt
  ein Mod gegenüber dem aktuellen Spiel-Build ist.
- GitHub-Action, die bei einem Tag das Zip baut und `update.json` setzt –
  danach gibt es im Panel einen Update-Knopf.
- `update_url` in `plugin.json`, damit Pelican Updates überhaupt bemerkt.
- MIT-Lizenz beigelegt (fehlte vorher, obwohl das Plugin auf einem
  MIT-lizenzierten Original aufbaut).
- Testsuite: 74 Tests ohne Panel (38 Neustart-Logik, 36 Installation).

## 0.2.0

Spielunabhängig gemacht. Alles Spielspezifische steht jetzt als Daten in
`config/mod-auto-restart.php`; der Code kennt kein Spiel beim Namen.

- Spielprofile: Valheim, V Rising, Core Keeper, Sunkenland, Project Zomboid
  (nur Spiel-Updates) und ein generisches. Alle Steam-App-IDs gegen die
  Steam-API geprüft.
- Steam-App-ID, Mod-Ordner und Profil sind pro Server einstellbar.
- Mod-Quelle wählbar: Thunderstore oder Hexium, global oder als Ausnahme pro
  Mod. Immer nur eine Quelle je Mod — dieselbe Mod hat in zwei Repositorys oft
  verschiedene Versionsnummern.
- Drei Ansagewege: RCON, Panel-Konsole, oder gar keiner.
- Probelauf (`MAR_DRY_RUN=true`): alles außer dem Neustart.
- `php artisan mar:check` zeigt, was das Plugin sieht, ohne etwas zu tun.
- Zweite Ebene Fehlerfang in `tick()`, damit ein Fehler beim Lesen der
  Serverliste nicht den Scheduler des Panels mitreißt.

## 0.1.0

Erste Portierung des Auto-Neustart-Teils von pz-mod-manager auf Valheim.

- Thunderstore statt Steam Workshop als Quelle der Versionsnummern.
- Mod-Erkennung über `BepInEx/plugins/<Autor>-<Paket>/manifest.json`.
- Warnungen über ValheimRcon (`showMessage`) statt `servermsg`.
- Kontrolle nach dem Neustart liest Dateien statt RCON.

### Was aus pz-mod-manager NICHT übernommen wurde

Die Mod-Verwaltung selbst: Mods hinzufügen, aktivieren, deaktivieren, löschen,
Ladereihenfolge sortieren. Das hing komplett am Steam Workshop und an der
`servertest.ini` von Project Zomboid und war nicht portierbar, sondern hätte
neu gebaut werden müssen. Dieses Plugin beobachtet Versionen und startet neu —
es verwaltet keine Mods.
