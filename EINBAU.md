# Einbau-Anleitung, Schritt für Schritt

## Was du herunterlädst

Eine einzige Datei: **`mod-auto-restart.zip`**. Darin steckt ein Ordner namens `mod-auto-restart` mit rund zwanzig Dateien in Unterordnern. Du musst dort nichts anfassen und nichts entpacken, wenn du Weg A nimmst.

Wichtig: Der Ordner **muss** `mod-auto-restart` heißen. Genau so, klein geschrieben, mit Bindestrichen. Heißt er anders – zum Beispiel `mod-auto-restart-main`, wie GitHub es beim Herunterladen gern anhängt –, findet Pelican ihn nicht und meldet nichts. Kein Fehler, einfach nichts.

---

## Weg A: Über die Panel-Oberfläche (empfohlen)

Kein SSH, keine Kommandozeile.

**1.** Die Datei `mod-auto-restart.zip` herunterladen. Nicht entpacken.

**2.** Im Panel oben rechts auf dein Profilbild → **Admin**.

**3.** In der linken Leiste **Plugins** anklicken.

**4.** Oben auf **Import**. Die Zip-Datei auswählen und hochladen.

**5.** Kurz warten. Das Plugin taucht in der Liste auf, Status vermutlich „Not installed".

**6.** In der Zeile auf **Install** klicken.

**7.** Nochmal warten – ein paar Sekunden bis eine Minute. Der Status springt auf „Installed".

**Passiert minutenlang gar nichts?** Dann arbeitet im Hintergrund niemand. Pelican schiebt Installationen an einen Hintergrundarbeiter weiter, und wenn der nicht läuft, bleibt der Auftrag einfach liegen. Das ist ein Problem deines Panels, nicht des Plugins – und es betrifft auch Backups und geplante Aufgaben. Nachsehen, ob der Dienst läuft:

```
systemctl status pelican-queue
```

**8.** Fertig. Geh auf einen deiner Valheim-Server. In der linken Leiste ist ein neuer Punkt: **Auto-Neustart**.

---

## Weg B: Von Hand über SSH

Nötig, wenn der Import-Knopf fehlt oder nicht will.

### Erst herausfinden, wo dein Panel liegt

Zwei übliche Fälle:

**Fall 1 – normal installiert.** Dann gibt es den Ordner `/var/www/pelican`. Prüfen:
```bash
ls /var/www/pelican
```
Kommt eine Liste mit `app`, `config`, `artisan` und so weiter: Fall 1.

**Fall 2 – in einem Container.** Dann gibt es diesen Ordner nicht. Prüfen:
```bash
docker ps
```
Steht dort eine Zeile mit `pelican-panel` oder ähnlich: Fall 2. Merk dir den Namen ganz rechts in der Zeile – meist `pelican-panel-1`.

### Fall 1: normal installiert

```bash
cd /var/www/pelican/plugins
```

Zip dorthin bekommen. Wenn sie auf deinem PC liegt, von deinem PC aus:
```bash
scp mod-auto-restart.zip root@DEINE-SERVER-IP:/var/www/pelican/plugins/
```

Dann auf dem Server:
```bash
cd /var/www/pelican/plugins
unzip mod-auto-restart.zip
rm mod-auto-restart.zip
ls
```

Bei `ls` muss ein Ordner `mod-auto-restart` stehen. Steht da etwas anderes, jetzt umbenennen:
```bash
mv der-falsche-name mod-auto-restart
```

Der Webserver muss die Dateien lesen dürfen:
```bash
chown -R www-data:www-data /var/www/pelican/plugins/mod-auto-restart
```

Anmelden und Zwischenspeicher leeren:
```bash
cd /var/www/pelican
php artisan p:plugin:install
```
Es erscheint eine Liste. Mit den Pfeiltasten `mod-auto-restart` auswählen, Enter.

```bash
php artisan optimize:clear
```

### Fall 2: im Container

Zip auf den Server bringen (wie oben mit `scp`, Ziel zum Beispiel `/root/`). Dann:

```bash
cd /root
unzip mod-auto-restart.zip
docker cp mod-auto-restart pelican-panel-1:/var/www/html/plugins/
docker exec -it pelican-panel-1 php artisan p:plugin:install
docker exec pelican-panel-1 php artisan optimize:clear
```

Ersetze `pelican-panel-1` durch den Namen aus deinem `docker ps`.

