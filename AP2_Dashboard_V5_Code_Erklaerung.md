# Web-Dashboard Gebäudeautomation – Version 5

Ausarbeitung zum Code `AP2_HTML_DASHBOARD_V5.php` für die Dokumentation und das Fachgespräch zum Betrieblichen Auftrag (AP2).

> Alles mit `[ANPASSEN]` musst du mit deinen eigenen Projektdaten füllen.
> Getestet wurde die Datei lokal mit PHP 8.3, Chromium und einem nachgebauten Webhook. Ein Test an der echten CATAN C1 steht noch aus und muss von dir durchgeführt werden (siehe Kapitel 8).

---

## Inhalt

1. Überblick
2. Was gegenüber V4 verbessert wurde
3. Aufbau der Datei
4. PHP-Teil
5. HTML-Teil
6. CSS-Teil
7. JavaScript-Teil
8. Test und Inbetriebnahme
9. Grenzen und Ausblick
10. Vorbereitung Fachgespräch (Fragen und Antworten)
11. Glossar

---

## 1. Überblick

Das Dashboard ist die **Bedien- und Beobachtungsebene** der Anlage. Es zeigt im Browser an, was die Steuerung (Phoenix Contact CATAN C1 mit Symcon) über Präsenz, Raumklima, Beschattung, Beleuchtung und Systemzustand meldet.

**Grundsatz:** Das Dashboard ist **nur lesend**. Es schaltet nichts. Die gesamte Regelungslogik bleibt in der Steuerung. Fällt das Dashboard aus, läuft die Anlage unverändert weiter.

```
 Feldebene               Automatisierungsebene         Webserver           Browser
 ┌─────────────┐  KNX   ┌──────────────────────┐ JSON  ┌─────────────┐     ┌─────────────────┐
 │ Präsenz,    │───────►│ CATAN C1 + Symcon    │──────►│ Webhook     │◄────│ Dashboard V5    │
 │ Temperatur, │  DALI  │ (Regelung, Automatik)│       │ /hook/      │ 1 s │ (diese Datei)   │
 │ Licht, Jal. │◄──────►│                      │       │ ap2-data    │     │                 │
 └─────────────┘        └──────────────────────┘       └─────────────┘     └─────────────────┘
```

**Ablauf in einem Satz:** PHP liefert einmal die Seite aus. Danach fragt das JavaScript im Browser jede Sekunde den Webhook ab und trägt die Werte in die Seite ein.

---

## 2. Was gegenüber V4 verbessert wurde

Das ist die wichtigste Tabelle für die Dokumentation: Sie zeigt, dass du deinen eigenen Code kritisch geprüft und verbessert hast.

| Nr. | Problem in V4 | Lösung in V5 | Warum wichtig |
|---|---|---|---|
| 1 | Code in sehr langen Zeilen (bis 650 Zeichen), kaum lesbar | Sauber formatiert, nummerierte Abschnitte, Kommentare in Deutsch | Wartbarkeit, Nachvollziehbarkeit |
| 2 | Ein fehlender Wert (`null`) wurde als **0** angezeigt | Funktion `zahl()` gibt `null` zurück, Anzeige `--` | Eine falsche „0“ ist in der Prozessvisualisierung irreführend |
| 3 | Bei Sensorfehler stand „N/V --“ statt „N/V“ | Einheit wird direkt geleert | Anzeigefehler |
| 4 | Fehlte `system.fault`, wurde „Anlage betriebsbereit“ angezeigt | Drei Zustände: ja / nein / **unbekannt** | Kein falscher Gut-Zustand („fail-safe“) |
| 5 | Störung im Hero-Bereich wurde nicht rot, weil `!important` im CSS die Farbe überschrieb | Farbe über CSS-Klassen `ok` / `warn` / `err` | Störungen müssen auffallen |
| 6 | Hängt die Steuerung, kamen alte Werte weiter, Anzeige blieb „Live“ | Prüfung, ob sich der **Zeitstempel** noch ändert, sonst „Daten veraltet“ | Erkennt eingefrorene Daten |
| 7 | Buttons und Slider vorhanden, aber ohne Funktion (toter Code) | Entfernt, Sollhelligkeit als Balken | Kein halbfertiger Code |
| 8 | Zwei CSS-Blöcke, der zweite überschrieb den ersten | Zu einem Block zusammengeführt | Weniger Fehler bei Änderungen |
| 9 | Inline-Styles und `innerHTML` | Nur CSS-Klassen und `textContent` | Grundlage für Sicherheitsregel (CSP) |
| 10 | Keine Sicherheits-Header | Content-Security-Policy mit Nonce, `nosniff`, `no-referrer` | Schutz vor eingeschleustem Code |
| 11 | Abfrage auch bei verstecktem Browser-Tab | Pause, wenn Tab nicht sichtbar | Entlastet die Steuerung |
| 12 | Tests nur mit echter Anlage möglich | **Demo-Modus** mit 5 Szenarien | Alle Fehlerfälle reproduzierbar testbar und vorführbar |
| 13 | Keine Hinweise für Screenreader | `role="status"`, `aria-live`, Überschriften `h2` | Barrierefreiheit |
| 14 | 140 KB Logo-Text am Dateianfang | Logo in Funktion `logoHtml()` am Dateiende | Code oben sofort lesbar |

