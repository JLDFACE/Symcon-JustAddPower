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

## Voraussetzungen auf den Just Add Power Geräten

### Zwingend erforderlich (Flexible Mode)

Auf **allen** MaxColor Geräten:

```
switch_mode=multicast
free_routing=y
multicast_on=y
```

Prüfen per Telnet:
```
astparam g switch_mode
astparam g free_routing
astparam g multicast_on
```

Falls nötig setzen:
```
astparam s switch_mode multicast
astparam s free_routing y
astparam s multicast_on y
astparam save
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

---

## Typische Fehlerbilder

### „Modul mit GUID nicht gefunden“
→ SymBox Cache  
**Fix:** Repo entfernen → Reboot → Repo neu hinzufügen

### Telnet „Connection refused“
→ Port 23 blockiert oder Gerät limitiert Sessions

### Geräte nicht im Scan
→ Nicht im Multicast Mode oder Telnet nicht erreichbar

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
