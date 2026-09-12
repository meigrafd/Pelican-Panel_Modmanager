# So bringst du das ins Repository

Dein Repository hat gerade den falschen Inhalt. Das ist schnell behoben, aber es
sind ein paar Dinge, die alle stimmen müssen, damit der Update-Knopf im Panel
später funktioniert.

## Was jetzt falsch ist

| Problem | Warum es nicht geht |
|---|---|
| Nur `mod-auto-restart.zip` liegt im Repo, die Dateien fehlen | Git kann nicht sehen, was sich in einem Zip ändert. Genau das wolltest du loswerden. |
| `release.yml` liegt im Wurzelverzeichnis | GitHub führt nur aus, was unter `.github/workflows/` liegt. Dort tut sie nichts. |
| `LICENSE` fehlt | Der Link im README zeigt ins Leere, und MIT verlangt, dass der Hinweis mitgeliefert wird. |
| `src/`, `config/`, `lang/`, `resources/` fehlen | Das ist das eigentliche Plugin. |
| `DEIN-GITHUB-NAME` steht noch in den Dateien | Pelican sucht Updates dann an einer Adresse, die es nicht gibt. |

## Der Weg über die Weboberfläche (ohne git)

GitHub kann per Ziehen-und-Ablegen keine Ordner hochladen, deren Name mit einem
Punkt beginnt — dein Dateimanager blendet sie meist gar nicht erst ein. Genau
deshalb fehlen `.github` und `.gitignore` so leicht.

Der Ausweg: solche Dateien nicht hochladen, sondern **anlegen**. Dabei erzeugt
ein Schrägstrich im Dateinamen automatisch die Ordner.

**`.github/workflows/release.yml` anlegen**

1. Im Repository auf **Add file** → **Create new file**
2. In das Namensfeld oben genau das hier tippen:
   `.github/workflows/release.yml`
   Beim Tippen der Schrägstriche zerfällt das Feld in Ordner — das ist richtig so.
3. Inhalt aus der Datei gleichen Namens einfügen
4. Unten auf **Commit changes**

**`.gitignore` anlegen**

Dasselbe noch einmal, Name: `.gitignore`

**Alles andere** (Ordner `src`, `config`, `lang`, `resources`, `tests` und die
Dateien im Wurzelverzeichnis) lässt sich normal per **Add file** → **Upload
files** hochladen. Ordner können dabei direkt ins Fenster gezogen werden.

## Veröffentlichen über die Weboberfläche

1. Im Repository rechts auf **Releases** → **Draft a new release**
2. Bei **Choose a tag** `v0.3.0` eintippen und auf
   **Create new tag: v0.3.0 on publish** klicken
3. Titel eintragen, zum Beispiel `0.3.0`
4. **Publish release**

Die Action startet daraufhin von selbst, prüft, baut das Zip, hängt es an genau
dieses Release und schreibt `update.json` um.

Wichtig: Die Version in `plugin.json` und der Tag müssen übereinstimmen — hier
also beide `0.3.0`. Passt es nicht, bricht die Action mit einer klaren Meldung
ab, statt ein Release zu bauen, das Pelican nie als Update erkennt.

## Der Weg mit git

**1.** Zip herunterladen und entpacken. Darin liegt ein Ordner
`mod-auto-restart` mit allem.

**2.** Repository leeren und den Inhalt hineinlegen — nicht den Ordner selbst,
sondern was darin ist:

```bash
git clone https://github.com/meigrafd/Pelican-Panel_Modmanager.git
cd Pelican-Panel_Modmanager

# alles Alte raus
git rm -r --cached . -q
rm -rf CHANGELOG.md EINBAU.md INSTALL.md README.md plugin.json release.yml update.json mod-auto-restart.zip

# Inhalt des entpackten Ordners hierher kopieren, inklusive der versteckten
# Dateien (.github und .gitignore) — das -a und der Punkt sind wichtig
cp -a /pfad/zu/mod-auto-restart/. .

git add -A
git commit -m "Plugin 0.3.0"
git push
```

**3.** Prüfen, dass es stimmt:

```bash
ls -A
```

Erwartet: `.github`, `.gitignore`, `CHANGELOG.md`, `EINBAU.md`, `INSTALL.md`,
`LICENSE`, `README.md`, `check.py`, `config`, `lang`, `lint.sh`, `plugin.json`,
`resources`, `src`, `tests`, `update.json`

Kein `mod-auto-restart.zip`. Die `.gitignore` hält es draußen, und die Action
baut es bei jedem Tag neu.

**4.** Erste Veröffentlichung:

```bash
git tag v0.3.0
git push --tags
```

Unter **Actions** im Repository läuft jetzt „Release". Sie prüft erst (Syntax,
statische Prüfung, 74 Tests), baut dann das Zip, hängt es ans Release und
schreibt `update.json` auf die neue Version.

Dafür braucht sie Schreibrecht: **Settings → Actions → General → Workflow
permissions → Read and write permissions**. Ohne das kann sie `update.json`
nicht zurückschreiben und bricht am letzten Schritt ab.

## Danach installieren

```bash
cd /var/www/pelican/plugins
git clone https://github.com/meigrafd/Pelican-Panel_Modmanager.git mod-auto-restart
cd /var/www/pelican
php artisan p:plugin:install
php artisan optimize:clear
```

Das `mod-auto-restart` am Ende der Klon-Zeile ist **nicht optional**. Pelican
verlangt, dass der Ordner genauso heißt wie die `id` in `plugin.json`, und die
lautet `mod-auto-restart` — nicht wie dein Repository. Ohne den Zusatz heißt der
Ordner `Pelican-Panel_Modmanager`, und Pelican übergeht das Plugin wortlos.

Alternativ über die Oberfläche: das Zip **aus den Releases** nehmen, nicht das
vom grünen Code-Knopf. Letzteres packt einen Ordner mit `-main` am Ende, und
damit gilt dasselbe Problem.

## Beim nächsten Mal

Wenn wir etwas ändern:

1. Dateien austauschen
2. Version in `plugin.json` hochzählen
3. `CHANGELOG.md` ergänzen
4. `git tag v0.3.1 && git push --tags`

Im Panel erscheint unter Admin → Plugins ein Update-Knopf. Die Action bricht ab,
wenn Tag und Version nicht zusammenpassen — Pelican vergleicht die Version im
Plugin, nicht den Tag, und ein Release mit abweichender Nummer würde nie als
Update erkannt.