---

## 3. Aufbau der Datei

Die Datei besteht aus vier Sprachen, die nacheinander kommen:

| Teil | Aufgabe | Wo |
|---|---|---|
| **PHP** (oben) | Einstellungen, HTTP-Header, Sicherheitsschlüssel | Abschnitt 1–2 im Kopf |
| **CSS** | Aussehen: Farben, Layout, Handy-Ansicht | `<style>`, Abschnitte 1–10 |
| **HTML** | Gerüst der Seite: Kopfzeile, Karten, Diagnose | `<body>` |
| **JavaScript** | Daten holen, anzeigen, Verbindung überwachen | `<script>`, Abschnitte 1–9 |
| **PHP** (unten) | Funktion `logoHtml()` mit eingebettetem Logo | Dateiende |

**Warum eine einzige Datei?** Sie lässt sich mit einem Schritt auf den Server kopieren, braucht keine Bibliotheken, keinen Build-Prozess und keinen Internetzugang. Für eine Anlage im abgeschotteten Netz ist das ein Vorteil. Nachteil: Die Datei ist länger. Deshalb ist sie in nummerierte Abschnitte gegliedert.

---

## 4. PHP-Teil

### 4.1 `declare(strict_types=1);`

Schaltet die strenge Typprüfung ein. PHP wandelt dann z. B. keinen Text stillschweigend in eine Zahl um, wenn eine Funktion eine Zahl erwartet. Das verhindert versteckte Fehler.

### 4.2 Einstellung `DEMO_ERLAUBT`

```php
const DEMO_ERLAUBT = true;
```

Schalter für den Demo-Modus. Im Produktivbetrieb auf `false` setzen. Der Wert wird später ins JavaScript übergeben:

```php
const DEMO_ERLAUBT = <?= DEMO_ERLAUBT ? 'true' : 'false' ?>;
```

`<?= … ?>` ist die Kurzform von `<?php echo … ?>`. PHP schreibt also `true` oder `false` direkt in den JavaScript-Code. Das ist die einzige Stelle, an der PHP und JavaScript „miteinander reden“.

### 4.3 HTTP-Header

Header sind Zusatzinformationen, die der Server vor der eigentlichen Seite an den Browser schickt.

| Header | Bedeutung |
|---|---|
| `Content-Type: text/html; charset=utf-8` | Es ist eine HTML-Seite, Umlaute in UTF-8 |
| `Cache-Control: no-store …`, `Pragma`, `Expires` | Seite nicht zwischenspeichern, immer frisch laden |
| `X-Content-Type-Options: nosniff` | Browser soll den Dateityp nicht „erraten“ |
| `Referrer-Policy: no-referrer` | Beim Aufruf anderer Seiten wird die eigene Adresse nicht mitgeschickt |
| `Content-Security-Policy` | Regeln, was der Browser laden und ausführen darf (siehe 4.4) |

### 4.4 Content-Security-Policy (CSP) mit Nonce

```php
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-{$nonce}'; ...");
```

```html
<script nonce="<?= $nonce ?>"> … </script>
```

**Einfach erklärt:** Bei jedem Seitenaufruf erzeugt PHP einen zufälligen Einmal-Schlüssel (Nonce = „number used once“). Der Browser führt nur Skripte und Styles aus, die genau diesen Schlüssel tragen. Gelingt es einem Angreifer, Code in die Seite zu schmuggeln, kennt er den Schlüssel nicht, und der Browser blockiert den Code.

| Regel | Bedeutung |
|---|---|
| `default-src 'self'` | Grundsätzlich nur Inhalte vom eigenen Server |
| `script-src 'nonce-…'` | Nur Skripte mit dem Schlüssel |
| `style-src 'nonce-…'` | Nur Styles mit dem Schlüssel |
| `img-src 'self' data:` | Bilder vom eigenen Server oder eingebettet (Logo) |
| `connect-src 'self'` | `fetch()` nur zum eigenen Server (Webhook) |
| `base-uri 'none'`, `form-action 'none'` | Keine Umleitung der Basisadresse, keine Formulare |

Das ist **zusätzlicher** Schutz. Der Hauptschutz ist, dass alle Werte mit `textContent` geschrieben werden (siehe 7.3).

> **Wenn die Seite auf deinem Server weiß bleibt:** Vermutlich blockiert die CSP etwas, oder der Server setzt eigene Regeln. In der Browser-Konsole (F12) steht dann eine Meldung mit „Content Security Policy“. Zum Testen kann die `header("Content-Security-Policy…")`-Zeile auskommentiert werden. Dann aber in der Doku erwähnen.

