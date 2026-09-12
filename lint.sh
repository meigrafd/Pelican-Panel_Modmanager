#!/bin/sh
# Alles, was vor dem Ausliefern laufen sollte.
#
# Ein Plugin, das nicht parst, nimmt das ganze Panel mit einer weissen Seite
# runter statt mit einer Meldung. Und die Fehler, die php -l NICHT findet -
# fehlende Uebersetzungsschluessel, ins Leere zeigende Knoepfe - werfen keine
# Ausnahme: die Seite rendert, und es faellt erst auf, wenn jemand klickt.
#
# Jede Stufe bricht das Skript ab. Eine fruehere Fassung im Original leitete
# durch `grep -v "No syntax errors" || true`, druckte also den Parse-Fehler und
# meldete danach Erfolg - eine kaputte Datei bestand die Pruefung, die es gab,
# um genau das zu fangen.
set -e
cd "$(dirname "$0")"

echo "== PHP-Syntax"
find . -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null
echo "   alle Dateien ok"

echo
echo "== Statische Pruefung"
python3 check.py

echo
echo "== Phasenlogik"
php tests/PhaseTest.php

echo
echo "== Installation: Aufbau-Erkennung und Abhaengigkeiten"
php tests/InstallTest.php

echo
echo "Alles durch."
