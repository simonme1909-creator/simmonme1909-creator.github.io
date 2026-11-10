# AP2 – Browserbasiertes Gebäudeautomations-Dashboard

Technische Auswertung und Kapitelentwurf für die Dokumentation zum Betrieblichen Auftrag.
Grundlage: `AP2_HTML_DASHBOARD_V4_FINAL.php` (157 Zeilen, ca. 160 KB, davon ca. 140 KB eingebettetes Logo).

> **Stand:** Die in A.8 genannten Schwächen sind in Version 5 behoben (`AP2_HTML_DASHBOARD_V5.php`). Erklärung und Änderungsliste siehe `AP2_Dashboard_V5_Code_Erklaerung.md`. Dieses Dokument beschreibt den Stand V4 und eignet sich als „Ist-Analyse“ vor der Überarbeitung.

> **Hinweis zur Vollständigkeit:** Ausgewertet wurde nur diese eine Datei. Die Datenquelle (`/hook/ap2-data`), die Symcon-Skripte, KNX-/DALI-Anbindung und die CATAN-Konfiguration liegen nicht vor. Alles dazu ist aus dem Frontend abgeleitet und mit `[ANPASSEN]` markiert, wo du echte Werte aus deinem Projekt einsetzen musst.

---

## Teil A – Technische Auswertung

### A.1 Gesamtarchitektur

```
 Sensorik/Aktorik        Steuerung / Server            Webserver              Browser
 ┌────────────┐   KNX   ┌───────────────────┐  HTTP   ┌──────────────┐       ┌──────────────┐
 │ Präsenz,   │────────►│ Phoenix Contact   │ Webhook │ /hook/       │ JSON  │ Dashboard    │
 │ Temp, Lux  │  DALI   │ CATAN C1 + Symcon │────────►│ ap2-data     │◄──────│ (HTML/CSS/JS)│
 │ Leuchten   │◄───────►│ (Logik, Automatik)│         │              │ 1 s   │ Polling      │
 └────────────┘         └───────────────────┘         └──────────────┘       └──────────────┘
```

Die PHP-Datei ist nur die **Darstellungsschicht** (Bedien- und Beobachtungsebene). Die Automatiklogik (Präsenz, Klima, Beschattung, Licht) läuft nicht hier, sondern auf der CATAN C1 / in Symcon. Das Dashboard liest nur und zeigt an.

### A.2 Aufbau der Datei

| Zeilen | Inhalt | Zweck |
|---|---|---|
| 3 | `declare(strict_types=1)` | Strikte Typprüfung in PHP |
| 5–8 | `header(...)` | Zeichensatz UTF-8 und **Caching komplett aus** (`no-store`, `no-cache`, `Pragma`, `Expires`), damit nie ein veralteter Stand angezeigt wird |
| 10 | `const PHOENIX_LOGO_DATA_URI` | Logo als Base64-PNG direkt im Code (Data-URI) |
| 12–149 | `$html = <<<'HTML' … HTML;` | Komplette Seite als Nowdoc (kein PHP-Parsing im Inhalt) |
| 20–66 | `<style>` | CSS: Design-Variablen, Layout, Responsive, Zustandsfarben |
| 69–118 | HTML-Gerüst | Kopfzeile, Hero-Kennzahlen, 4 Funktionskarten, Systemdiagnose, Footer |
| 120–146 | `<script>` | Abfrage der Daten und Aktualisierung der Anzeige |
| 151–156 | PHP-Nachlauf | Logo einsetzen (HTML-escaped), Fallback „PC“-Marke, Ausgabe per `echo` |

### A.3 Frontend-Logik (JavaScript) im Detail

**Konfiguration (Z. 122):**
`dataUrl: '/hook/ap2-data'`, `actionUrl: '/hook/ap2-action'`, `pollMs: 1000`, `timeoutMs: 1800`, `controls: false`.

**Hilfsfunktionen:**

| Funktion | Aufgabe |
|---|---|
| `$` | Kurzform für `getElementById` |
| `set(id, wert, fallback)` | Setzt Text per `textContent`, bei leer/`null` den Platzhalter `--` |
| `fmt(wert, stellen)` | Zahl auf Nachkommastellen formatieren, sonst `--` |
| `clamp(wert, min, max)` | Wert begrenzen (z. B. 0–100 %) |
| `dot(id, status)` | Statusleuchte grün / rot / gelb |
| `badge(id, typ, text)` | Beschriftetes Status-Label |
| `color(id, text)` | Färbt einen Wert anhand von Schlüsselwörtern (STÖR/FEHLER → rot, HEIZ → heat, KÜHL → blau, NACHLAUF/WARN → gelb, OK/SOLL/BELEGT/EIN/ERKANNT → grün) |