### 4.5 Funktion `logoHtml()` am Dateiende

Das Logo ist als Base64-Text (ca. 140 KB) direkt in der Datei gespeichert („Data-URI“). Dadurch braucht der Server keine Bilddatei.

Die Funktion steht am Ende, obwohl sie oben im HTML aufgerufen wird. Das geht, weil PHP beim Start die ganze Datei einliest und alle Funktionen schon kennt, bevor es den Code ausführt. So stört der lange Logo-Text nicht beim Lesen.

`htmlspecialchars()` wandelt Sonderzeichen (`"`, `<`, `>`) in harmlose HTML-Zeichen um. So kann der Text das `<img>`-Tag nicht „aufbrechen“. Ist kein Logo hinterlegt, erscheint ein Ersatzlogo aus Text.

---

## 5. HTML-Teil

### 5.1 Gliederung der Seite

```
header.topbar        Logo, Titel, Verbindungsanzeige
div.demo-banner      nur im Demo-Modus sichtbar
section.hero         Gesamtstatus + 3 Kennzahlen (Temperatur, Raumstatus, Licht)
main.grid
  section.card       Präsenz
  section.card       Raumklima
  section.card       Beschattung
  section.card       Smart Lights (nur Anzeige)
  section.card       Systemdiagnose (CATAN, Sensorik, KNX, DALI, Gesamtstatus)
footer               Uhrzeit
```

### 5.2 Prinzip: Platzhalter mit `id`

Jeder Wert, der sich ändert, steht in einem Element mit eindeutiger `id`:

```html
<span id="tempActual">--</span><span id="tempUnit" class="unit">°C</span>
```

Das JavaScript sucht das Element über die `id` und ersetzt `--` durch den echten Wert. `--` ist zugleich die Anzeige für „kein Wert vorhanden“.

### 5.3 Barrierefreiheit

- `lang="de"`: Screenreader lesen deutsch vor.
- Kartentitel sind echte Überschriften (`<h2>`), die Seite hat dadurch eine Gliederung.
- `role="status" aria-live="polite"` bei Verbindungs- und Gesamtstatus: Ändert sich der Text, wird er automatisch vorgelesen.
- Zustände werden nie **nur** über Farbe gezeigt, immer auch als Text (wichtig bei Rot-Grün-Schwäche).

---

## 6. CSS-Teil

### 6.1 Design-Variablen

```css
:root {
  --pc-teal: #00a9ad;
  --ok:      #008f77;
  --fault:   #d73b32;
  ...
}
```

Alle Farben stehen einmal zentral oben. Verwendet werden sie mit `var(--ok)`. Will man eine Farbe ändern, ändert man sie an einer Stelle.

Jede Karte bekommt über eine Klasse ihre Akzentfarbe:

```css
.card-climate { --section: var(--pc-orange); }
```

Der farbige Balken oben und der linke Rand der Messwerte nutzen `var(--section)` und färben sich automatisch passend.

### 6.2 Layout mit CSS Grid

```css
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
```

Zwei gleich breite Spalten mit 16 px Abstand. `minmax(0, 1fr)` verhindert, dass lange Texte eine Spalte breiter drücken.

### 6.3 Ampelfarben

| Klasse | Farbe | Bedeutung |
|---|---|---|
| `.dot.ok` | grün | in Ordnung |
| `.dot.warn` | gelb | unbekannt / Warnung / noch nicht verbunden |
| `.dot.err` | rot | Störung / offline |
| `.state-ok`, `.state-heat`, `.state-info`, `.state-warn`, `.state-fault` | Textfarbe für Zustandstexte (z. B. HEIZEN = orange) |

### 6.4 Zustand „Daten nicht aktuell“

```css
body.data-stale .card:not(.diagnostics),
body.data-stale .hero { opacity: .58; filter: grayscale(.55); }
```

Setzt das JavaScript die Klasse `data-stale` auf `<body>`, werden alle Karten außer der Diagnose ausgegraut. So sieht man sofort, dass die Werte nicht aktuell sind, die Diagnose bleibt aber gut lesbar.

### 6.5 Responsive Design

Mit `@media (max-width: …)` passt sich die Seite an die Bildschirmbreite an:

| Breite | Hero | Karten | Diagnose |
|---|---|---|---|
| > 1100 px (Monitor) | 4 Spalten | 2 Spalten | 5 Spalten |
| ≤ 1100 px | 2 Spalten | 2 Spalten | 3 Spalten |
| ≤ 820 px (Tablet) | 2 Spalten | 1 Spalte | 2 Spalten |
| ≤ 610 px (Handy) | 1 Spalte | 1 Spalte | 1 Spalte |

Getestet bei 390 px Breite (iPhone): Es gibt keinen waagerechten Bildlauf.

---

## 7. JavaScript-Teil

### 7.1 `'use strict';`

Strenger Modus: Tippfehler bei Variablennamen führen zu einer Fehlermeldung statt zu stillen Fehlern.

