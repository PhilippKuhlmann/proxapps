{{--
    Der Netzplan der Anmeldeseite. Zweimal eingebunden, in zwei Rollen:

    1. $hintergrund = true - blass, absolut positioniert, nur Linien und
       Kaesten. Unter lg liegt diese Fassung hinter der Karte und fuellt die
       ganze Flaeche; ab lg schrumpft sie auf einen Streifen links, damit
       hinter der Karte etwas zu sehen ist, ohne der lesbaren Fassung in der
       zweiten Spalte ins Gehege zu kommen.

    2. ohne $hintergrund - die lesbare Fassung. Erst ab lg, wo sie als eigene
       Rasterspalte neben der Maske steht.

    Beschriftungen traegt nur die lesbare Fassung. Im Hintergrund waeren sie an
    der Kartenkante mitten im Wort abgeschnitten, und ein halbes "RTR-CORE"
    sieht nach Fehler aus.

    Reine Zeichnung, kein Inhalt: aria-hidden, damit Vorleseprogramme nicht
    anfangen, IP-Bereiche vorzulesen, bevor jemand angemeldet ist. Die Werte
    sind feste Beispiele und stammen von keinem Kunden - die Seite sieht jeder,
    auch ohne Zugang.

    Farben ueber currentColor statt fill-/stroke-Utilities: so haengt die ganze
    Zeichnung an einer einzigen text-Klasse je Gruppe und folgt dem
    Dunkelmodus, ohne dass jede Linie ihre eigene dark:-Variante braucht.

    Aufbau von oben nach unten: Gateway, Core-Switch, Verteilerschiene, drei
    Etagen-Switches, Zugangsschiene, vier VLANs, Patchfeld.
--}}
@php
    $hintergrund ??= false;

    $lage = $hintergrund
        ? 'absolute inset-0 h-full w-full opacity-30
           lg:inset-y-0 lg:left-0 lg:right-auto lg:w-[30rem] lg:opacity-25'
        : 'hidden h-auto max-h-[78vh] w-full lg:block';

    /*
     * Die Wege, die ein Paket nehmen kann. Immer dieselbe Strecke leuchten zu
     * lassen sah nach Schleife aus; mehrere Wege sehen nach Betrieb aus.
     *
     * Die Zeiten sind absichtlich krumm und ohne gemeinsamen Teiler: Bei 6, 8
     * und 4 Sekunden traefen sich die Pakete regelmaessig wieder am Start, und
     * genau das faellt auf.
     */
    $wege = [
        // Vom Gateway in den Core
        ['d' => 'M765 198 V248', 'dauer' => '2.9s', 'start' => '3.6s'],
        // Core ueber die Verteilerschiene auf die Etagen-Switches
        ['d' => 'M835 344 V404 H525 V444', 'dauer' => '6.5s', 'start' => '0s'],
        ['d' => 'M835 344 V404 H1045 V444', 'dauer' => '7.1s', 'start' => '2.3s'],
        // Von den Etagen ueber die Zugangsschiene in die VLANs
        ['d' => 'M785 494 V534 H400 V574', 'dauer' => '8.3s', 'start' => '1.1s'],
        ['d' => 'M1045 494 V534 H910 V574', 'dauer' => '5.3s', 'start' => '4.7s'],
        // Vom WLAN-VLAN hinunter aufs Patchfeld
        ['d' => 'M1165 660 V712', 'dauer' => '3.7s', 'start' => '1.4s'],
    ];

    /* x-Anfang der drei Etagen-Switches, damit Kasten und Ports zusammenpassen. */
    $etagen = [435, 695, 955];
@endphp

{{-- slice fuer den Hintergrund: fuellt die Flaeche und beschneidet, statt sie
     per CSS-scale zu vergroessern - das hatte Chrome sichtbar unscharf
     gezeichnet. meet fuer die lesbare Fassung: dort soll nichts wegfallen. --}}