**Kernablauf:**

1. `load()` ruft per `fetch` die Daten ab (`cache:'no-store'`), mit `AbortController` und **1,8 s Timeout**.
2. Erfolg → `render(json)` aktualisiert alle Felder und setzt `lastOk` (Zeitstempel der letzten gültigen Antwort).
3. Fehler → erst wenn die letzte gültige Antwort **älter als 3,5 s** ist, wird `offline()` aufgerufen. Einzelne verlorene Pakete führen so nicht sofort zur Fehleranzeige.
4. Im `finally` wird der nächste Abruf per `setTimeout(load, 1000)` geplant. Es gibt immer nur **eine** Anfrage gleichzeitig; Anfragen können sich nicht stapeln, wenn die Steuerung langsam antwortet.
5. Die Uhr im Footer läuft unabhängig im Browser (`setInterval`, 1 s).

**Offline-Verhalten (`offline()`):** Die Seite bekommt die Klasse `data-stale` (Karten ausgegraut, Werte grau, Diagnose gelb umrandet), alle Diagnoseleuchten gehen auf „unbekannt“, Bedienelemente werden gesperrt. Ein Ausfall ist damit deutlich sichtbar und man liest keine veralteten Werte als aktuell.

### A.4 Datenvertrag (aus dem JS abgeleitet)

Der Webhook muss JSON in dieser Form liefern:

```json
{
  "timestamp": 1731240000,
  "presence": { "roomState": "BELEGT", "detected": true, "lux": 420, "runOnSeconds": 0, "connected": true },
  "climate":  { "sensorOk": true, "actual": 21.4, "setpoint": 21.0, "state": "SOLL", "season": "Winter" },
  "shading":  { "level": 35, "voltage": 4.2, "sensorOk": true },
  "lighting": { "on": true, "actualPercent": 80, "targetPercent": 80, "connected": true },
  "system":   { "catan": true, "sensors": true, "knx": true, "dali": true, "fault": false }
}
```

Alle Zugriffe nutzen Optional Chaining (`d.presence?.…`). Fehlende Blöcke lassen die Seite also nicht abstürzen. `timestamp` wird als Unix-Zeit in **Sekunden** erwartet (`*1000`). `[ANPASSEN: mit der tatsächlichen Webhook-Ausgabe abgleichen]`

### A.5 Funktionsbereiche

| Bereich | Angezeigt | Quelle/Status-Badge |
|---|---|---|
| Hero-Leiste | Gesamtstatus („Anlage betriebsbereit“ / „Störung vorhanden“), Raumtemperatur, Raumstatus, Lichtniveau | `system.fault`, `climate.actual`, `presence.roomState`, `lighting.actualPercent` |
| Präsenz | Raumstatus, Präsenz (ERKANNT/FREI), Lux, Nachlaufzeit | Badge „KNX Live“ / „Vorbereitet“ |
| Raumklima | Ist-/Solltemperatur, Klimazustand, Betriebsart (Saison) | Badge „Sensor OK“ / „Sensorfehler“; bei Sensorausfall `N/V` |
| Beschattung | Stufe in % (mit Balken Offen→Geschlossen), Sensorspannung in V | Badge „Automatik“ / „Sensorfehler“ |
| Smart Lights | Status, Helligkeit Ist/Soll, Buttons Ein/Aus, Slider | Badge „DALI Live“ / „Vorbereitet“ |
| Systemdiagnose | CATAN C1, Sensorik, KNX, DALI, Gesamtstatus, Zeit der letzten Aktualisierung | Ampelpunkte |

### A.6 Design und Responsive Verhalten

- Farbwelt über CSS-Variablen in `:root` (`--pc-teal`, `--ok`, `--fault` …) zentral änderbar.
- Layout per CSS Grid: Hero 4 Spalten → 2 → 1; Karten 2 Spalten → 1; Diagnose 5 → 3 → 2 → 1 Spalten. Breakpoints bei 1100, 820 und 610 px.
- Sticky Kopfzeile (auf Smartphones statisch), Fortschrittsbalken mit CSS-Transition.
- Statusfarben immer **zusätzlich zu Text** (nicht nur Farbe), das hilft bei Farbsehschwäche.

### A.7 Sicherheit