### 7.2 Einstellungen (Abschnitt 1)

```js
const DATEN_URL            = '/hook/ap2-data';
const ABFRAGE_INTERVALL_MS = 1000;
const ANFRAGE_TIMEOUT_MS   = 1800;
const OFFLINE_NACH_MS      = 3500;
const VERALTET_NACH_MS     = 10000;
```

Alle Zeitwerte stehen als benannte Konstanten oben, statt als „magische Zahlen“ im Code verteilt. Begründung der Werte:

| Konstante | Wert | Begründung |
|---|---|---|
| Abfrageintervall | 1 s | Präsenz und Licht sollen ohne spürbare Verzögerung erscheinen. Temperatur wäre auch mit 10 s ausreichend `[ANPASSEN: eigene Begründung]` |
| Timeout | 1,8 s | Kleiner als die Offline-Grenze, damit ein hängender Request rechtzeitig abgebrochen wird |
| Offline nach | 3,5 s | Etwa zwei bis drei verpasste Abfragen. Einzelne Aussetzer im Netz lösen noch keinen Alarm aus |
| Veraltet nach | 10 s | Deutlich länger als das Intervall, damit kurze Verzögerungen in der Steuerung keinen Fehlalarm auslösen |

### 7.3 Hilfsfunktionen (Abschnitt 3)

**`zahl(wert)`**: der wichtigste Baustein.

```js
function zahl(wert) {
  if (wert === null || wert === undefined || wert === '') {
    return null;
  }
  const n = Number(wert);
  return Number.isFinite(n) ? n : null;
}
```

In JavaScript ergibt `Number(null)` den Wert **0**. Ohne diese Prüfung würde ein ausgefallener Sensor als „0 °C“ oder „0 %“ erscheinen. Die Funktion liefert stattdessen `null`, und die Anzeige zeigt `--`.

**`formatZahl(wert, stellen)`**: rundet auf feste Nachkommastellen (`toFixed`), sonst `--`.

**`setzeText(id, text)`**: schreibt Text in ein Element.

```js
el(id).textContent = leer ? '--' : String(text);
```

`textContent` behandelt alles als reinen Text. Selbst wenn in den Daten `<img src=x onerror=alert(1)>` stünde, wird es nur als Zeichenfolge angezeigt und nicht ausgeführt. Das wurde getestet (siehe 8.2, T9). `innerHTML` würde den Code ausführen (Cross-Site-Scripting, XSS).

**`setzeBalken`, `setzePunkt`, `setzeBadge`**: setzen Breite bzw. CSS-Klasse. Das Aussehen steht nur im CSS, das JavaScript wählt nur die Klasse aus.

**`faerbeNachZustand(id, text)` mit Regeltabelle:**

```js
const FARB_REGELN = [
  { wort: 'STÖR',    klasse: 'state-fault' },
  ...
  { wort: 'EIN',     klasse: 'state-ok', genau: true }
];
```

Die Steuerung liefert Zustände als Text (z. B. „HEIZEN“, „STÖRUNG“). Die Funktion geht die Tabelle von oben nach unten durch und nimmt die **erste** passende Regel. Darum steht „STÖR“ oben: Ein Text mit Störung soll immer rot sein, auch wenn er zusätzlich „OK“ enthält. `genau: true` bedeutet, dass der Text exakt passen muss. Sonst würde „EIN“ auch bei „KEIN“ greifen.

Vorteil der Tabelle: Neue Zustände ergänzt man mit einer Zeile, ohne die Logik anzufassen.

### 7.4 Anzeigefunktionen (Abschnitt 4)

Für jeden Bereich gibt es eine eigene Funktion:

| Funktion | Daten | Zeigt an |
|---|---|---|
| `zeigePraesenz(p)` | `daten.presence` | Raumstatus, Präsenz, Lux, Nachlauf, Badge KNX |
| `zeigeKlima(k)` | `daten.climate` | Ist/Soll, Zustand, Betriebsart, Sensorstatus |
| `zeigeBeschattung(b)` | `daten.shading` | Stufe, Spannung, Balken, Sensorstatus |
| `zeigeBeleuchtung(l)` | `daten.lighting` | Ein/Aus, Ist, Soll-Balken, Badge DALI |
| `zeigeSystem(s)` | `daten.system` | Diagnose und Gesamtstatus |

Aufruf mit Absicherung:

```js
zeigeKlima(daten.climate || {});
```

Fehlt `climate` in den Daten, bekommt die Funktion ein leeres Objekt `{}`. Alle Werte sind dann `undefined` und werden als `--` angezeigt. Die Seite stürzt nicht ab.

**Sensorfehler beim Klima:** Meldet die Steuerung `sensorOk: false`, wird statt einer Zahl `N/V` (nicht verfügbar) angezeigt und die Einheit ausgeblendet. Der letzte (falsche) Wert bleibt also nicht stehen.

**Drei Zustände bei der Diagnose (`setzeDiagnose`):**

