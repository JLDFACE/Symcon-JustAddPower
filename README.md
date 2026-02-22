# Symcon‑JustAddPower – MaxColor Routing Modul

## Überblick
Dieses Repository stellt IP‑Symcon‑Module zur Steuerung von **Just Add Power MaxColor** Encodern und Decodern bereit.  
Der Fokus liegt auf **Flexible Mode / Multicast Routing** inkl. getrenntem Umschalten von **Video, Audio und USB**.

Das Modul ist **SymBox‑kompatibel**, versionskonservativ implementiert und bewusst ohne experimentelle UI‑APIs gehalten.

---

## Repository‑Struktur (IP‑Symcon Standard)

```
Symcon-JustAddPower/
├── library.json
├── README.md
├── Configurator/
│   ├── module.json
│   ├── module.php
│   └── form.json
├── Registry/
│   ├── module.json
│   └── module.php
├── Encoder/
│   ├── module.json
│   └── module.php
└── Decoder/
    ├── module.json
    └── module.php
```

**Wichtig:**
- Im Root **nur** `library.json` und `README.md`
- Jedes Modul liegt in einem eigenen Unterordner
- GUIDs sind eindeutig und müssen **nicht geändert** werden

---

## Modulübersicht

### 1) Configurator
- Netzwerk‑Scan (Telnet)
- Erkennung von MaxColor Geräten (`getmodel.sh`)
- Automatische Rollenbestimmung (ENC / DEC)
- Auslesen des `webname` vom Gerät
- Erstellen von Encoder‑ und Decoder‑Instanzen
- Manuelles Override (Encoder / Decoder / Skip)

### 2) Registry (Source Registry)
- Zentrale Quelle für alle Encoder‑Quellen
- Validiert:
  - eindeutige SourceNames
  - kollisionsfreie Video / Audio / USB Channels
- Liefert Source‑Mapping an Decoder

### 3) Encoder (Flexible)
- Setzt Multicast‑Kanäle für:
  - Video
  - Audio
  - USB
- Automatische Kanalvergabe über Registry möglich
- Telnet‑basierte Steuerung (`channel -v/-a/-u`)

### 4) Decoder (Flexible)
- Routing von Video / Audio / USB
- Audio‑folgt‑Video und USB‑folgt‑Video
- Preset‑Grundlage vorbereitet
- Dynamische Auswahl der Quellen aus Registry

---

## Ansteuerungskonzept (Routing‑Logik)

### Grundprinzip
- **Encoder** senden auf Multicast‑Kanälen
- **Decoder** abonnieren Multicast‑Kanäle
- Jeder Dienst ist **separat schaltbar**:
  - Video (`-v`)
  - Audio (`-a`)
  - USB (`-u`)

### Channel‑Schema (Beispiel)
| Dienst | Basis | Zweck |
|------|------|------|
| Video | 1000 | Hauptbild |
| Audio | 2000 | Ton |
| USB   | 3000 | USB‑Routing |

> Encoder *n* nutzt:
> - Video = 1000 + n  
> - Audio = 2000 + n  
> - USB   = 3000 + n  

Dieses Schema wird zentral in der **Registry** verwaltet.

---

## Channel ↔ Multicast IP (MaxColor)

Im **Flexible/Advanced Mode** wird die Multicast-IP direkt aus dem Kanal abgeleitet:

- Video: `239.92.xx.yy`
- Audio: `239.93.xx.yy`
- USB (MaxColor/MC-USB): `239.97.xx.yy`

Dabei gilt:
- `Kanal = xxyy` (00..99 / 00..99)
- Beispiel: Kanal `1001` => `xx=10`, `yy=01`

### Beispiele (MaxColor)
| Dienst | Kanal | Multicast IP |
|------|------:|------|
| Video | 1000 | `239.92.10.00` |
| Video | 1001 | `239.92.10.01` |
| Audio | 2000 | `239.93.20.00` |
| Audio | 2001 | `239.93.20.01` |
| USB   | 3000 | `239.97.30.00` |
| USB   | 3001 | `239.97.30.01` |

Hinweis:
- Geräte zeigen IPs oft ohne führende Nullen an (z. B. `239.97.30.1` statt `239.97.30.01`).
- Wichtige Stolperfalle: `239.97.10.1` entspricht **Kanal 1001**, nicht 3001.