Positiv:
- Dynamische Daten werden fast durchgehend per `textContent` geschrieben, nicht als HTML. Damit ist kein XSS über manipulierte Sensor-/Statuswerte möglich.
- Die einzige `innerHTML`-Stelle (`heroState`) setzt feste, im Code stehende Strings.
- Das Logo wird in PHP mit `htmlspecialchars` ausgegeben.
- Das Dashboard ist **nur lesend** (`controls:false`), es kann nichts an der Anlage verstellt werden.

Zu beachten:
- Der Endpunkt `/hook/ap2-data` ist im Code ohne Authentifizierung angesprochen. Für ein Messe-/Showcase-Netz im Labor akzeptabel, im Produktivnetz nicht. `[ANPASSEN: Netzsegmentierung / Zugriffsschutz in deinem Aufbau]`
- Falls die Steuerfunktionen später freigeschaltet werden, braucht `actionUrl` zwingend Authentifizierung, Eingabevalidierung und Begrenzung der Werte serverseitig. Clientseitiges `clamp` schützt nicht.

### A.8 Kritische Bewertung (ehrlich)

**Stärken**
1. Robuste Verbindungsüberwachung (Timeout, Toleranzzeit, keine überlappenden Requests, klarer Offline-Zustand).
2. Die ganze Anwendung steckt in **einer** Datei, ohne Bibliotheken, ohne Build. Einfach zu deployen.
3. Saubere Trennung von Anzeige und Logik (die Steuerung rechnet, der Browser zeigt).
4. Responsive und gut lesbar.

**Schwächen / Fehler im Code**
1. **Bug – Einheit bei Sensorausfall:** `set('tempUnit','')` setzt wegen des Fallbacks in `set()` den Text `--` statt leer. Bei Sensorfehler steht dann „N/V --“ statt „N/V“.
2. **`null` wird zu 0:** `fmt(null)` ergibt `0.0`, weil `Number(null)` gleich 0 ist. Liefert die Steuerung bei fehlendem Wert `null`, zeigt das Dashboard fälschlich „0“ statt „--“. Nur `undefined` wird korrekt zu `--`. `clamp` hat dasselbe Problem (fehlende Beschattung wird als 0 % „offen“ angezeigt). Bei Sicherheits- oder Prozessanzeigen ist das irreführend.
3. **Toter Code:** `actionUrl` wird nirgends verwendet, und für Ein/Aus/Slider existieren keine Event-Handler. Mit `controls:true` würden die Bedienelemente aktiv, aber nichts bewirken. Das gehört entweder fertig implementiert oder entfernt.
4. **Technische Schuld im CSS:** Ab Zeile 51 überschreibt ein zweiter Block den ersten („Messe-Look“) teilweise. Das ist schwer wartbar und sollte zusammengeführt werden.
5. **PHP ist praktisch überflüssig:** Es ersetzt nur das Logo und setzt Header. Eine statische `.html` plus Header im Webserver würde reichen. Das 140-KB-Base64-Logo bläht die Datei auf und lässt sich nicht cachen. (Das ist wegen der Cache-Header ohnehin so gewollt, aber das Logo wird bei jedem Aufruf mitgesendet.)
6. **Polling statt Push:** 1 Anfrage/s pro Browser. Für wenige Clients unkritisch, bei vielen Besuchern auf der Messe belastet es die CATAN. WebSocket/SSE oder ein serverseitiger Cache wären sauberer.
7. **`color()` per Textsuche:** Ein Teilstring wie „OK“ kann unbeabsichtigt in anderen Texten greifen. Besser ein fester Statuscode aus der Steuerung statt Freitext.
8. **Barrierefreiheit:** Keine `aria-live`-Bereiche, Statuswechsel werden von Screenreadern nicht angesagt.
9. **Nicht belegbar aus dieser Datei:** Ob KNX/DALI-Werte, Nachlauf, Heiz-/Kühllogik und Saisonumschaltung korrekt funktionieren. Das hängt komplett an der Steuerung und muss durch Tests nachgewiesen werden (siehe Kapitel Abnahme).

**Zum Branding:** Logo, Farben und der Slogan stammen aus dem Phoenix-Contact-Umfeld. Für die Dokumentation gilt: Quelle/Genehmigung des Ausbilders bzw. Betriebs nennen und keine Rechte Dritter beanspruchen. `[ANPASSEN]`

---

## Teil B – Kapitelentwurf für die Dokumentation

> Gliederung ist ein Vorschlag, passe sie an die Vorgaben deines Prüfungsausschusses an. Alles in `[…]` ist von dir zu ergänzen, ich kenne diese Fakten nicht und erfinde sie nicht.