| Wert aus der Steuerung | Anzeige |
|---|---|
| `true` | grün, z. B. „Online“ |
| `false` | rot oder gelb, z. B. „Offline“ |
| fehlt / unbekannt | gelb, „Unbekannt“ |

Gleiches gilt für die Störmeldung: Nur ein ausdrückliches `fault: false` ergibt „Anlage betriebsbereit“. Fehlt der Wert, steht dort „Status unbekannt“. Das entspricht dem Grundsatz der Automatisierungstechnik, dass ein **fehlendes Signal nie als Gut-Meldung** gewertet werden darf (vergleichbar mit Drahtbruchsicherheit bzw. Ruhestromprinzip).

### 7.5 Verbindungsüberwachung (Abschnitt 5)

Das Dashboard kennt drei Verbindungszustände:

```
                 Antwort ok, Zeitstempel ändert sich
           ┌──────────────────────────────────────────┐
           ▼                                          │
     ┌───────────┐  Zeitstempel > 10 s gleich  ┌──────────────┐
     │   LIVE    │────────────────────────────►│   VERALTET   │
     │  (grün)   │◄────────────────────────────│   (gelb)     │
     └───────────┘  Zeitstempel ändert sich    └──────────────┘
        │    ▲                                        │
        │    │ Antwort wieder ok                      │
        │    │                                        │
        ▼    │   > 3,5 s keine gültige Antwort        ▼
     ┌─────────────────────────────────────────────────────┐
     │                    OFFLINE (rot)                    │
     └─────────────────────────────────────────────────────┘
```

| Zustand | Bedingung | Anzeige |
|---|---|---|
| **Live** | Antwort kommt, Zeitstempel ändert sich | grün „Live verbunden“ |
| **Veraltet** | Antwort kommt, aber der Zeitstempel ändert sich seit über 10 s nicht | gelb „Daten veraltet“, Karten ausgegraut |
| **Offline** | Über 3,5 s keine gültige Antwort | rot „CATAN nicht erreichbar“, Diagnose auf „unbekannt“, Karten ausgegraut |

**Warum „veraltet“ zusätzlich zu „offline“?** Es kann sein, dass der Webserver antwortet, das Skript in der Steuerung aber hängt und immer dieselben alten Werte liefert. Nur am Antworten erkennt man das nicht. Am Zeitstempel schon.

**Warum nicht mit der Browser-Uhr vergleichen?** Die Uhr der Steuerung und die des Tablets/PCs können voneinander abweichen. Ein direkter Vergleich würde dann fälschlich „veraltet“ melden. Deshalb wird nur geprüft, ob sich der Zeitstempel **ändert**.

```js
function datenSindVeraltet(zeitstempel) {
  const jetzt = Date.now();
  if (zeitstempel !== letzterZeitstempel) {   // neuer Wert -> Stoppuhr neu starten
    letzterZeitstempel = zeitstempel;
    zeitstempelSeit = jetzt;
  }
  return (jetzt - zeitstempelSeit) > VERALTET_NACH_MS;
}
```

> **Hinweis:** Erzeugt der Webhook den Zeitstempel bei jeder Anfrage neu (z. B. `time()` im Symcon-Skript), ändert er sich immer und die Veraltet-Erkennung greift nie. Sinnvoll ist ein Zeitstempel, der den Zeitpunkt der letzten **Messwertänderung bzw. Aktualisierung in der Steuerung** angibt. `[ANPASSEN: prüfen, wie dein Webhook den Zeitstempel erzeugt]`

### 7.6 Daten verarbeiten (Abschnitt 6)

`zeigeDaten(daten)` prüft zuerst, ob überhaupt ein Objekt angekommen ist (Plausibilitätsprüfung). Dann ruft sie die fünf Anzeigefunktionen auf und entscheidet anhand des Zeitstempels, ob „Live“ oder „Veraltet“ angezeigt wird.

### 7.7 Daten abholen (Abschnitt 7)

```js
async function holeDaten() {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), ANFRAGE_TIMEOUT_MS);
  try {
    const antwort = await fetch(DATEN_URL, { cache: 'no-store', signal: controller.signal });
    if (!antwort.ok) throw new Error('HTTP-Fehler ' + antwort.status);
    return await antwort.json();
  } finally {
    clearTimeout(timer);
  }
}
```

| Element | Erklärung |
|---|---|
| `async` / `await` | Die Funktion wartet auf die Antwort, ohne den Browser zu blockieren. Die Seite bleibt bedienbar |
| `fetch()` | HTTP-GET-Anfrage an den Webhook |
| `cache: 'no-store'` | Keine zwischengespeicherte Antwort verwenden |
| `AbortController` | Bricht die Anfrage nach 1,8 s ab, damit sie nicht ewig hängt |
| `antwort.ok` | `true` bei HTTP-Status 200–299. Bei 404/500 wird ein Fehler ausgelöst |
| `antwort.json()` | Wandelt den JSON-Text in ein JavaScript-Objekt |
| `finally` | Läuft immer, auch bei Fehler. Hier wird der Timer gelöscht |