<svg class="pointer-events-none {{ $lage }}" viewBox="270 100 1075 730"
    preserveAspectRatio="xMidYMid {{ $hintergrund ? 'slice' : 'meet' }}"
    aria-hidden="true" focusable="false">

    {{-- Linien, Kaesten und Ports teilen sich eine Farbe. Sie steht einmal an
         der aeusseren Gruppe; die inneren sagen nur noch, womit gemalt wird -
         sonst stuende dieselbe Klasse zweimal da und koennte auseinanderlaufen. --}}
    <g class="text-chathams-blue-200 dark:text-cerulean-900">
        {{-- Linien und Kaesten --}}
        <g stroke="currentColor" stroke-width="1.7" fill="none">
            {{-- Gateway und Core --}}
            <rect x="690" y="146" width="150" height="52" />
            <path d="M765 198 V248" />
            <rect x="690" y="286" width="290" height="58" />

            {{-- Verteilerschiene: vom Core auf die Etagen --}}
            <path d="M835 344 V404" />
            <path d="M400 404 H1165" />
            <path d="M525 404 V444 M785 404 V444 M1045 404 V444" />

            {{-- Die drei Etagen-Switches --}}
            @foreach ($etagen as $x)
                <rect x="{{ $x }}" y="444" width="180" height="50" />
            @endforeach

            {{-- Zugangsschiene: von den Etagen in die VLANs --}}
            <path d="M525 494 V534 M785 494 V534 M1045 494 V534" />
            <path d="M400 534 H1165" />
            <path d="M400 534 V574 M655 534 V574 M910 534 V574 M1165 534 V574" />

            {{-- Die vier VLANs --}}
            <rect x="300" y="574" width="200" height="86" />
            <rect x="555" y="574" width="200" height="86" />
            <rect x="810" y="574" width="200" height="86" />
            <rect x="1065" y="574" width="200" height="86" />

            {{-- Patchfeld unter dem WLAN-VLAN --}}
            <path d="M1165 660 V712" />
            <rect x="1015" y="712" width="300" height="46" />

            {{-- Massstabsleiste, wie sie auf einer Zeichnung unten steht --}}
            <path d="M400 800 H580 M400 794 V806 M490 796 V804 M580 794 V806" />
        </g>

        {{-- Ports: gefuellte Kaestchen --}}
        <g fill="currentColor" opacity="0.6">
            {{-- Core-Switch: zwei Reihen --}}
            <rect x="702" y="300" width="12" height="9" /><rect x="718" y="300" width="12" height="9" />
            <rect x="734" y="300" width="12" height="9" /><rect x="750" y="300" width="12" height="9" />
            <rect x="766" y="300" width="12" height="9" /><rect x="782" y="300" width="12" height="9" />
            <rect x="798" y="300" width="12" height="9" /><rect x="814" y="300" width="12" height="9" />
            <rect x="702" y="313" width="12" height="9" /><rect x="718" y="313" width="12" height="9" />
            <rect x="734" y="313" width="12" height="9" /><rect x="750" y="313" width="12" height="9" />
            <rect x="766" y="313" width="12" height="9" /><rect x="782" y="313" width="12" height="9" />

            {{-- Etagen-Switches: je acht und sechs Ports --}}
            @foreach ($etagen as $x)
                @for ($i = 0; $i < 8; $i++)
                    <rect x="{{ $x + 12 + $i * 17 }}" y="464" width="12" height="9" />
                @endfor
                @for ($i = 0; $i < 6; $i++)
                    <rect x="{{ $x + 12 + $i * 17 }}" y="477" width="12" height="9" />
                @endfor
            @endforeach

            {{-- Patchfeld --}}
            @for ($i = 0; $i < 12; $i++)
                <rect x="{{ 1027 + $i * 19 }}" y="726" width="15" height="18" />
            @endfor
        </g>
    </g>

    @unless ($hintergrund)
        {{-- Beschriftungen --}}
        <g class="font-mono text-chathams-blue-500 dark:text-cerulean-700" fill="currentColor" font-size="16.5">
            <text x="700" y="132">GATEWAY</text>
            <text x="700" y="272">PVE-CLUSTER</text>

            <text x="435" y="436">PVE-01</text>
            <text x="695" y="436">PVE-02</text>
            <text x="955" y="436">PVE-03</text>

            <text x="314" y="602">CT 100 · ADGUARD</text>
            <text x="569" y="602">VLAN 250 · SERVER</text>
            <text x="824" y="602">VLAN 20 · CLIENTS</text>
            <text x="1079" y="602">VLAN 40 · WLAN</text>

            <text x="1025" y="700">vmbr0 · BRIDGE</text>
        </g>

        <g class="font-mono text-chathams-blue-400 dark:text-cerulean-800" fill="currentColor" font-size="14">
            <text x="702" y="177">10.0.0.1 · GW</text>
            <text x="1000" y="320">3 Nodes · LXC</text>

            <text x="314" y="624">10.0.50.0/24</text>
            <text x="314" y="644">.20 – .120 DHCP</text>

            <text x="569" y="624">10.10.250.0/24</text>
            <text x="569" y="644">.10 – .40 statisch</text>

            <text x="824" y="624">10.0.20.0/24</text>
            <text x="824" y="644">.100 – .200 DHCP</text>

            <text x="1079" y="624">10.0.40.0/24</text>
            <text x="1079" y="644">.100 – .250 DHCP</text>
        </g>
    @endunless

    {{-- Die Pakete zuletzt, damit sie ueber den Leitungen liegen.

         pathLength="100" normiert jede Strecke auf dieselbe Laenge - nur
         deshalb genuegt eine CSS-Regel fuer alle, egal wie lang der Weg ist.

         In der Hintergrundfassung laufen sie nur unter lg: Darueber ist die
         lesbare Fassung daneben zu sehen, und dann liefe jedes Paket
         doppelt. --}}
    @foreach ($wege as $weg)
        <path class="netzplan-paket text-cerulean-600 dark:text-cerulean-500 @if ($hintergrund) lg:hidden @endif"
            d="{{ $weg['d'] }}" pathLength="100"
            style="animation-duration: {{ $weg['dauer'] }}; animation-delay: {{ $weg['start'] }}"
            stroke="currentColor" stroke-width="2.6" stroke-linecap="round" fill="none" opacity="0.85" />
    @endforeach
</svg>