### X Visualisierung der Gebäudeautomation

#### X.1 Ausgangssituation und Zielsetzung

Die Gebäudeautomation des Raumes [Raumbezeichnung] umfasst eine präsenzabhängige Beleuchtung (KNX/DALI), eine Raumklimaregelung, eine lichtabhängige Beschattung sowie eine zentrale Steuerung auf Basis der Phoenix Contact CATAN C1. Es fehlte eine übersichtliche Möglichkeit, den Anlagenzustand ohne Spezialsoftware einzusehen.

Ziel war eine **browserbasierte Bedien- und Beobachtungsebene**, die
- alle relevanten Prozesswerte in Echtzeit anzeigt,
- Anlagen- und Kommunikationsstörungen eindeutig sichtbar macht,
- auf PC, Tablet und Smartphone ohne Installation nutzbar ist und
- die Anlage in der ersten Ausbaustufe **nur lesend** beobachtet (keine Eingriffsmöglichkeit).

#### X.2 Anforderungen

| Nr. | Anforderung | Art |
|---|---|---|
| A1 | Anzeige von Raumtemperatur, Solltemperatur, Klimazustand, Betriebsart | funktional |
| A2 | Anzeige von Präsenz, Raumstatus, Helligkeit (Lux), Nachlaufzeit | funktional |
| A3 | Anzeige von Beschattungsstufe und Sensorspannung | funktional |
| A4 | Anzeige von Lichtstatus und Helligkeit Ist/Soll | funktional |
| A5 | Diagnose von CATAN, Sensorik, KNX, DALI, Gesamtstörung | funktional |
| A6 | Aktualisierung im Sekundentakt | Leistung |
| A7 | Eindeutige Anzeige bei Verbindungsverlust, keine veralteten Werte als aktuell | Zuverlässigkeit |
| A8 | Darstellung auf Desktop und mobilen Geräten | Bedienbarkeit |
| A9 | Keine externen Bibliotheken, ein Deployment-Artefakt | Wartbarkeit |
| A10 | Lesender Zugriff, kein Fernschalten | Sicherheit |

#### X.3 Systemarchitektur und Entscheidungen

Die Visualisierung besteht aus einer einzelnen PHP-Datei, die eine HTML-Seite mit eingebettetem CSS und JavaScript ausliefert. Der Browser ruft im Sekundentakt per HTTP (`fetch`) einen Webhook (`/hook/ap2-data`) ab, der den aktuellen Anlagenzustand als JSON liefert. Die Steuerung selbst ([Symcon/CATAN-Logik]) bleibt unverändert. Die Visualisierung greift nicht in die Regelung ein.

Entscheidungen:

| Entscheidung | Begründung | Alternative (verworfen) |
|---|---|---|
| Browser-Oberfläche statt Spezialsoftware | Plattformunabhängig, keine Installation | Native HMI/Panel-Software |
| Einzeldatei ohne Frameworks | Einfaches Deployment, keine Abhängigkeiten, kein Internetzugriff nötig | React/Vue mit Build-Prozess |
| Polling (1 s) | Einfach, robust, ohne Zusatzdienste | WebSocket/SSE (aufwendiger) `[ANPASSEN]` |
| Reine Anzeige in V4 | Risikoarm, Betrieb der Anlage nicht gefährdet | Sofortige Fernbedienung |
| Caching deaktiviert | Anzeige stets aktuell | Standard-Caching |

#### X.4 Umsetzung

**Datenabruf und Aktualisierung.** Ein Abruf läuft mit einem Timeout von 1,8 s. Erst nach dem Ende (erfolgreich oder fehlgeschlagen) wird der nächste in 1 s geplant. Dadurch können sich keine Anfragen stauen, wenn die Steuerung langsam antwortet.

**Fehlertoleranz.** Antwortet die Steuerung kurzzeitig nicht, bleibt die Anzeige zunächst bestehen. Erst nach mehr als 3,5 s ohne gültige Daten wechselt die Oberfläche in den Zustand „CATAN nicht erreichbar“: Karten werden ausgegraut, Statusleuchten auf „unbekannt“ gesetzt und Bedienelemente gesperrt.

**Darstellung der Zustände.** Prozesswerte werden je nach Zustand farblich hervorgehoben (z. B. grün für in Ordnung, gelb für Nachlauf/Warnung, rot für Störung). Farbe wird immer durch Text ergänzt. Für Sensorausfälle wird „N/V“ (nicht verfügbar) statt eines falschen Zahlenwertes angezeigt.