**Der Abfragezyklus:**

```js
async function abfrageZyklus() {
  if (!document.hidden) {
    try {
      const daten = await holeDaten();
      zeigeDaten(daten);
      letzteAntwortZeit = Date.now();
    } catch (fehler) {
      if (Date.now() - letzteAntwortZeit > OFFLINE_NACH_MS) {
        zeigeOffline();
      }
    }
  }
  setTimeout(abfrageZyklus, ABFRAGE_INTERVALL_MS);
}
```

**Warum `setTimeout` am Ende statt `setInterval`?** `setInterval` startet stur jede Sekunde eine neue Anfrage, auch wenn die alte noch läuft. Antwortet die Steuerung langsam, würden sich die Anfragen stauen und die Steuerung zusätzlich belasten. Hier startet die nächste Anfrage erst **1 s nach dem Ende** der vorherigen. Es läuft also nie mehr als eine Anfrage gleichzeitig.

**`document.hidden`:** Ist der Browser-Tab im Hintergrund oder minimiert, wird nicht abgefragt. Bei mehreren Tablets auf einer Messe spart das unnötige Last.

**Fehlertoleranz:** Schlägt eine Anfrage fehl, wird nicht sofort „offline“ angezeigt, sondern erst nach 3,5 s ohne Erfolg. Kurze Netzaussetzer führen so nicht zu Fehlalarmen.

### 7.8 Demo-Modus (Abschnitt 8)

Aufruf: `https://<server>/<pfad>/AP2_HTML_DASHBOARD_V5.php?demo=stoerung`

| Szenario | Was simuliert wird |
|---|---|
| `normal` | Normalbetrieb, Werte schwanken leicht |
| `sensorfehler` | Temperatur- und Helligkeitssensor ausgefallen |
| `stoerung` | Störmeldung aktiv, DALI ausgefallen |
| `veraltet` | Steuerung antwortet, Zeitstempel bleibt stehen |
| `offline` | Steuerung antwortet gar nicht |

Im Demo-Modus erscheint oben ein gelber gestrichelter Hinweis, damit niemand Simulationswerte für echte hält. Im Betrieb wird der Modus mit `DEMO_ERLAUBT = false` abgeschaltet.

**Nutzen:** Alle Fehlerfälle lassen sich jederzeit reproduzierbar vorführen und testen, ohne Sensoren abzuklemmen oder die Anlage zu stören.

### 7.9 Start (Abschnitt 9)

Demo-Hinweis einblenden (falls aktiv), Uhr starten, ersten Abfragezyklus starten. Danach läuft alles von selbst.

### 7.10 Datenformat des Webhooks (Schnittstelle)

Das Dashboard erwartet diese JSON-Struktur. Alle Felder sind optional, fehlende Werte werden als `--` bzw. „Unbekannt“ angezeigt.

```json
{
  "timestamp": 1731240000,
  "presence": { "roomState": "BELEGT", "detected": true, "lux": 420, "runOnSeconds": 0, "connected": true },
  "climate":  { "sensorOk": true, "actual": 21.4, "setpoint": 21.0, "state": "HEIZEN", "season": "Winter" },
  "shading":  { "sensorOk": true, "level": 35, "voltage": 4.2 },
  "lighting": { "connected": true, "on": true, "actualPercent": 80, "targetPercent": 80 },
  "system":   { "catan": true, "sensors": true, "knx": true, "dali": true, "fault": false }
}
```

| Feld | Typ | Einheit / Bedeutung |
|---|---|---|
| `timestamp` | Zahl | Unix-Zeit in **Sekunden** |
| `presence.roomState` | Text | z. B. BELEGT, FREI, NACHLAUF |
| `presence.detected` | true/false | Präsenzmelder hat jemanden erkannt |
| `presence.lux` | Zahl | Beleuchtungsstärke in lx |
| `presence.runOnSeconds` | Zahl | Restliche Nachlaufzeit in s |
| `climate.actual` / `setpoint` | Zahl | Ist-/Solltemperatur in °C |
| `climate.state` | Text | z. B. HEIZEN, KÜHLEN, SOLL ERREICHT, STÖRUNG |
| `climate.season` | Text | Betriebsart, z. B. Winter/Sommer |
| `shading.level` | Zahl | 0 = offen … 100 = geschlossen (%) |
| `shading.voltage` | Zahl | Spannung des Helligkeitssensors in V (z. B. 0–10 V) |
| `lighting.actualPercent` / `targetPercent` | Zahl | Ist-/Sollhelligkeit in % |
| `system.*` | true/false | Diagnose der Komponenten, `fault` = Sammelstörung |

`[ANPASSEN: mit der echten Ausgabe deines Webhooks abgleichen, z. B. Browser → /hook/ap2-data aufrufen]`

---

## 8. Test und Inbetriebnahme

### 8.1 Inbetriebnahme