---

## Kontrolle: hat es geklappt?

**Im Panel:** Valheim-Server öffnen. Links steht **Auto-Neustart**. Draufklicken. Oben sollte stehen: Profil „Valheim", App 896660, und darunter deine installierten Mods.

**Auf der Kommandozeile** (falls du SSH hast) – das ist der ehrlichere Test, weil er auch zeigt, ob das Plugin die Dateien deines Servers wirklich lesen kann:

```bash
cd /var/www/pelican
php artisan mar:check
```
Im Container:
```bash
docker exec pelican-panel-1 php artisan mar:check
```

Das startet nichts neu. Es zeigt nur, was das Plugin sieht.

---

## Wenn etwas schiefgeht

**Der Punkt „Auto-Neustart" taucht nicht auf.**
Meist der Ordnername. Nachsehen:
```bash
ls /var/www/pelican/plugins
```
Muss exakt `mod-auto-restart` sein. Danach `php artisan optimize:clear` und die Seite im Browser neu laden.

Zweithäufigster Grund: Der Schritt `p:plugin:install` wurde ausgelassen. Reines Hinkopieren reicht nicht – Pelican merkt sich den Zustand in einer Datei, die dieser Befehl schreibt.

**Das ganze Panel ist weiß oder zeigt einen Fehler.**
Ruhig bleiben, der Panel-Kern ist nicht angefasst worden. Ordner beiseite schieben:
```bash
cd /var/www/pelican
mv plugins/mod-auto-restart /root/
php artisan optimize:clear
```
Panel ist wieder da. Wenn du magst, schick mir die Fehlermeldung aus `storage/logs/laravel.log`.

**Plugin wieder loswerden.**
In der Plugin-Liste auf **Uninstall**. Oder:
```bash
cd /var/www/pelican
php artisan p:plugin:uninstall
php artisan optimize:clear
```

---

## Aus deinem eigenen GitHub installieren

Wenn du das Plugin bei dir ins GitHub gelegt hast, geht es auch so – und dann
bekommst du bei jeder Änderung einen Update-Knopf im Panel statt Handarbeit:

```bash
cd /var/www/pelican/plugins
git clone https://github.com/meigrafd/Pelican-Panel_Modmanager.git mod-auto-restart
cd /var/www/pelican
php artisan p:plugin:install
php artisan optimize:clear
```

Beim Klonen heißt der Ordner automatisch richtig. Das ist der Vorteil gegenüber
dem Zip-Download von GitHub, der ein `-main` anhängt.

Vorher einmalig: In `plugin.json` und `update.json` den Platzhalter
`meigrafd` durch deinen Benutzernamen ersetzen. Sonst sucht Pelican die
Updates an der falschen Stelle.

## Danach: nichts passiert von allein

Nach dem Einbau startet das Plugin **nichts** neu und installiert **nichts**. Die
Neustart-Funktion ist bei jedem Server einzeln ausgeschaltet, bis du sie
einschaltest, und eine Installation passiert erst, wenn du den Plan gesehen und
bestätigt hast. Du kannst also in Ruhe die Seite ansehen, `mar:check` laufen
lassen und RCON testen, ohne dass irgendein Server ins Wanken gerät.

## Die erste Mod installieren

1. Valheim-Server öffnen → **Mods & Neustart** → Abschnitt **Installierte Mods**
2. URL einfügen, zum Beispiel
   `https://thunderstore.io/c/valheim/p/Tristan/ValheimRcon/`
3. Auf **Prüfen**. Jetzt siehst du, was installiert würde – inklusive
   Abhängigkeiten wie BepInEx und aller Hinweise zur Kompatibilität. Es ist noch
   nichts passiert.
4. Auf **Installieren**. Die Dateien landen im Mod-Ordner.
5. Server neu starten, damit er sie lädt.

Wichtig: Mods, die auch die Spieler brauchen, müssen bei denen ebenfalls
installiert sein. Das Plugin bedient nur den Server.

Wie es danach weitergeht – in fünf Stufen vom Anschauen bis zum Scharfschalten – steht in `INSTALL.md` im Plugin-Ordner.

Ein Datenbank-Backup vor dem Einbau kostet nichts und nimmt dem Ganzen den Schrecken. Pelican bezeichnet sein Plugin-System selbst als noch in Entwicklung.