---

## Voraussetzungen auf den Just Add Power Geräten

### Zwingend erforderlich (Flexible Mode / Advanced)

Auf **allen** MaxColor Geräten:

```
channel mode advanced
reboot
```

Danach kann pro Dienst geschaltet werden:
```
channel -v <0-9999>
channel -a <0-9999>
channel -u <0-9999>
```

Wichtig:
- Alle beteiligten Geräte müssen im gleichen Mode laufen.
- `channel mode advanced` zeigt die Default-Scopes für Video/Audio/USB an.
- Die früher oft verwendeten `astparam ... switch_mode/free_routing/multicast_on` Kommandos sind auf neueren justOS/MAX-Ständen nicht verlässlich verfügbar.

Firmware prüfen (CLI):
```
GET /details/device/firmware/version
```

---

### WebName (empfohlen)
Der `webname` ist die **Single Source of Truth** für die Namensgebung in Symcon.

Setzen:
```
astparam s webname MVZ-Raum1-Projektor
astparam save
```

Der Configurator liest diesen Namen automatisch aus.

---

### Netzwerk
- Telnet (Port 23) muss erreichbar sein
- Geräte und SymBox müssen im gleichen Routing‑Kontext liegen (kein VLAN‑Block)

---

## Inbetriebnahme Schritt für Schritt

### 1) Repository hinzufügen
- Module Control → Repository hinzufügen
- URL: `https://github.com/JLDFACE/Symcon-JustAddPower`

### 2) Configurator anlegen
- Instanz „JustAddPower Configurator“ erstellen
- IP‑Bereich und Timeouts einstellen

### 3) Scan durchführen
- „Scan starten“
- Gefundene Geräte prüfen
- Rollen ggf. manuell überschreiben

### 4) Geräte erstellen
- Encoder und Decoder über Configurator erzeugen
- Registry wird automatisch angelegt

### 5) Encoder prüfen
- SourceName korrekt?
- Kanäle vergeben?
- Telnet‑Verbindung aktiv?

### 6) Decoder konfigurieren
- Registry‑Instanz gesetzt?
- Quellen auswählbar?
- Audio/USB‑Follow‑Optionen prüfen

### 7) MC-USB richtig zuordnen
- MC-USB als **HOST**: stellt USB-Quelle bereit (SourceName + USBChannel, dann `USB Kanal anwenden`)
- MC-USB als **CLIENT**: wählt eine Quelle aus der Registry (`USBSource`), setzt keinen festen USB-Kanal
- Für MaxColor gilt bei USB immer Scope `239.97.x.x`

---

## Typische Fehlerbilder

### „Modul mit GUID nicht gefunden“
→ SymBox Cache  
**Fix:** Repo entfernen → Reboot → Repo neu hinzufügen

### Telnet „Connection refused“
→ Port 23 blockiert oder Gerät limitiert Sessions

### Geräte nicht im Scan
→ Nicht im Multicast Mode oder Telnet nicht erreichbar

### Nach „Channels anwenden“ kein Signal
→ Meist Kanal-/Scope-Mismatch zwischen Sollwert und Gerät

Checkliste:
- Sind alle Geräte auf `channel mode advanced`?
- Stimmen Video/Audio/USB-Kanal wirklich mit der erwarteten Multicast-IP überein?
- Beispiel: Für USB-Kanal `3001` muss die Zielgruppe `239.97.30.01` sein.
- Falls nur ein Dienst geändert werden soll, im Encoder die separaten Buttons verwenden:
  - `Nur Video anwenden`
  - `Nur Audio anwenden`
  - `Nur USB anwenden`

---

## Design‑Leitlinien dieses Moduls

- konservative IP‑Symcon APIs
- keine experimentellen UI‑Funktionen
- klare Trennung:
  - Discovery
  - Registry
  - Steuerung
- Fokus auf **Stabilität im Produktivbetrieb**

---

## Lizenz / Nutzung
Internes Projekt der FACE GmbH / JLDFACE  
Anpassungen projektspezifisch möglich.

---

**Status:** stabiler Produktivstand  
**Empfehlung:** Änderungen nur iterativ und isoliert vornehmen