1. Datei an dieselbe Stelle wie V4 kopieren (gleicher Server, damit `/hook/ap2-data` erreichbar ist).
2. Mit `?demo=normal` aufrufen: Die Seite muss erscheinen, mit gelbem Demo-Hinweis.
3. Ohne Parameter aufrufen: Anzeige muss auf „Live verbunden“ wechseln.
4. Werte mit der Steuerung (Symcon-Konsole) vergleichen.
5. Für den Betrieb `DEMO_ERLAUBT = false` setzen.

### 8.2 Entwicklertest (durchgeführt)

Lokal mit PHP 8.3 (`php -S`), nachgebautem Webhook und Chromium (automatisiert mit Playwright):

| Nr. | Test | Erwartet | Ergebnis |
|---|---|---|---|
| T1 | PHP-Syntaxprüfung (`php -l`) | keine Fehler | bestanden |
| T2 | Normalbetrieb, Werte vom Webhook | alle Werte korrekt, „Live verbunden“ | bestanden |
| T3 | `level: null` (Beschattung) | `--`, Balken leer, **nicht** 0 | bestanden |
| T4 | Webhook liefert HTTP 503 | nach 1,5 s noch „Live“, nach 5 s „CATAN nicht erreichbar“ | bestanden |
| T5 | Webhook wieder erreichbar | automatisch zurück auf „Live“, Ausgrauen weg | bestanden |
| T6 | Demo `sensorfehler` | `N/V` ohne Einheit, Badge „Sensorfehler“ | bestanden |
| T7 | Demo `stoerung` | Gesamtstatus rot, DALI gelb, „STÖRUNG“ rot | bestanden |
| T8 | Demo `veraltet` | nach ca. 10 s „Daten veraltet“ | bestanden |
| T9 | Schadcode im Zustandstext (`<img onerror=…>`) | wird nur als Text angezeigt, kein Ausführen | bestanden |
| T10 | CSP aktiv | keine CSP-Fehlermeldungen in der Konsole | bestanden |
| T11 | Smartphone 390 px | einspaltig, kein waagerechter Bildlauf | bestanden |

### 8.3 Abnahmetest an der Anlage (von dir durchzuführen)

| Nr. | Test | Erwartet | Ergebnis |
|---|---|---|---|
| A1 | Raum betreten / verlassen | Präsenz ERKANNT/FREI, Nachlauf zählt runter | `[ ]` |
| A2 | Temperatursollwert in der Steuerung ändern | Anzeige folgt in ≤ 2 s | `[ ]` |
| A3 | Temperatursensor abklemmen | `N/V`, Badge „Sensorfehler“ | `[ ]` |
| A4 | Helligkeitssensor abdecken | Beschattungsstufe/Spannung ändern sich | `[ ]` |
| A5 | Licht an der Anlage schalten/dimmen | Lichtstatus und Ist-Helligkeit folgen | `[ ]` |
| A6 | Netzwerkkabel CATAN ziehen | nach ca. 3,5 s „CATAN nicht erreichbar“ | `[ ]` |
| A7 | Kabel wieder stecken | automatisch „Live verbunden“ | `[ ]` |
| A8 | KNX- bzw. DALI-Bus trennen | Diagnosepunkt wechselt | `[ ]` |
| A9 | Aufruf auf Tablet/Smartphone | Darstellung korrekt | `[ ]` |

---

## 9. Grenzen und Ausblick

**Grenzen (ehrlich benennen, das wirkt fachlich besser als verschweigen):**
- Nur Anzeige, keine Bedienung. Bewusste Entscheidung für Sicherheit und geringes Risiko.
- Kein Login im Dashboard. Zugriffsschutz muss über das Netzwerk erfolgen (eigenes VLAN/Netz, Firewall). `[ANPASSEN: wie ist es bei dir gelöst?]`
- Polling: jeder offene Browser fragt 1× pro Sekunde. Bei sehr vielen gleichzeitigen Nutzern steigt die Last auf der Steuerung.
- Zustandsfarben hängen am Text der Steuerung (z. B. „HEIZEN“). Ändert jemand die Texte in Symcon, stimmen die Farben ggf. nicht mehr.
- Keine Historie: Es wird nur der aktuelle Zustand angezeigt, keine Verläufe.

**Ausblick:**
1. Bedienfunktionen (Licht, Sollwert) mit Anmeldung und Prüfung der Werte **in der Steuerung**, nicht nur im Browser.
2. Server-Sent Events oder WebSocket statt Polling (Steuerung schickt nur bei Änderung).
3. Statuscodes statt Freitext vom Webhook (z. B. `"state": 2` für Heizen).
4. Trendkurven (Temperatur über den Tag), Alarmhistorie.
5. HTTPS mit Zertifikat, falls das Dashboard außerhalb des Labornetzes genutzt wird.

---

## 10. Vorbereitung Fachgespräch

