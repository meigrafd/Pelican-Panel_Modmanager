<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Spielprofile
    |--------------------------------------------------------------------------
    |
    | Ein Profil beschreibt alles, was an einem Spiel anders ist. Der Rest des
    | Plugins kennt kein einziges Spiel beim Namen.
    |
    | Die Zuordnung laeuft in dieser Reihenfolge:
    |   1. Was der Operator auf der Seite eingestellt hat
    |   2. Das erste Profil, dessen `egg_match` im Egg-Namen vorkommt
    |   3. 'generic'
    |
    | Eigene Profile kommen einfach dazu - ohne Code. Was ein neues Profil
    | mindestens braucht: `egg_match`, `app_id`, `mods_path` und, wenn gewarnt
    | werden soll, einen `messaging`-Block.
    |
    | Schluesselfelder:
    |
    |   app_id       Steam-App des DEDIZIERTEN Servers, nicht des Spiels. Wird
    |                von der Egg-Variable SRCDS_APPID ueberschrieben, wenn es
    |                sie gibt - dann stimmt sie immer, auch bei einem eigenen
    |                Egg.
    |
    |   mods_path    Ordner im Container, in dem die Mod-Ordner liegen.
    |
    |   layout       Wie ein Mod-Ordner aussieht:
    |                'thunderstore' = <Autor>-<Paket>/manifest.json  (Gale,
    |                r2modman, Thunderstore-CLI - der Normalfall)
    |                'flat'         = <Paket>/manifest.json, Autor unbekannt.
    |                Dann kann nichts automatisch zugeordnet werden; solche
    |                Mods werden angezeigt, aber nie geprueft.
    |
    |   sources      Welche Repositorys dieses Spiel fuehren. Der Schluessel ist
    |                der Name in der Auswahl, der Wert die Basis-URL. Bei
    |                Thunderstore ist die URL fuer ALLE Spiele dieselbe: der
    |                Paket-Endpunkt kennt nur Autor und Paketname, keine
    |                Community. Bei Hexium hat jedes Spiel eine eigene
    |                Subdomain.
    |
    |   messaging    Wie Spieler gewarnt werden. Siehe unten.
    |
    */

    'profiles' => [

        'valheim' => [
            'label' => 'Valheim',
            'egg_match' => ['valheim'],
            'app_id' => '896660',
            'mods_path' => 'BepInEx/plugins',
            'layout' => 'thunderstore',
            'sources' => [
                'thunderstore' => 'https://thunderstore.io',
                'hexium' => 'https://valheim.hexium.gg',
            ],
            'page_url' => [
                'thunderstore' => 'https://thunderstore.io/c/valheim/p/{namespace}/{name}/',
                'hexium' => 'https://valheim.hexium.gg/mods/{namespace}/{name}',
            ],
            'messaging' => [
                'via' => 'rcon',
                // ValheimRcon lauscht standardmaessig auf Spielport + 2.
                'port_offset' => 2,
                // showMessage steht mittig auf dem Bildschirm, say landet im
                // Chat. Beides, damit die Warnung weder uebersehen noch
                // vergessen werden kann.
                'broadcast' => 'showMessage :text',
                'chat' => 'say :text',
                'save' => 'save',
                'stats' => 'serverStats',
                'needs_mod' => 'ValheimRcon',
            ],
            /*
            | Indizien fuer die Kompatibilitaetspruefung.
            |
            | Keines der beiden Repositorys hat ein Feld fuer die Spielversion.
            | Was es gibt, sind Kategorien - und die beiden fuehren
            | UNTERSCHIEDLICHE:
            |
            |   Hexium   hat einen ausdruecklichen Tag "Valheim 1.0".
            |   Thunderstore hat "Client-side" und "Server-side", dafuer keinen
            |            Versionstag (nur Epochen wie "Bog Witch Update", alle
            |            vor 1.0).
            |
            | Deshalb darf die PRUEFUNG beide Quellen befragen, auch wenn
            | INSTALLIERT immer nur aus einer wird. Versionsnummern mischen
            | waere gefaehrlich; Hinweise mischen ist genau richtig.
            */
            'signals' => [
                // Kategorie, die "fuer die aktuelle Spielgeneration gebaut"
                // bedeutet, und wo sie steht.
                'current_tag' => ['source' => 'hexium', 'name' => 'Valheim 1.0'],
                // Wo Client-/Serverseite vermerkt ist.
                'sides' => 'thunderstore',
            ],

            // Warnungen, die nur fuer dieses Spiel gelten. Reiner Text auf der
            // Seite, ohne Wirkung auf die Logik.
            'notes' => [
                'crossplay' => 'ENABLE_CROSSPLAY',
            ],
        ],

        'v-rising' => [
            'label' => 'V Rising',
            'egg_match' => ['v rising', 'v_rising', 'vrising'],
            'app_id' => '1829350',
            'mods_path' => 'BepInEx/plugins',
            'layout' => 'thunderstore',
            'sources' => [
                'thunderstore' => 'https://thunderstore.io',
            ],
            'page_url' => [
                'thunderstore' => 'https://thunderstore.io/c/v-rising/p/{namespace}/{name}/',
            ],
            // V Rising bringt RCON nicht von Haus aus mit. Ohne ein Mod, das
            // eine Schnittstelle oeffnet, gibt es keine Vorwarnung - der
            // Neustart laeuft trotzdem, nur still.
            'messaging' => ['via' => 'none'],
        ],

        'core-keeper' => [
            'label' => 'Core Keeper',
            'egg_match' => ['core keeper', 'core_keeper'],
            'app_id' => '1963720',
            'mods_path' => 'BepInEx/plugins',
            'layout' => 'thunderstore',
            'sources' => [
                'thunderstore' => 'https://thunderstore.io',
            ],
            'page_url' => [
                'thunderstore' => 'https://thunderstore.io/c/core-keeper/p/{namespace}/{name}/',
            ],
            'messaging' => ['via' => 'none'],
        ],

        'sunkenland' => [
            'label' => 'Sunkenland',
            'egg_match' => ['sunkenland'],
            'app_id' => '2667530',
            'mods_path' => 'BepInEx/plugins',
            'layout' => 'thunderstore',
            'sources' => [
                'thunderstore' => 'https://thunderstore.io',
                'hexium' => 'https://sunkenland.hexium.gg',
            ],
            'page_url' => [
                'thunderstore' => 'https://thunderstore.io/c/sunkenland/p/{namespace}/{name}/',
                'hexium' => 'https://sunkenland.hexium.gg/mods/{namespace}/{name}',
            ],
            'messaging' => ['via' => 'none'],
        ],

        /*
        | Projekt Zomboid, der Ausgangspunkt dieses Plugins. Keine Mod-Quelle -
        | die Mods kommen aus dem Steam Workshop, den dieses Plugin nicht
        | abfragt. Was bleibt, ist die Ueberwachung des Spiel-Builds, und die
        | Ansage kann ueber die Panel-Konsole gehen statt ueber RCON.
        */
        'project-zomboid' => [
            'label' => 'Project Zomboid (nur Spiel-Updates)',
            'egg_match' => ['zomboid'],
            'app_id' => '380870',
            'mods_path' => null,
            'layout' => 'thunderstore',
            'sources' => [],
            'page_url' => [],
            'messaging' => [
                'via' => 'console',
                'broadcast' => 'servermsg ":text"',
                'save' => 'save',
            ],
        ],

        /*
        | Rueckfall fuer alles andere. Prueft nur den Spiel-Build, warnt nicht
        | und sucht keine Mods, bis jemand app_id und mods_path auf der Seite
        | eintraegt.
        */
        'generic' => [
            'label' => 'Anderes Spiel',
            'egg_match' => [],
            'app_id' => null,
            'mods_path' => 'BepInEx/plugins',
            'layout' => 'thunderstore',
            'sources' => [
                'thunderstore' => 'https://thunderstore.io',
            ],
            'page_url' => [
                'thunderstore' => 'https://thunderstore.io/package/{namespace}/{name}/',
            ],
            'messaging' => ['via' => 'none'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ansage-Wege
    |--------------------------------------------------------------------------
    |
    |   'rcon'    Source-RCON gegen den Server. Braucht meist ein Mod.
    |             Platzhalter :text im Befehl.
    |   'console' Ueber die Panel-Konsole (stdin des Servers). Kein Port, kein
    |             Passwort - aber nur, wenn das Spiel einen Broadcast-Befehl
    |             auf stdin hat. Valheim hat keinen.
    |   'none'    Keine Vorwarnung. Der Neustart laeuft trotzdem.
    |
    */

    // Nur Server anzeigen, deren Egg zu einem Profil passt. Auf false gesetzt
    // taucht die Seite bei jedem Server auf und faellt auf 'generic' zurueck.
    'require_known_profile' => false,

    /*
    |--------------------------------------------------------------------------
    | Probelauf
    |--------------------------------------------------------------------------
    |
    | Panelweiter Schalter. Wenn true, laeuft alles wie sonst - Erkennung,
    | Warnungen, Backup, Historie - nur der Neustart selbst wird unterdrueckt
    | und stattdessen als "dry-run" vermerkt.
    |
    | Gedacht fuer die ersten Tage auf einem echten Panel: man sieht an echten
    | Daten, ob das Plugin das Richtige tun WUERDE, bevor man ihm erlaubt, es zu
    | tun. Kein Ersatz fuer einen Testserver, aber deutlich naeher an der
    | Wahrheit als jede Simulation.
    |
    | Achtung: Warnungen gehen dabei trotzdem an die Spieler raus. Wer das nicht
    | will, laesst die Nachrichtenfelder leer oder traegt kein RCON-Passwort ein.
    |
    */
    'dry_run' => env('MAR_DRY_RUN', false),

    'cache' => [
        'index_minutes' => 10,
        'registry_minutes' => 10,
        'build_minutes' => 10,
    ],
];