**Aufbau der Oberfläche.** Kopfzeile mit Verbindungsstatus; Kennzahlenleiste (Temperatur, Raumstatus, Lichtniveau, Gesamtstatus); vier Funktionskarten (Präsenz, Raumklima, Beschattung, Smart Lights); Systemdiagnose mit fünf Statusanzeigen; Fußzeile mit Uhrzeit.

**Responsives Layout.** Per CSS Grid passt sich die Seite an drei Breitenstufen (1100/820/610 px) an, von vierspaltig auf einspaltig.

**Sicherheitsaspekte.** Dynamische Werte werden ausschließlich als Text ausgegeben (`textContent`), nicht als HTML. Manipulierte Messwerte können so keinen Code einschleusen. Der Zugriff ist rein lesend. `[ANPASSEN: Netzwerkabsicherung ergänzen]`

#### X.5 Test und Abnahme

Vorschlag für Testfälle, die du tatsächlich durchführen und mit Ergebnis eintragen musst:

| Nr. | Test | Erwartung | Ergebnis |
|---|---|---|---|
| T1 | Normalbetrieb, Werte mit Steuerung vergleichen | Anzeige = Steuerungswerte | `[ ]` |
| T2 | Person betritt/verlässt Raum | Präsenz und Raumstatus wechseln, Nachlauf zählt | `[ ]` |
| T3 | Temperatursensor abziehen | „N/V“, Badge „Sensorfehler“ | `[ ]` |
| T4 | Netzwerkkabel zur CATAN ziehen | Nach ca. 3,5 s „CATAN nicht erreichbar“, Ausgrauen | `[ ]` |
| T5 | Verbindung wiederherstellen | Automatische Rückkehr zu „Live verbunden“ | `[ ]` |
| T6 | KNX/DALI-Ausfall simulieren | Entsprechende Diagnoseleuchte wechselt | `[ ]` |
| T7 | Darstellung auf Handy/Tablet | Layout einspaltig, alles lesbar | `[ ]` |
| T8 | Ändern der Beleuchtung an der Anlage | Anzeige folgt innerhalb ca. 1–2 s | `[ ]` |

Hinweis: T3 prüft zugleich den in A.8 genannten Anzeigefehler (Einheit „--“ bei Sensorausfall).

#### X.6 Bewertung, Grenzen und Ausblick

**Erreicht:** A1–A10 sind mit dem Dashboard abgedeckt, sofern der Webhook den beschriebenen Datenvertrag liefert. `[nach Test bestätigen]`

**Grenzen:**
- Reine Anzeige, die Bedienelemente (Licht Ein/Aus, Helligkeitsregler) sind vorbereitet, aber nicht aktiviert.
- Polling erzeugt pro Browser eine Anfrage pro Sekunde; bei vielen gleichzeitigen Nutzern steigt die Last auf der Steuerung.
- Fehlende Werte (`null`) können als „0“ angezeigt werden und müssen im Webhook bzw. Frontend abgefangen werden.
- Kein Zugriffsschutz im Frontend, die Absicherung muss durch das Netz erfolgen.

**Ausblick:**
1. Fehlerkorrekturen (Einheit bei Sensorausfall, Behandlung von `null`).
2. Bedienfunktionen mit Authentifizierung und serverseitiger Prüfung der Werte.
3. Umstellung von Polling auf Server-Sent Events oder WebSocket.
4. Historie/Trends der Messwerte, Alarmierung bei Störung.
5. Barrierefreiheit (`aria-live`) und Zusammenführen des CSS.

#### X.7 Wirtschaftlichkeit / Zeit `[ANPASSEN]`

Soll-/Ist-Zeitplanung, Personalkosten, Materialkosten und Amortisation gehören laut Vorgabe in dein eigenes Kapitel. Die Datei liefert dazu keine Daten. Ein Vorteil, den du argumentieren kannst: Es entstehen keine Lizenzkosten, weil keine kommerzielle Visualisierungssoftware oder Bibliothek nötig ist.

---

## Anhang – Code-Verweise

| Thema | Zeile |
|---|---|
| Cache-Header | 5–8 |
| Logo-Konstante | 10 |
| CSS-Variablen | 21–29 |
| Zweiter CSS-Block („Messe-Look“) | 51–64 |
| Konfiguration (`CONFIG`) | 122 |
| `render()` | 131–141 |
| `offline()` | 142 |
| `load()` (Polling, Timeout) | 143 |
| Logo-Einsatz in PHP | 151–155 |