**Warum PHP, wenn das meiste HTML/JavaScript ist?**
PHP setzt die HTTP-Header (Cache aus, Sicherheitsregeln), erzeugt den Sicherheitsschlüssel (Nonce) und bettet das Logo ein. Die Messwerte holt der Browser selbst per JavaScript. Dadurch muss die Seite nur einmal geladen werden.

**Wie kommen die Daten von der Anlage in den Browser?**
Sensoren → KNX/DALI → CATAN C1/Symcon → Webhook liefert JSON → Browser holt es jede Sekunde per `fetch()` → JavaScript schreibt die Werte in die Seite.

**Was ist JSON?**
Ein Textformat für strukturierte Daten aus Schlüssel-Wert-Paaren, z. B. `{"actual": 21.4}`. Es ist leicht lesbar und wird von JavaScript direkt verstanden.

**Was passiert, wenn die Steuerung ausfällt?**
Einzelne Aussetzer werden toleriert. Nach 3,5 s ohne Antwort zeigt das Dashboard „CATAN nicht erreichbar“, graut alle Werte aus und setzt die Diagnose auf „unbekannt“. Kommt die Verbindung zurück, wird automatisch wieder „Live“ angezeigt.

**Woran erkennst du, dass die Steuerung zwar antwortet, aber hängt?**
Am Zeitstempel. Ändert er sich über 10 s nicht, wird „Daten veraltet“ angezeigt.

**Warum wird ein fehlender Wert nicht als 0 angezeigt?**
`Number(null)` ergibt in JavaScript 0. Eine falsche 0 (z. B. 0 °C) kann zu Fehlentscheidungen führen. Deshalb prüft `zahl()` das vorher und es wird `--` angezeigt.

**Warum „unbekannt“ statt „in Ordnung“, wenn kein Störsignal kommt?**
Fail-safe-Prinzip: Ein fehlendes Signal darf nie als Gut-Meldung gelten, so wie beim Ruhestromprinzip oder der Drahtbruchüberwachung.

**Wie schützt du die Seite vor Schadcode?**
Erstens werden alle Werte mit `textContent` geschrieben, also nie als HTML ausgeführt. Zweitens erlaubt die Content-Security-Policy nur Skripte mit dem zufälligen Schlüssel dieses Seitenaufrufs. Drittens ist die Seite nur lesend und kann nichts an der Anlage verändern.

**Was ist der Unterschied zwischen `setInterval` und deinem `setTimeout`-Zyklus?**
`setInterval` startet starr jede Sekunde, auch wenn die vorige Anfrage noch läuft. Mein Zyklus startet die nächste Abfrage erst, wenn die vorige fertig ist. So stauen sich keine Anfragen.

**Warum hast du die Buttons aus V4 entfernt?**
Sie waren ohne Funktion. Halbfertige Bedienelemente verwirren den Nutzer. Eine Bedienung braucht Anmeldung und eine Prüfung der Werte in der Steuerung. Das ist als Ausblick dokumentiert.

**Wie testest du Fehlerfälle, ohne die Anlage zu stören?**
Mit dem Demo-Modus (`?demo=sensorfehler`, `stoerung`, `veraltet`, `offline`). Zusätzlich habe ich an der echten Anlage die Abnahmetests durchgeführt `[ANPASSEN]`.

**Warum ist das Logo als langer Text in der Datei?**
Base64-Data-URI: Das Bild ist als Text eingebettet, man braucht keine zweite Datei auf dem Server. Nachteil: Die Datei wird ca. 140 KB größer.

**Was bedeutet responsive?**
Die Seite passt ihr Layout an die Bildschirmbreite an (CSS Grid und Media Queries). Auf dem Handy ist sie einspaltig, am Monitor mehrspaltig.

---

## 11. Glossar

| Begriff | Erklärung |
|---|---|
| Webhook | URL auf dem Server, die beim Aufruf ein Skript ausführt und hier die Anlagendaten als JSON liefert |
| JSON | Textformat für Daten (JavaScript Object Notation) |
| Polling | Regelmäßiges Nachfragen, ob es neue Daten gibt |
| `fetch()` | JavaScript-Funktion für HTTP-Anfragen |
| `async`/`await` | Warten auf ein Ergebnis, ohne die Seite einzufrieren |
| Timeout | Maximale Wartezeit, danach Abbruch |
| DOM | Die Seite als Baum von Elementen, den JavaScript ändern kann |
| `textContent` | Setzt reinen Text in ein Element (sicher) |
| XSS | Cross-Site-Scripting: Einschleusen von Schadcode in eine Webseite |
| CSP | Content-Security-Policy: Regeln, was der Browser ausführen darf |
| Nonce | Einmal-Zufallswert, hier der Schlüssel für erlaubte Skripte |
| Cache | Zwischenspeicher des Browsers |
| Data-URI | Datei (z. B. Bild) als Text direkt im Code |
| Responsive | Anpassung an verschiedene Bildschirmgrößen |
| Fail-safe | Bei Fehler oder fehlender Information in den sicheren Zustand gehen |
