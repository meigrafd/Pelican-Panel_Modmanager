# Installieren und testen, ohne das Panel zu zerlegen

## Installation

Der Ordnername **muss** der `id` aus `plugin.json` entsprechen: `mod-auto-restart`. Das ist die häufigste Fehlerquelle – ein Ordner `mod-auto-restart-main` aus einem GitHub-Zip wird nicht geladen.

**Über die Oberfläche:** Ordner zippen, im Panel unter Plugins auf **Import**, danach auf **Install**.

**Von Hand:**
```bash
cd /var/www/pelican/plugins
# Ordner hierher kopieren, dann:
cd /var/www/pelican
php artisan p:plugin:install      # fragt interaktiv, welches Plugin
php artisan optimize:clear
```

Docker-Compose-Setup:
```bash
docker cp mod-auto-restart pelican-panel-1:/var/www/html/plugins/
docker exec -it pelican-panel-1 php artisan p:plugin:install
docker exec pelican-panel-1 php artisan optimize:clear
```

Installieren, Aktualisieren und Deinstallieren laufen im Hintergrund über die Queue. Passiert minutenlang nichts, läuft der Queue-Worker nicht – das ist dann das eigentliche Problem, nicht das Plugin.

**Wieder runter:** Uninstall-Knopf in der Plugin-Liste oder `php artisan p:plugin:uninstall`, dann `optimize:clear`.

## Was schiefgehen kann, und was nicht

Ehrlich zur Lage: Pelican bezeichnet sein Plugin-System selbst als noch in Entwicklung. Ein Plugin läuft im selben Prozess wie das Panel – ein Fehler in der Seitenklasse kann die Oberfläche lahmlegen, nicht nur die eigene Seite.

| Risiko | Wie abgesichert |
|---|---|
| Plugin startet ungefragt Server neu | `enabled` ist pro Server aus. Eine frische Installation startet nichts neu, egal wie viele Server auf dem Panel liegen. |
| Fehler reißt den Scheduler mit | Zwei Ebenen Fehlerfang: eine um jeden Server, eine um das Zusammenstellen der Serverliste. Ein Plugin darf höchstens sich selbst kaputtmachen. |
| Seite wirft Fehler → weißes Panel | Nicht im Plugin abfangbar. Deshalb der Rückweg unten. |
| Registry-Ausfall löst Neustarts aus | Ein Fehlschlag liefert `null` und gilt als „keine Information". Getestet. |

**Rückweg, wenn die Oberfläche nicht mehr geht:**
```bash
cd /var/www/pelican
mv plugins/mod-auto-restart /root/    # aus dem Weg schieben
php artisan optimize:clear
```
Das reicht, weil der Panel-Kern nicht angefasst wird. Vorher ein Datenbank-Backup zu ziehen kostet nichts und nimmt dem Ganzen den Schrecken.

## Testen in fünf Stufen

Jede Stufe ist folgenlos, solange die vorige sauber war.

**1. Nur installieren.** Alles bleibt aus. Die Seite ansehen: Wird das richtige Profil erkannt? Steht die richtige Steam-App da? Werden die Mods gefunden? Wenn hier schon Unsinn steht, hört es hier auf.

**2. Von der Kommandozeile prüfen.** Startet nichts neu, ist rein lesend:
```bash
php artisan mar:check              # alle Server
php artisan mar:check Vanguards    # nur einer, nach Name oder Id
```
Zeigt Profil, App-Id, Mod-Ordner, Quellen, Ansageweg und das Ergebnis eines echten Vergleichs. Das ist genau der Aufruf, den auch der Scheduler macht – nur ohne alles, was danach käme. Steht dort „würde einen Neustart auslösen", weißt du das, bevor es passiert.

**3. RCON testen.** Auf der Seite Passwort und Port eintragen, dann **Ansage testen**. Es kommt eine echte Nachricht im Spiel an. Klappt das nicht, würden später die Warnungen fehlen – der Neustart liefe trotzdem.

**4. Probelauf.** Panelweiter Schalter in der `.env`:
```
MAR_DRY_RUN=true
```
Danach `php artisan optimize:clear`. Jetzt läuft alles echt – Erkennung, Warnung, Backup, Historie – nur der Neustart wird unterdrückt und als „Probelauf" vermerkt. Ein paar Tage so laufen lassen und die Historie lesen: Hätte es zum richtigen Zeitpunkt und aus dem richtigen Grund neu gestartet?

Achtung: die Warnungen gehen dabei wirklich an die Spieler raus. Wer das nicht will, lässt die Nachrichtenfelder leer.

**5. Scharf schalten.** `MAR_DRY_RUN` raus, und auf **einem** Server einschalten – am besten einem, auf dem gerade niemand spielt. Erst wenn dort ein Neustart sauber durchgelaufen und bestätigt ist, kommen die anderen dran.

## Das saubere Verfahren

Ein zweites Panel als Wegwerf-Instanz. Pelicans Docker-Compose-Setup macht das billig: eigener Port, eigene SQLite-Datei, eigenes Wings. Dort kann nichts kaputtgehen, was weh tut.

Der Haken: ohne angebundenes Wings und einen echten Server lassen sich die interessanten Teile – Dateien lesen, Backups, Neustart – gar nicht testen. Ein Testpanel ohne Testserver prüft nur, ob die Seite rendert. Das ist Stufe 1, und die geht auf dem echten Panel genauso gefahrlos.

Mein Rat deshalb: Stufen 1 bis 4 auf dem echten Panel, und für Stufe 5 einen Wegwerf-Server statt eines Wegwerf-Panels.

## Statische Prüfung vor jedem Einspielen

```bash
python3 check.py
```
Findet, was `php -l` nicht findet: fehlende Übersetzungsschlüssel, `wire:click` auf Methoden, die es nicht gibt, Einstellfelder, die nirgends gelesen werden. Genau diese Fehler werfen keine Ausnahme – die Seite rendert, und der Fehler fällt erst auf, wenn jemand klickt.

Beim Schreiben dieser Anleitung hat das Skript prompt einen gefunden: das neue Ergebnis „Probelauf" hatte keinen Übersetzungsschlüssel. In der Historie hätte statt des Worts der rohe Schlüssel gestanden.
