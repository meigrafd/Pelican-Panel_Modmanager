# Änderungen

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
