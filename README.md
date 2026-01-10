# Symcon-JustAddPower -- MaxColor Flexible Mode

## Flexible Mode (MaxColor) -- Aktivierung & Prüfung

Dieses Modul setzt **zwingend den Flexible Mode** (Multicast / Free
Routing) auf allen Just Add Power MaxColor Geräten voraus.

### Modus prüfen

    astparam

Erwartet:

    switch_mode=multicast
    free_routing=y
    multicast_on=y

### Flexible Mode aktivieren (falls nötig)

    channel mode advanced
    reboot

### Verifikation

    channel -v 1000
    channel -a 2000
    channel -u 3000

------------------------------------------------------------------------

## Inbetriebnahme-Checkliste (Techniker / Service)

### 1. Netzwerk

-   Gleiches Layer-2-Netz
-   Multicast erlaubt
-   Telnet Port 23 erreichbar

### 2. Gerätemodus prüfen

    astparam

-   free_routing=y

### 3. Flexible Mode aktivieren (falls nötig)

    channel mode advanced
    reboot

### 4. WebName setzen

    astparam s webname "STANDORT-RAUM-QUELLE-INDEX"

### 5. IP-Symcon

-   Registry anlegen
-   Encoder anlegen (SourceName = WebName)
-   Decoder anlegen

### 6. Routing-Test

    channel -v 1000
    channel -a 2000
    channel -u 3000

### 7. Abschluss

-   Keine Fehlermeldungen
-   Registry Status OK
