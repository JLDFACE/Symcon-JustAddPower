# Symcon-JustAddPower (Just Add Power MaxColor) – IP-Symcon

## Betriebsmodus
Dieses Modul-Set ist für **Flexible Mode (advanced)** gedacht, d. h. Video/Audio/USB werden getrennt geschaltet.
Die Umschaltung erfolgt über Telnet/CLI (Port 23).

Hinweis: Das Setzen von `channel mode advanced` erfordert in der Regel einen Reboot aller Geräte.

## Namenskonzept
- Wir pflegen den **WebName** auf den Geräten.
- Encoder: WebName = SourceName (global eindeutig)
- Decoder: Auswahl ausschließlich über SourceName (keine Zahlen in der Bedienung)

## Channel-Schema
Service-Bereiche + fortlaufender Source-Index `n`:

- VideoCH = 1000 + n
- AudioCH = 2000 + n
- USBCH  = 3000 + n

`n` wird automatisch als **nächster freier Index** vergeben (Registry).

## Module
- **Configurator**: IP-Scan + WebName auslesen + Encoder/Decoder anlegen
- **Registry**: globale Sources aus Encoder-Instanzen, Validierung, NextFree-Index
- **Encoder**: SourceName/WebName, Auto-Assign nach Schema, Apply Channels
- **Decoder**: Video/Audio/USB getrennt, Audio folgt Video, USB folgt Video, Presets

## Auto-Erkennung Encoder vs. Decoder (Configurator)

Der Configurator führt beim Scan per Telnet u. a. `getmodel.sh` aus und leitet daraus die Rolle ab:

- TX / Transmitter / Encoder => Gerät wird als Encoder angeboten
- RX / Receiver / Decoder    => Gerät wird als Decoder angeboten
- UNKNOWN                    => keine automatische Anlage (bewusst konservativ)

Zusätzlich wird `astparam g webname` ausgelesen; bei Encoder-Instanzen wird empfohlen:
WebName == SourceName (global eindeutig).


## SymBox-Hinweis
Bei Problemen mit Modul-Caching: Repository ggf. entfernen und SymBox rebooten.
