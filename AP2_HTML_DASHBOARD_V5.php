<?php
/**
 * ============================================================================
 *  Gebäudeautomation – Web-Dashboard (Bedien- und Beobachtungsebene)
 * ============================================================================
 *  Projekt : AP2 – Betrieblicher Auftrag            [ANPASSEN]
 *  Autor   : [Name]                                 [ANPASSEN]
 *  Version : 5.0 – [Datum]                          [ANPASSEN]
 *
 *  Aufgabe
 *    Diese Datei liefert eine HTML-Seite aus, die den Zustand der Anlage
 *    (CATAN C1, Sensorik, KNX, DALI) im Browser anzeigt.
 *    Die Seite ist NUR LESEND: Sie schaltet nichts, sie zeigt nur an.
 *
 *  Ablauf
 *    1. PHP setzt die HTTP-Header (kein Cache, Sicherheitsregeln).
 *    2. PHP gibt die HTML-Seite mit CSS und JavaScript aus.
 *    3. Im Browser fragt das JavaScript jede Sekunde den Webhook
 *       /hook/ap2-data ab und erhält die Messwerte als JSON.
 *    4. Das JavaScript schreibt die Werte in die Seite und überwacht,
 *       ob die Verbindung steht und ob die Daten aktuell sind.
 *
 *  Demo-Modus (Test und Präsentation ohne Anlage)
 *    Aufruf mit  ?demo=normal | sensorfehler | stoerung | veraltet | offline
 * ============================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Einstellungen
// ---------------------------------------------------------------------------

// Demo-Modus erlauben? Für den Produktivbetrieb auf false setzen.
const DEMO_ERLAUBT = true;

// ---------------------------------------------------------------------------
// 2. HTTP-Header
// ---------------------------------------------------------------------------

// Zufälliger Einmal-Schlüssel ("Nonce"). Nur <script>/<style>-Blöcke mit
// genau diesem Schlüssel darf der Browser ausführen (siehe CSP unten).
$nonce = base64_encode(random_bytes(16));

header('Content-Type: text/html; charset=utf-8');

// Kein Zwischenspeichern: Der Browser soll immer den aktuellen Stand laden.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Sicherheits-Header
header('X-Content-Type-Options: nosniff');   // Dateityp nicht "erraten"
header('Referrer-Policy: no-referrer');      // keine Adresse an Dritte weitergeben
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "                 // grundsätzlich nur eigener Server
    . "script-src 'nonce-{$nonce}'; "        // nur Skripte mit unserem Schlüssel
    . "style-src 'nonce-{$nonce}'; "         // nur Styles mit unserem Schlüssel
    . "img-src 'self' data:; "               // Bilder: eigener Server + eingebettetes Logo
    . "connect-src 'self'; "                 // fetch() nur zum eigenen Server
    . "base-uri 'none'; form-action 'none'"
);

// Ab hier wird HTML ausgegeben. PHP-Werte werden mit der Kurzschreibweise
// "<?=" eingesetzt (entspricht "<?php echo").
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#008f77">
<title>Gebäudeautomation</title>

<style nonce="<?= $nonce ?>">
/* ==========================================================================
   1. Design-Variablen (Farben, Abstände) – zentral an einer Stelle änderbar
   ========================================================================== */
:root {
  /* Markenfarben */
  --pc-teal:      #00a9ad;
  --pc-teal-dark: #007e82;
  --pc-teal-soft: #e3f8f8;
  --pc-cyan:      #12b8c5;
  --pc-lime:      #8dcc32;
  --pc-orange:    #ec7c23;

  /* Text, Linien, Flächen */
  --ink:       #162629;
  --muted:     #687b7f;
  --line:      #d4dfe1;
  --surface:   #ffffff;
  --surface-2: #f4f8f8;

  /* Zustandsfarben (Ampel) */
  --ok:      #008f77;
  --warn:    #e8ad18;
  --fault:   #d73b32;
  --unknown: #7a898c;

  --shadow:      0 14px 34px rgba(24, 56, 60, .13);
  --shadow-soft: 0 6px 18px rgba(24, 56, 60, .09);
  --radius:      12px;
}

/* ==========================================================================
   2. Grundlayout
   ========================================================================== */
* { box-sizing: border-box; }

html, body {
  margin: 0;
  min-height: 100%;
  color: var(--ink);
  font-family: "Segoe UI", Arial, sans-serif;
}

body { background: linear-gradient(135deg, #f8fafb 0, #e8eff0 55%, #e3ebec 100%); }

.shell {
  max-width: 1540px;
  margin: 0 auto;
  padding: 22px;
}

/* ==========================================================================
   3. Kopfzeile
   ========================================================================== */
.topbar {
  position: sticky;
  top: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 15px 28px;
  background: rgba(255, 255, 255, .97);
  border-top: 7px solid var(--pc-teal);
  box-shadow: 0 6px 22px rgba(24, 56, 60, .12);
}

/* Farbverlauf als Linie unter der Kopfzeile */
.topbar::after {
  content: "";
  position: absolute;
  left: 0;
  right: 0;
  bottom: -1px;
  height: 3px;
  background: linear-gradient(90deg, var(--pc-teal), var(--pc-cyan) 48%, var(--pc-lime));
}

.brand { display: flex; align-items: center; gap: 18px; min-width: 0; }

.brand-logo {
  display: flex;
  align-items: center;
  min-width: 250px;
  height: 54px;
  padding: 3px 5px;
  background: #fff;
  border-radius: 3px;
}
.brand-logo img { display: block; max-width: 246px; max-height: 52px; object-fit: contain; }

/* Ersatzlogo, falls kein Bild hinterlegt ist */
.brand-fallback { display: flex; align-items: center; gap: 9px; color: var(--pc-teal-dark); font-weight: 800; }
.brand-fallback-mark {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  border-radius: 5px;
  background: var(--pc-teal);
  color: #fff;
  font-size: 20px;
}

.brand-divider { width: 1px; height: 46px; background: var(--line); }

.eyebrow {
  color: var(--pc-teal-dark);
  font-size: .72rem;
  font-weight: 800;
  letter-spacing: .16em;
  text-transform: uppercase;
}
.project h1 { margin: 2px 0; font-size: clamp(1.25rem, 2vw, 1.9rem); line-height: 1.12; }
.subtitle { color: var(--muted); font-size: .86rem; }

/* Verbindungsanzeige oben rechts */
.live-pill {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: linear-gradient(180deg, #fff, #f0f8f8);
  border: 1px solid #b9dada;
  border-radius: 999px;
  white-space: nowrap;
}

/* Hinweis im Demo-Modus */
.demo-banner {
  margin-bottom: 16px;
  padding: 10px 14px;
  border: 2px dashed var(--warn);
  border-radius: 6px;
  background: #fff8df;
  color: #8a6500;
  font-weight: 700;
}

/* ==========================================================================
   4. Statuspunkte (Ampel)
   ========================================================================== */
.dot {
  flex: none;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--unknown);
}
.dot.ok   { background: var(--ok);    box-shadow: 0 0 0 4px rgba(0, 143, 119, .12), 0 0 12px rgba(0, 143, 119, .5); }
.dot.warn { background: var(--warn);  box-shadow: 0 0 0 4px rgba(232, 173, 24, .12); }
.dot.err  { background: var(--fault); box-shadow: 0 0 0 4px rgba(215, 59, 50, .12), 0 0 12px rgba(215, 59, 50, .4); }

/* ==========================================================================
   5. Kennzahlen-Leiste ("Hero")
   ========================================================================== */
.hero {
  display: grid;
  grid-template-columns: 1.6fr repeat(3, .72fr);
  gap: 14px;
  margin-bottom: 16px;
}

.hero-main {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 18px 20px;
  color: #fff;
  border-radius: var(--radius);
  background: linear-gradient(105deg, #05a9ae 0%, #20b7b8 43%, #8dcc32 100%);
  box-shadow: 0 16px 34px rgba(0, 153, 158, .24);
}
.hero-title { font-size: 1.18rem; font-weight: 800; }
.hero-copy  { margin-top: 4px; font-size: .87rem; color: rgba(255, 255, 255, .9); }

/* Gesamtstatus im Hero – Farbe über Klasse ok / warn / err */
.hero-state {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 6px;
  font-weight: 750;
  white-space: nowrap;
  background: rgba(255, 255, 255, .92);
  color: var(--muted);
  box-shadow: 0 5px 16px rgba(0, 0, 0, .1);
}
.hero-state.ok  { color: var(--pc-teal-dark); }
.hero-state.err { color: var(--fault); background: #fff2f1; }

.hero-kpi {
  padding: 15px 17px;
  background: var(--surface);
  border: 1px solid var(--line);
  border-top: 4px solid var(--kpi-farbe);
  border-radius: var(--radius);
  box-shadow: var(--shadow-soft);
}
.kpi-temp  { --kpi-farbe: var(--pc-orange); }
.kpi-room  { --kpi-farbe: var(--pc-lime); }
.kpi-light { --kpi-farbe: var(--pc-cyan); }

.kpi-label, .metric-label {
  color: var(--muted);
  font-size: .7rem;
  font-weight: 700;
  letter-spacing: .09em;
  text-transform: uppercase;
}
.kpi-value { margin-top: 7px; font-size: 1.65rem; font-weight: 760; }
.kpi-unit, .unit { margin-left: 3px; font-size: .8rem; font-weight: 600; color: var(--muted); }

/* ==========================================================================
   6. Funktionskarten
   ========================================================================== */
.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.card {
  --section: var(--pc-teal);             /* Akzentfarbe, je Karte überschrieben */
  overflow: hidden;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
}
.card::before {
  content: "";
  display: block;
  height: 6px;
  background: linear-gradient(90deg, var(--pc-teal), var(--pc-cyan) 52%, var(--section));
}
.card-presence { --section: var(--pc-lime); }
.card-climate  { --section: var(--pc-orange); }
.card-shading  { --section: var(--pc-cyan); }
.card-light    { --section: var(--pc-teal); }

.card-body { padding: 18px 20px 20px; }

.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}
.card-title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
  font-size: .92rem;
  font-weight: 800;
  letter-spacing: .065em;
  text-transform: uppercase;
}
.section-mark { width: 11px; height: 11px; border-radius: 2px; background: var(--section); }

/* Status-Label rechts in der Kartenüberschrift */
.badge {
  padding: 5px 9px;
  border: 1px solid var(--line);
  border-radius: 4px;
  background: var(--surface-2);
  color: var(--muted);
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
}
.badge.good { color: var(--pc-teal-dark); background: #eaf7f4; border-color: #b5ddd4; }
.badge.bad  { color: var(--fault);        background: #fff2f1; border-color: #efbbb7; }
.badge.wait { color: #8a6500;             background: #fff8df; border-color: #ebd999; }

/* Einzelner Messwert */
.metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 11px;
}
.metric {
  min-height: 96px;
  padding: 14px 15px;
  background: linear-gradient(145deg, #f9fbfb, #f1f6f6);
  border: 1px solid var(--line);
  border-left: 3px solid var(--section);
  border-radius: 5px;
}
.metric-value {
  margin-top: 9px;
  font-size: clamp(1.35rem, 2.2vw, 2.08rem);
  font-weight: 760;
  line-height: 1.08;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Textfarbe je nach Anlagenzustand (gesetzt per JavaScript) */
.state-ok    { color: var(--ok); }
.state-info  { color: var(--pc-cyan); }
.state-warn  { color: var(--warn); }
.state-heat  { color: var(--pc-orange); }
.state-fault { color: var(--fault); }

/* Fortschrittsbalken (Beschattung, Helligkeit Soll) */
.process { margin-top: 14px; }
.process-label {
  display: flex;
  justify-content: space-between;
  margin-bottom: 7px;
  color: var(--muted);
  font-size: .76rem;
}
.track { height: 9px; overflow: hidden; border-radius: 2px; background: #dce6e7; }
.fill {
  display: block;
  width: 0;
  height: 100%;
  background: linear-gradient(90deg, var(--pc-teal-dark), var(--pc-teal), var(--pc-lime));
  transition: width .45s ease;
}

/* ==========================================================================
   7. Systemdiagnose
   ========================================================================== */
.diagnostics { grid-column: 1 / -1; }

.diag-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 10px;
}
.diag {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 13px;
  background: #f8fbfb;
  border: 1px solid var(--line);
  border-radius: 5px;
}
.diag strong { display: block; font-size: .85rem; }
.diag small  { display: block; margin-top: 2px; color: var(--muted); font-size: .75rem; }

/* ==========================================================================
   8. Fußzeile
   ========================================================================== */
.footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 12px;
  margin-top: 14px;
  padding: 0 3px;
  color: var(--muted);
  font-size: .76rem;
}
.footer strong { color: var(--pc-teal-dark); }

/* ==========================================================================
   9. Zustand "Daten nicht aktuell": alles ausgrauen
   ========================================================================== */
body.data-stale .card:not(.diagnostics),
body.data-stale .hero         { opacity: .58; filter: grayscale(.55); }
body.data-stale .metric-value,
body.data-stale .kpi-value    { color: var(--unknown) !important; }
body.data-stale .diagnostics  { border-color: var(--warn); }

/* ==========================================================================
   10. Anpassung an kleinere Bildschirme (Tablet, Smartphone)
   ========================================================================== */
@media (max-width: 1100px) {
  .hero       { grid-template-columns: 1fr 1fr; }
  .hero-main  { grid-column: 1 / -1; }
  .diag-grid  { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 820px) {
  .shell         { padding: 14px; }
  .brand-logo    { min-width: 0; }
  .brand-divider { display: none; }
  .grid          { grid-template-columns: 1fr; }
  .diag-grid     { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 610px) {
  .topbar     { position: relative; flex-direction: column; align-items: flex-start; padding: 14px 16px; }
  .brand      { flex-wrap: wrap; width: 100%; }
  .brand-logo { width: 100%; max-width: 240px; }
  .live-pill  { width: 100%; }
  .shell      { padding: 10px; }
  .hero       { grid-template-columns: 1fr; }
  .hero-main  { flex-direction: column; align-items: flex-start; }
  .metrics,
  .diag-grid  { grid-template-columns: 1fr; }
  .card-body  { padding: 16px; }
}
</style>
</head>

<body>

<!-- ======================================================================
     Kopfzeile: Logo, Projekttitel, Verbindungsstatus
     ====================================================================== -->
<header class="topbar">
  <div class="brand">
    <div class="brand-logo"><?= logoHtml() ?></div>
    <div class="brand-divider"></div>
    <div class="project">
      <div class="eyebrow">Building Automation · Showcase</div>
      <h1>Gebäudeautomation</h1>
      <div class="subtitle">CATAN C1 · KNX · DALI · Symcon · Browserbasierte Bedien- und Beobachtungsebene</div>
    </div>
  </div>

  <!-- role="status": Screenreader lesen Änderungen automatisch vor -->
  <div class="live-pill" role="status" aria-live="polite">
    <span id="connectionDot" class="dot warn"></span>
    <span id="connectionText">Verbindung wird aufgebaut</span>
  </div>
</header>

<div class="shell">

  <!-- Nur sichtbar im Demo-Modus -->
  <div id="demoBanner" class="demo-banner" hidden>
    DEMO-MODUS – angezeigt werden simulierte Werte, keine echten Anlagendaten.
  </div>

  <!-- ====================================================================
       Kennzahlen-Leiste
       ==================================================================== -->
  <section class="hero">
    <div class="hero-main">
      <div>
        <div class="hero-title">Technische Lösungen für eine lebenswerte Zukunft</div>
        <div class="hero-copy">Gebäudeautomation mit CATAN C1: offen, vernetzt und transparent vom Sensor bis zur browserbasierten Visualisierung.</div>
      </div>
      <div id="heroState" class="hero-state" role="status" aria-live="polite">
        <span id="heroDot" class="dot warn"></span>
        <span id="heroText">System wird geprüft</span>
      </div>
    </div>

    <div class="hero-kpi kpi-temp">
      <div class="kpi-label">Raumtemperatur</div>
      <div class="kpi-value"><span id="heroTemp">--</span><span id="heroTempUnit" class="kpi-unit">°C</span></div>
    </div>
    <div class="hero-kpi kpi-room">
      <div class="kpi-label">Raumstatus</div>
      <div id="heroRoom" class="kpi-value">--</div>
    </div>
    <div class="hero-kpi kpi-light">
      <div class="kpi-label">Lichtniveau</div>
      <div class="kpi-value"><span id="heroLight">--</span><span class="kpi-unit">%</span></div>
    </div>
  </section>

  <main class="grid">

    <!-- ==================================================================
         Präsenz
         ================================================================== -->
    <section class="card card-presence">
      <div class="card-body">
        <div class="card-head">
          <h2 class="card-title"><span class="section-mark"></span>Präsenz</h2>
          <span id="presenceBadge" class="badge wait">Vorbereitet</span>
        </div>
        <div class="metrics">
          <div class="metric">
            <div class="metric-label">Raumstatus</div>
            <div id="roomState" class="metric-value">--</div>
          </div>
          <div class="metric">
            <div class="metric-label">Präsenz erkannt</div>
            <div id="presence" class="metric-value">--</div>
          </div>
          <div class="metric">
            <div class="metric-label">Beleuchtungsstärke</div>
            <div class="metric-value"><span id="lux">--</span><span class="unit">lx</span></div>
          </div>
          <div class="metric">
            <div class="metric-label">Nachlauf</div>
            <div class="metric-value"><span id="runOn">--</span><span class="unit">s</span></div>
          </div>
        </div>
      </div>
    </section>

    <!-- ==================================================================
         Raumklima
         ================================================================== -->
    <section class="card card-climate">
      <div class="card-body">
        <div class="card-head">
          <h2 class="card-title"><span class="section-mark"></span>Raumklima</h2>
          <span id="climateBadge" class="badge">Status</span>
        </div>
        <div class="metrics">
          <div class="metric">
            <div class="metric-label">Isttemperatur</div>
            <div class="metric-value"><span id="tempActual">--</span><span id="tempUnit" class="unit">°C</span></div>
          </div>
          <div class="metric">
            <div class="metric-label">Solltemperatur</div>
            <div class="metric-value"><span id="tempSet">--</span><span class="unit">°C</span></div>
          </div>
          <div class="metric">
            <div class="metric-label">Klimazustand</div>
            <div id="climateState" class="metric-value">--</div>
          </div>
          <div class="metric">
            <div class="metric-label">Betriebsart</div>
            <div id="season" class="metric-value">--</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ==================================================================
         Beschattung
         ================================================================== -->
    <section class="card card-shading">
      <div class="card-body">
        <div class="card-head">
          <h2 class="card-title"><span class="section-mark"></span>Beschattung</h2>
          <span id="shadeBadge" class="badge">Automatik</span>
        </div>
        <div class="metrics">
          <div class="metric">
            <div class="metric-label">Beschattungsstufe</div>
            <div class="metric-value"><span id="shadeLevel">--</span><span class="unit">%</span></div>
          </div>
          <div class="metric">
            <div class="metric-label">Sensorspannung</div>
            <div class="metric-value"><span id="brightnessVoltage">--</span><span class="unit">V</span></div>
          </div>
        </div>
        <div class="process">
          <div class="process-label"><span>Offen</span><span>Geschlossen</span></div>
          <div class="track"><span id="shadeBar" class="fill"></span></div>
        </div>
      </div>
    </section>

    <!-- ==================================================================
         Beleuchtung (nur Anzeige)
         ================================================================== -->
    <section class="card card-light">
      <div class="card-body">
        <div class="card-head">
          <h2 class="card-title"><span class="section-mark"></span>Smart Lights</h2>
          <span id="lightBadge" class="badge wait">Vorbereitet</span>
        </div>
        <div class="metrics">
          <div class="metric">
            <div class="metric-label">Lichtstatus</div>
            <div id="lightState" class="metric-value">--</div>
          </div>
          <div class="metric">
            <div class="metric-label">Helligkeit Ist</div>
            <div class="metric-value"><span id="lightActual">--</span><span class="unit">%</span></div>
          </div>
        </div>
        <div class="process">
          <div class="process-label"><span>Helligkeit Soll</span><span id="lightTargetLabel">-- %</span></div>
          <div class="track"><span id="lightTargetBar" class="fill"></span></div>
        </div>
      </div>
    </section>

    <!-- ==================================================================
         Systemdiagnose
         ================================================================== -->
    <section class="card diagnostics">
      <div class="card-body">
        <div class="card-head">
          <h2 class="card-title"><span class="section-mark"></span>Systemdiagnose</h2>
          <span id="updatedBadge" class="badge">Keine Daten</span>
        </div>
        <div class="diag-grid">
          <div class="diag"><span id="catanDot"  class="dot warn"></span><div><strong>CATAN C1</strong><small id="catanText">Warte auf Daten</small></div></div>
          <div class="diag"><span id="sensorDot" class="dot warn"></span><div><strong>Sensorik</strong><small id="sensorText">Unbekannt</small></div></div>
          <div class="diag"><span id="knxDot"    class="dot warn"></span><div><strong>KNX</strong><small id="knxText">Unbekannt</small></div></div>
          <div class="diag"><span id="daliDot"   class="dot warn"></span><div><strong>DALI</strong><small id="daliText">Unbekannt</small></div></div>
          <div class="diag"><span id="faultDot"  class="dot warn"></span><div><strong>Gesamtstatus</strong><small id="faultText">Unbekannt</small></div></div>
        </div>
      </div>
    </section>

  </main>

  <footer class="footer">
    <span><strong>Gebäudeautomation</strong> · umgesetzt mit Phoenix Contact CATAN C1</span>
    <span id="clock">--</span>
  </footer>
</div>

<script nonce="<?= $nonce ?>">
'use strict';

/* ==========================================================================
   1. Einstellungen
   ========================================================================== */
const DATEN_URL            = '/hook/ap2-data'; // Webhook, der die Anlagendaten als JSON liefert
const ABFRAGE_INTERVALL_MS = 1000;   // Pause zwischen zwei Abfragen
const ANFRAGE_TIMEOUT_MS   = 1800;   // So lange wird höchstens auf eine Antwort gewartet
const OFFLINE_NACH_MS      = 3500;   // So lange ohne Antwort  -> "nicht erreichbar"
const VERALTET_NACH_MS     = 10000;  // So lange gleicher Zeitstempel -> "Daten veraltet"

// Wert kommt aus PHP (Konstante DEMO_ERLAUBT oben in der Datei)
const DEMO_ERLAUBT = <?= DEMO_ERLAUBT ? 'true' : 'false' ?>;

// Demo-Szenario aus der Adresse lesen, z. B. ...?demo=stoerung  (sonst null)
const DEMO_SZENARIO = DEMO_ERLAUBT ? new URLSearchParams(location.search).get('demo') : null;

/* ==========================================================================
   2. Zustand der Verbindungsüberwachung
   ========================================================================== */
let letzteAntwortZeit  = 0;     // Browserzeit der letzten gültigen Antwort
let letzterZeitstempel = null;  // Zeitstempel aus den letzten Daten
let zeitstempelSeit    = 0;     // Seit wann sich dieser Zeitstempel nicht geändert hat

/* ==========================================================================
   3. Hilfsfunktionen
   ========================================================================== */

// Kurzform, um ein Element über seine id zu holen
function el(id) {
  return document.getElementById(id);
}

// Wandelt einen Wert in eine Zahl um.
// Liefert null, wenn kein gültiger Wert vorliegt (null, leer, Text ...).
// Wichtig: Number(null) wäre 0 – ein fehlender Wert darf aber nicht als 0 erscheinen.
function zahl(wert) {
  if (wert === null || wert === undefined || wert === '') {
    return null;
  }
  const n = Number(wert);
  return Number.isFinite(n) ? n : null;
}

// Formatiert eine Zahl mit fester Anzahl Nachkommastellen, sonst "--"
function formatZahl(wert, stellen) {
  const n = zahl(wert);
  return n === null ? '--' : n.toFixed(stellen);
}

// Schreibt Text in ein Element. Leere Werte werden als "--" angezeigt.
// textContent statt innerHTML: Text wird nie als HTML-Code ausgeführt.
function setzeText(id, text) {
  const leer = (text === null || text === undefined || text === '');
  el(id).textContent = leer ? '--' : String(text);
}

// Begrenzt eine Zahl auf den Bereich min..max
function begrenze(n, min, max) {
  return Math.min(max, Math.max(min, n));
}

// Setzt einen Fortschrittsbalken auf 0..100 %. Ohne gültigen Wert: leer.
function setzeBalken(id, prozent) {
  const n = zahl(prozent);
  el(id).style.width = (n === null ? 0 : begrenze(n, 0, 100)) + '%';
}

// Setzt einen Statuspunkt: zustand = 'ok' (grün), 'warn' (gelb), 'err' (rot)
function setzePunkt(id, zustand) {
  el(id).className = 'dot ' + zustand;
}

// Setzt ein Status-Label: art = 'good', 'bad', 'wait' oder '' (neutral)
function setzeBadge(id, art, text) {
  el(id).className = art ? 'badge ' + art : 'badge';
  el(id).textContent = text;
}

// Regeln für die Textfarbe von Zustandstexten der Steuerung.
// Die Reihenfolge ist wichtig: Die erste passende Regel gewinnt,
// deshalb steht "Störung" ganz oben.
// genau: true  -> Text muss exakt passen (sonst würde "EIN" auch bei "KEIN" greifen)
const FARB_REGELN = [
  { wort: 'STÖR',     klasse: 'state-fault' },
  { wort: 'FEHLER',   klasse: 'state-fault' },
  { wort: 'AUSFALL',  klasse: 'state-fault' },
  { wort: 'HEIZ',     klasse: 'state-heat'  },
  { wort: 'KÜHL',     klasse: 'state-info'  },
  { wort: 'NACHLAUF', klasse: 'state-warn'  },
  { wort: 'WARN',     klasse: 'state-warn'  },
  { wort: 'OK',       klasse: 'state-ok'    },
  { wort: 'SOLL',     klasse: 'state-ok'    },
  { wort: 'BELEGT',   klasse: 'state-ok'    },
  { wort: 'ERKANNT',  klasse: 'state-ok', genau: true },
  { wort: 'EIN',      klasse: 'state-ok', genau: true }
];
const ALLE_FARB_KLASSEN = ['state-ok', 'state-info', 'state-warn', 'state-heat', 'state-fault'];

// Färbt ein Element passend zum Zustandstext ein
function faerbeNachZustand(id, text) {
  const element = el(id);
  const t = String(text ?? '').toUpperCase();

  element.classList.remove(...ALLE_FARB_KLASSEN);

  for (const regel of FARB_REGELN) {
    const passt = regel.genau ? (t === regel.wort) : t.includes(regel.wort);
    if (passt) {
      element.classList.add(regel.klasse);
      return;
    }
  }
}

/* ==========================================================================
   4. Anzeige der einzelnen Bereiche
      Jede Funktion bekommt nur "ihren" Teil der Daten. Fehlt der Teil
      komplett, wird ein leeres Objekt {} verwendet – die Seite stürzt
      dadurch nicht ab, sondern zeigt "--".
   ========================================================================== */

function zeigePraesenz(p) {
  setzeText('roomState', p.roomState);
  setzeText('heroRoom', p.roomState);
  faerbeNachZustand('roomState', p.roomState);
  faerbeNachZustand('heroRoom', p.roomState);

  let praesenz = '--';
  if (p.detected === true)  { praesenz = 'ERKANNT'; }
  if (p.detected === false) { praesenz = 'FREI'; }
  setzeText('presence', praesenz);
  faerbeNachZustand('presence', praesenz);

  setzeText('lux',   formatZahl(p.lux, 0));
  setzeText('runOn', formatZahl(p.runOnSeconds, 0));

  if (p.connected === true) {
    setzeBadge('presenceBadge', 'good', 'KNX Live');
  } else {
    setzeBadge('presenceBadge', 'wait', 'Vorbereitet');
  }
}

function zeigeKlima(k) {
  if (k.sensorOk === true) {
    setzeText('tempActual', formatZahl(k.actual, 1));
    setzeText('heroTemp',   formatZahl(k.actual, 1));
    el('tempUnit').textContent     = '°C';
    el('heroTempUnit').textContent = '°C';
    setzeBadge('climateBadge', 'good', 'Sensor OK');
  } else {
    // Sensor gestört: kein (falscher) Zahlenwert, sondern "nicht verfügbar"
    setzeText('tempActual', 'N/V');
    setzeText('heroTemp',   'N/V');
    el('tempUnit').textContent     = '';
    el('heroTempUnit').textContent = '';
    setzeBadge('climateBadge', 'bad', 'Sensorfehler');
  }

  setzeText('tempSet', formatZahl(k.setpoint, 1));
  setzeText('climateState', k.state);
  faerbeNachZustand('climateState', k.state);
  setzeText('season', k.season);
}

function zeigeBeschattung(b) {
  setzeText('shadeLevel', formatZahl(b.level, 0));
  setzeText('brightnessVoltage', formatZahl(b.voltage, 1));
  setzeBalken('shadeBar', b.level);

  if (b.sensorOk === true) {
    setzeBadge('shadeBadge', 'good', 'Automatik');
  } else {
    setzeBadge('shadeBadge', 'bad', 'Sensorfehler');
  }
}

function zeigeBeleuchtung(l) {
  let status = '--';
  if (l.on === true)  { status = 'EIN'; }
  if (l.on === false) { status = 'AUS'; }
  setzeText('lightState', status);
  faerbeNachZustand('lightState', status);

  setzeText('lightActual', formatZahl(l.actualPercent, 0));
  setzeText('heroLight',   formatZahl(l.actualPercent, 0));
  setzeText('lightTargetLabel', formatZahl(l.targetPercent, 0) + ' %');
  setzeBalken('lightTargetBar', l.targetPercent);

  if (l.connected === true) {
    setzeBadge('lightBadge', 'good', 'DALI Live');
  } else {
    setzeBadge('lightBadge', 'wait', 'Vorbereitet');
  }
}

// Eine Diagnosezeile setzen.
// wert === true -> grün; wert === false -> zustandFalsch; fehlt der Wert -> gelb "Unbekannt"
function setzeDiagnose(name, wert, textOk, textFalsch, zustandFalsch) {
  if (wert === true) {
    setzePunkt(name + 'Dot', 'ok');
    setzeText(name + 'Text', textOk);
  } else if (wert === false) {
    setzePunkt(name + 'Dot', zustandFalsch);
    setzeText(name + 'Text', textFalsch);
  } else {
    setzePunkt(name + 'Dot', 'warn');
    setzeText(name + 'Text', 'Unbekannt');
  }
}

// Gesamtstatus im Hero-Bereich: zustand = 'ok', 'warn' oder 'err'
function setzeGesamtstatus(zustand, text) {
  el('heroState').className = 'hero-state ' + zustand;
  setzePunkt('heroDot', zustand);
  setzeText('heroText', text);
}

function zeigeSystem(s) {
  setzeDiagnose('catan',  s.catan,   'Online',         'Offline',               'err');
  setzeDiagnose('sensor', s.sensors, 'Betriebsbereit', 'Störung',               'err');
  setzeDiagnose('knx',    s.knx,     'Verbunden',      'Noch nicht verbunden',  'warn');
  setzeDiagnose('dali',   s.dali,    'Betriebsbereit', 'Noch nicht verbunden',  'warn');

  // Störmeldung: true = Störung. Nur ein ausdrückliches false bedeutet "keine Störung".
  // Fehlt der Wert, wird NICHT "betriebsbereit" angezeigt.
  if (s.fault === true) {
    setzePunkt('faultDot', 'err');
    setzeText('faultText', 'Störung vorhanden');
    setzeGesamtstatus('err', 'Störung vorhanden');
  } else if (s.fault === false) {
    setzePunkt('faultDot', 'ok');
    setzeText('faultText', 'Keine Störung');
    setzeGesamtstatus('ok', 'Anlage betriebsbereit');
  } else {
    setzePunkt('faultDot', 'warn');
    setzeText('faultText', 'Unbekannt');
    setzeGesamtstatus('warn', 'Status unbekannt');
  }
}

/* ==========================================================================
   5. Verbindungs- und Aktualitätsüberwachung
   ========================================================================== */

// Prüft, ob sich der Zeitstempel der Daten noch ändert.
// Ändert er sich länger als VERALTET_NACH_MS nicht, liefert die Steuerung
// zwar eine Antwort, aber keine neuen Werte (z. B. Skript hängt).
// Es wird bewusst NICHT mit der Uhr des Browsers verglichen, weil die Uhren
// von Steuerung und Browser voneinander abweichen können.
function datenSindVeraltet(zeitstempel) {
  const jetzt = Date.now();

  if (zeitstempel !== letzterZeitstempel) {
    letzterZeitstempel = zeitstempel;
    zeitstempelSeit = jetzt;
  }
  return (jetzt - zeitstempelSeit) > VERALTET_NACH_MS;
}

function zeigeVerbindungLive() {
  document.body.classList.remove('data-stale');
  setzePunkt('connectionDot', 'ok');
  setzeText('connectionText', 'Live verbunden');
}

function zeigeDatenVeraltet() {
  document.body.classList.add('data-stale');
  setzePunkt('connectionDot', 'warn');
  setzeText('connectionText', 'Daten veraltet');
  setzeBadge('updatedBadge', 'wait', 'Keine neuen Werte');
  setzeGesamtstatus('warn', 'Daten veraltet');
}

// Wird aufgerufen, wenn die Steuerung längere Zeit nicht antwortet.
// Alle Anzeigen werden als "unbekannt" markiert, damit niemand alte Werte
// für aktuelle hält.
function zeigeOffline() {
  document.body.classList.add('data-stale');
  setzePunkt('connectionDot', 'err');
  setzeText('connectionText', 'CATAN nicht erreichbar');

  setzePunkt('catanDot', 'err');
  setzeText('catanText', 'Offline');
  for (const name of ['sensor', 'knx', 'dali', 'fault']) {
    setzePunkt(name + 'Dot', 'warn');
    setzeText(name + 'Text', 'Status unbekannt');
  }

  setzeBadge('updatedBadge', 'bad', 'Daten nicht aktuell');
  setzeGesamtstatus('err', 'Verbindung unterbrochen');
}

/* ==========================================================================
   6. Daten verarbeiten
   ========================================================================== */

function zeigeDaten(daten) {
  // Plausibilitätsprüfung: Es muss ein JSON-Objekt sein
  if (typeof daten !== 'object' || daten === null) {
    throw new Error('Ungültiges Datenformat');
  }

  zeigePraesenz(daten.presence    || {});
  zeigeKlima(daten.climate        || {});
  zeigeBeschattung(daten.shading  || {});
  zeigeBeleuchtung(daten.lighting || {});
  zeigeSystem(daten.system        || {});

  const zeitstempel = zahl(daten.timestamp);   // Unix-Zeit in Sekunden

  if (zeitstempel === null) {
    // Ohne Zeitstempel ist keine Aktualitätsprüfung möglich -> Browserzeit anzeigen
    zeigeVerbindungLive();
    setzeBadge('updatedBadge', '', 'Empfangen ' + new Date().toLocaleTimeString('de-DE'));
    return;
  }

  if (datenSindVeraltet(zeitstempel)) {
    zeigeDatenVeraltet();
  } else {
    zeigeVerbindungLive();
    setzeBadge('updatedBadge', '', 'Aktualisiert ' + new Date(zeitstempel * 1000).toLocaleTimeString('de-DE'));
  }
}

/* ==========================================================================
   7. Daten abholen
   ========================================================================== */

// Holt die Daten vom Webhook (oder im Demo-Modus aus der Simulation).
// Wirft einen Fehler, wenn keine gültige Antwort kommt.
async function holeDaten() {
  if (DEMO_SZENARIO) {
    return demoDaten(DEMO_SZENARIO);
  }

  // AbortController: bricht die Anfrage nach ANFRAGE_TIMEOUT_MS ab
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), ANFRAGE_TIMEOUT_MS);

  try {
    const antwort = await fetch(DATEN_URL, { cache: 'no-store', signal: controller.signal });
    if (!antwort.ok) {
      throw new Error('HTTP-Fehler ' + antwort.status);
    }
    return await antwort.json();
  } finally {
    clearTimeout(timer);   // Timer immer aufräumen, egal ob Erfolg oder Fehler
  }
}

// Ein Abfrage-Zyklus. Plant sich am Ende selbst neu ein.
// Vorteil gegenüber setInterval: Die nächste Abfrage startet erst, wenn die
// vorherige fertig ist – Anfragen können sich nicht stauen.
async function abfrageZyklus() {
  // Ist der Tab nicht sichtbar, wird nicht abgefragt (entlastet die Steuerung)
  if (!document.hidden) {
    try {
      const daten = await holeDaten();
      zeigeDaten(daten);
      letzteAntwortZeit = Date.now();
    } catch (fehler) {
      console.warn('Abfrage fehlgeschlagen:', fehler.message);

      // Einzelne Aussetzer werden toleriert, erst nach OFFLINE_NACH_MS -> offline
      if (Date.now() - letzteAntwortZeit > OFFLINE_NACH_MS) {
        zeigeOffline();
      }
    }
  }
  setTimeout(abfrageZyklus, ABFRAGE_INTERVALL_MS);
}

/* ==========================================================================
   8. Demo-Modus: simulierte Anlagendaten für Test und Präsentation
   ========================================================================== */
function demoDaten(szenario) {
  if (szenario === 'offline') {
    throw new Error('Demo: Steuerung nicht erreichbar');
  }

  const sek = Math.floor(Date.now() / 1000);

  // Normalbetrieb mit leicht schwankenden Werten
  const daten = {
    timestamp: sek,
    presence: { roomState: 'BELEGT', detected: true, lux: 380 + (sek % 40), runOnSeconds: 0, connected: true },
    climate:  { sensorOk: true, actual: 21.0 + (sek % 10) / 10, setpoint: 21.5, state: 'HEIZEN', season: 'Winter' },
    shading:  { sensorOk: true, level: 35, voltage: 4.2 },
    lighting: { connected: true, on: true, actualPercent: 80, targetPercent: 80 },
    system:   { catan: true, sensors: true, knx: true, dali: true, fault: false }
  };

  if (szenario === 'sensorfehler') {
    daten.climate.sensorOk = false;
    daten.climate.actual   = null;
    daten.shading.sensorOk = false;
    daten.shading.level    = null;
    daten.system.sensors   = false;
  }

  if (szenario === 'stoerung') {
    daten.climate.state       = 'STÖRUNG';
    daten.lighting.connected  = false;
    daten.system.dali         = false;
    daten.system.fault        = true;
  }

  if (szenario === 'veraltet') {
    daten.timestamp = 1700000000;   // fester Zeitstempel -> ändert sich nie
  }

  return daten;
}

/* ==========================================================================
   9. Start
   ========================================================================== */

if (DEMO_SZENARIO) {
  el('demoBanner').hidden = false;
  el('demoBanner').textContent += ' Szenario: ' + DEMO_SZENARIO;
}

// Uhr in der Fußzeile (Uhrzeit des Browsers)
function aktualisiereUhr() {
  setzeText('clock', new Date().toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'medium' }));
}
aktualisiereUhr();
setInterval(aktualisiereUhr, 1000);

abfrageZyklus();
</script>
</body>
</html>
<?php

/**
 * Liefert das Logo als HTML.
 * Das Bild ist als Base64-Text direkt in dieser Datei gespeichert ("Data-URI"),
 * damit auf dem Server keine zweite Datei nötig ist.
 * Ist kein Logo hinterlegt, wird ein Ersatzlogo aus Text angezeigt.
 *
 * Hinweis: PHP kennt Funktionen bereits beim Start der Datei, deshalb darf
 * diese Funktion am Ende stehen, obwohl sie weiter oben aufgerufen wird.
 * So steht der sehr lange Logo-Text nicht mitten im lesbaren Code.
 */
function logoHtml(): string
{
    $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAACAAAAAH4CAYAAADe/g2XAAAQAElEQVR4AeydB/xN9f/HXx97b0JWKCkVUSSzEJWysrKVlVDJKEmpqKQSSggZDaSpNI1oqSRC9t57r/7/Xp/ws7/f+/2ec+8ZLw/ne+8995z3eH7OPePzeb/fnyT/p38iIAIiIAIiIAIiIAIiIAIiIAIiIAJBJyD/REAEREAEREAEREAEREAEREAEREAEgk/g/5JA/0RABERABERABERABERABERABERABAJOQO6JgAiIgAiIgAiIgAiIgAiIgAiIgAgEnwCgAIAwtLJ8FAEREAEREAEREAEREAEREAERCDcBeS8CIiACIiACIiACIiACIiACIiACIhB8Av96qACAfyHovwiIgAiIgAiIgAiIgAiIgAiIgAgEmYB8EwEREAEREAEREAEREAEREAEREAERCD4BeqgAAFLQIgIiIAIiIAIiIAIiIAIiIAIiIALBJSDPREAEREAEREAEREAEREAEREAEREAEgk/AeqgAAItBf0RABERABERABERABERABERABEQgqATklwiIgAiIgAiIgAiIgAiIgAiIgAiIQPAJ/OehAgD+46C/IiACIiACIiACIiACIiACIiACIhBMAvJKBERABERABERABERABERABERABEQg+AROeKgAgBMg9CICIiACIiACIiACIiACIiACIiACQSQgn0RABERABERABERABERABERABERABIJP4KSHCgA4SUKvIiACIiACIiACIiACIiACIiACIhA8AvJIBERABERABERABERABERABERABEQg+AROeagAgFMo9EYEREAEREAEREAEREAEREAEREAEgkZA/oiACIiACIiACIiACIiACIiACIiACASfwP88VADA/1jonQiIgAiIgAiIgAiIgAiIgAiIgAgEi4C8EQEREAEREAEREAEREAEREAEREAERCD6B0zxUAMBpMPRWBERABERABPxG4P/+7//AxW92y14REAEREAEREIHoEJAWdwnwPoyLu1okXQREQAS8T4DnQi7et1QWioAIiIAIiIAIiEAwCZzulQIATqdxkfe8gT169Cj27NmDTZs2YeXKlViyZAkWLFiAP/74A/PmzdMiBjoGdAzoGNAx4PgxwGvMn3/+ib/++gvLli3DunXrsHPnThw6dAj//PPPRa5c/vqK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b1fnZbzoaSMREAF3CfhbOrN+fvrpJ7z66qvgtCsff/wx2FHmb69kvQiIgAiIwNkE1q9fbwdB3njjDbAqHbMiz95Gn0VABEQglgT43M+FNuTMmRPNmzdHhQoVwIAArtPiHoFt27aB/SxffPEFjh496p6iKEpm4si3335rAxsY5BBF1aFUlS5dOrRo0QLVq1dHqlSpAly94+LNG9gAgN9//x2TJ08OzAni4s2ob0VABERABEQgcQTY6cISu+p4SRxH7R0uAnyAmzZtGl544QVbypSl3FSaOlzHgLwVAc8R8LFBDKT64IMP0K9fP1vun4GJDAjwsUsyXQREQARE4CIENm3aZKdtZRDAwoULlQ15EVb6SgREIPYErrnmGtx3331gSfFkyZLF3qAAW8C+SfZTjhkzBhznOxmI4VeXGcTA69zQoUOxevVqv7rhG7tZ6v+uu+5C06ZNkSFDhmAP/sfRKoEMAOAPiqWktmzZokysOA4AfS0CIiACIiACJMAqAO+99x5WrlzJj1pEQATiSYAPon/99ZfNVu3Tpw84JYDKVMcTnjYTARFwnIBfBbJDjMFULPk/depU7Nq1y6+uyG4REAEREIEICHDKF5ZDHjRokB3kUTBtBPC0qQiIgKsEjDFnDBwmSZIElStXRps2bZA/f/4zvnPVkJAKZ78KpwEbMWIE1qxZ41sKDGbg1Dec9mbWrFm+9cNPhlesWBEPPvggChQoAP5u/WR7pLbGtX0gAwAWLFiAr776Stn/cbW+vhcBERABERCBEwQ4iMkMjNGjRyt47gQTvYhAJATYefn+++/bktWfffYZ9u/fH8nu2lYEREAEnCDgOxns2GMllf79+4Ode5zCjwH9vnNEBouACIiACCSYAEs9f/jhhzagloM9CgJIMErtKAIi4DKBlClT4o477kC9evWQLVs2l7VJPIOCOQ3Au+++i3379vkSCH1455138NFHH0HXN/eb8IorrkCHDh3Aih0pUqRwX2FsNcSpPXABABzAmDRpEnbs2BGn89pABERABERABETgfwSOHDkClt5lWfP/rdU7ERCB+BLgQNbXX3+N559/Hrwf3bNnT3x31XYiIAIi4AABf4lgyf8JEybgueees/cfeob3V/vJWhEQARFwkgCDAD7++GO89tpr+PXXXzVI4iRcyRIBEXCUQNasWdGyZUswyzht2rSOypawcwls2LABTLaYMWOG764NrLY6ffp0vPXWW9i9e/e5zmmNowQyZsyItm3bolKlSkiTJo2jsr0pLG6rAhcAsHfvXvCGkYMYcbuvLURABERABERABE4SYBDd2rVr8e23355cpVcREIEICbC82y+//IKXXnoJb7/9Nnbu3BmhBG0uAiIgAgkk4KPdVq1ahZEjR2LAgAGYM2cOFHzoo8aTqSIgAiLgEgEOjrAqDO+jv//+exw7dswlTRIrAiIgAokjUKRIETzwwAO47rrrkDx58sQJ094XJcCs+UWLFmH8+PHg60U39tCXtHvFihUYOnQo+Ooh0wJpSrJkyVC3bl3UqVMHmTNnDscUHfFoycAFAPz8889YuXKlyhfHo/G1iQiIgAiIgAicTeDgwYNgaa2z1+uzCIhAZAQ4JRUHtpjFxM5MBthEJkFbi4AIiEBkBPyy9Y8//miz/l999VXbiacBHr+0nOwUAREQAfcJ8L75008/BaeG+eabbxQE4D5yaRABEUgggXLlyqFz584oXLiwBhsTyDC+u7GvklN+jxkzBpy+NL77xWo79v9s3brVTm0za9YsjVVGoSGqVauGNm3aIE+ePEiSJHDD3uclGJ+VgSPBSFHNGRifptc2IiACIiACInAuAUao/vnnn8rEOxeN1ohARAT4wLd69Wq8+eabNuKb0wFwXURCtLEIiIAIxJ+A57fkc/oXX3xhB3UmTpwIlvP0vNEyUAREQAREIOoEWBVmxowZtqLW9OnToSqvUW8CKRQBEYgHAWYcV69eHc2bNwenBYjHLtokEQQ4fdhHH32EKVOmgNMvJkKU67vu37/fTlvAqSF1DXMdN6699lo7+F+sWDHwd+m+Rk9oiJcRgQoA4I/phx9+UHRovJpeG4mACIiACIjAuQQ4QMn5F+fOnXvul1ojAiIQMYH169fbAIDBgwfbOd/4G4tYiHYQAREQgTgJeHsDDuZ88sknNvOf2Tu7du3ytsGyTgREQAREIKYEmO3JrElWAvj6669x+PDhmNoj5SIgAiJwPgIZMmRA48aNwezjtGnTnm8TrXOIAPtSOI3YO++8A1YU42eHRDsqhtcrTnHG0v8MWnBUuISdQ4DBN8z8L1++PNKkSXPO98FdET/PAhUAwLk01q1bh3/++Sd+3msrERABERABERCBcwjs27cPzNA75wutEAERSBAB3p8OGjQIL774IlgJIEFCtJMIiIAIXIyAh7/buXMnRo8ejd69e4MB+wwG8LC5Mk0EREAERMAjBJjhOXPmTDzzzDP48MMPsXfvXo9YJjNEQARE4H8EWHK8W7duuOGGG5AiRYr/faF3jhNgRbFff/0VnApgyZIljstPrECOS3J68meffRZetC+x/nlt/5QpU6Jly5aoXbs2MmfOHK6pOOLZGIEKAFi4cCFYXiOevmszERABERABERCB8xBgtOpff/11nm+0SgREIKEEtmzZgpEjR2L48OHKYEooxBjvx3nkmGXAJcamSL0InEPAqys2b96MESNG2DLOvLc4duyYV02VXSIgAiIgAh4kwMEeVqd7/vnnbRCA+n092EgySQRCTsAYA5Yef/TRR1G4cGENQrp8PDCYmElL77//Pnbs2OGytviLZz8Br1EDBw60FQriv6e2TAgBYwzuvvtuW4HjkksuCd3vLr7MAhcAwEGL+Dqv7URABERABERABM4lcPz4cWzcuPHcL7RGBEQgUQQ4EMapADhnHTszEyVMO0edAAMAjDFR1yuFIhAPAp7cZO3atRgyZAh43mO1PnaKedJQGSUCIiACIuBpArxvnj9/PjiowulkVFHL080l40QglASSJk2KSpUqoX379siSJUsoGUTTafatTJo0CVOnTgWnBY+m7gvp4rWKQQm0yys2XcjWIKy/8cYb0aJFCxQtWhT8/QXBpwh8iPemgQoAYFkN/tDi7b02FAEREAEREAEROIcAS1bt3r0buqaeg0YrRCDRBFavXm3nwP7pp5+gTNhE44yqAGOMjSo3xkRVr5SJQNwEvLcFB/9HjRpls//XrFnjPQNlkQiIgAiIgK8IMEj9zz//RL9+/TB58mRwehkGlnHxlSMyVgREILAE0qRJgwYNGuCuu+4C3wfWUQ84xnM/xwLfeecd/PLLLzG3iNeoefPm2apnvD7F3KCAG8BpN1q1aoWbbroJqVKlCri353Mv/usCFQCwbt06daTGv+21pQiIgAiIgAiclwBvpDnfoqoAnBePVopAoglw2qqHH34YHCBjwE2iBUqACIhAuAl4zHuW+mep5qFDh6qikMfaRuaIgAiIgJ8JcIDlZBDAuHHjbBCAn/2R7SIgAsEjkC1bNjz22GMoW7YskidPHjwHPeQRs+xnzZqFMWPGgIkWsTSNFQl69uyJxYsXx9KMUOhmcE3btm1Rs2ZNZMyYMRQ+n+NkBCsCFQCwZcsW8GYwAv+1qQiIgAiIgAiIwHkIMPtfGXvnAaNVIuAAAQ76//bbb+jatStUwtQBoFESYYwy/6OEWmoiJOClzZctW4ZBgwaB2TjsCPOSbbJFBERABETA/wQYrL58+XI7HcD48eOxbds2/zslD0RABAJFoGDBgujVq8A/W2YAABAASURBVBcKFSoETiMXKOc85szevXvtNAAff/wx9u3bFzPrXnzxRcyePRu8RsXMiBAoZqn/WrVqoW7dusiRI4et0BgCt89xMZIVgQoA4GCFfmSRNL+2FQEREAEREIHzE+D19PDhw+f/UmtFQAQSTYBBq59//jlefvnlRMuSABEQgVAT8IzzHPwfMmQIJk6ciB07dnjGLhkiAiIgAiIQLAIMpmW250svvYQJEyZg69atwXJQ3oiACPiWgDHGDvqXKVMGjz76KLJnzw79c5cAq5cy+Pi7776LSXXwSZMmYezYsVAfqrvtTOn8XTVt2hQMsmEwANeFcInI5SQRba2NRUAEREAEREAEREAEREAEHCFw8OBBcLBsxowZjsiTEBEQgTASiJ3PDBY8uXBKk7feegujRo3S4H/smkSaRUAERCA0BHj9YRBA//79beUZXodC47wcFQER8DyBlClTon79+mjevDn43vMG+9hABoX9+uuvdhCe08RE0xVOffb4449j+/bt0VQbSl1FihRBhw4dUL58eaRIkSKUDP5zOrK/CgCIjJe2FgEREAEREAEREAEREAHHCPBB8cEHH8SuXbsckylBIiACISLgAVc3bNiAESNGYPjw4di9e7cHLJIJIiACIiACYSGwadMmvP7663bhewYGhMV3+SkCIuBtAunSpcPDDz+MChUqQNnK7rbVkSNHMG3aNFsVJlrTkHE6xy5duoBV0Nz1TtIzZMiAFi1aoGrVqkiTJk24S/9HeDgoACBCYNpcBERABERABERABERABJwksHDhQjBq3EmZkiUCIhAOArH2ksFLLL88dOhQbNu2LdbmSL8IiIAIiEAICTCg9s0338SLL74IVgJQEEAIDwK5LAIeJZAjRw57bmLJcmOMR60MhlkckGc5fgYCHDp0yFWnWHWAFWhmzpwJvndVWciFJ0+eHPfccw/q1auHrFmzhn7wP9LDQQEAkRLT9iIgAiIgAiIgAiIgAiLgIAE+MHIA7fvvvwc7LLk4KF6iREAEgksgpp5xGpOpU6diwIABGvyPaUtIuQiIgAiIAIMAOBXNwIEDsWbNGntPLSoiIAIiEGsCxhhcffXV9n45U6ZMsTYn8PoZBPb222/jxx9/dHVg/qOPPsKwYcNw+PDhwDONtYOsoNG4cWMUKFAASZKEfjg74uYQsYiRaQcREAEREAEREAEREAERcJYAs2hbtWqFY8eOOStY0kRABAJMIHauHT16FN9++62tXrJly5bYGSLNIiACIiACInCCAO+nOSVNr169sHr1alcHf06o1IsIiIAIXJAAA/u5JEuWDNWrV0fPnj2ROnXqC26vLxJP4Pjx45g1axbGjh2LpUuXJl7geSSw5P+jjz6KHTt2nOdbrXKSwDXXXIM2bdrg5ptvBn9HTsr2p6zIrU4S+S7aQwREQAREQAREQAREQAREwGkCy5cvx8svv6ySZk6DlTwRCCqBGPr1008/2cF/ZlnG0AypFgEREAEREIEzCOzfvx+TJ0+GggDOwKIPIiACMSaQIkUKtGvXDnfddVeMLQm++iNHjtjrwJQpU8DAMCc9ZgW0bt26YeXKlU6KlazzEGC5/2bNmqFatWrg7+c8m4RvVQI8VgBAAqBpFxEQAREQAREQAREQARFwmgCnAuD8pZy7zmnZkicCIhA8ArHy6O+//8Zzzz2HhQsXqsRyrBpBekVABERABC5IgAM0HPhhti0DbHmPfcGN9YUIiIAIuETAGHNGcH/69Onx/PPPo0iRIi5plNiTBHbv3g1OC/P55587WmWRU599/fXXqjBzErRLr8mTJ0eTJk1Qv359ZMyY8YzfkUsqfSE2IUYqACAh1LSPCIiACIiACIiACIiACLhAgNm0nTp1ckGyRIqACASMQEzc2bBhAxio9OWXX4IlNmNihJSKgAiIgAiIQBwEDhw4YDNAW7Zsib/++kuDNXHw0tciIALRIZAvXz47MJ0hQ4boKAyxFpbqHzlyJObNm+cIBT7/DBo0CHv37nVEnoRcmEDVqlXRokUL5MmTR4P//8OUoHcKAEgQNu0kAiIgAiIgAiIgAiIgAs4TOHbsGL744gusX7/eeeGSKAIiECAC0Xdl3759YMfX4MGDNfgfffzSKAIiIAIiECEB3lf/+OOPtuz2ihUrFAQQIT9tLgIi4DwBYwxKly6NZ599FsmSJXNegSSeIvB///d/mDFjBt577z2sW7fu1PqEvNm4cSO6dOmCHTt2JGR37RMBgeuvvx733XcfihUrhiRJNHyNU/8S9kYEE8ZNe4mACIiACIiACIiACIiA4wT4kMqHyq5duzouWwJFQAQCRCAGrixYsACPPfYYDh8+HAPtUikCIiACIiACkRNgtRoGAdStWxecwkbTAUTOUHuIgAg4SyBp0qR2gLNRo0bOCpa0cwgwEOzkVACsDHPOBvFcoSll4gkqkZtdcskl4O+CFQD4O2H/GJdEig3G7gn0QgEACQSn3URABERABERABERABETADQJHjx7Fd999h9WrV7shXjJFQAQCQCDaLixcuBDdu3fHpk2boq1a+kRABERABEQgUQQYBMDrWIMGDbBkyRJVsUkUTe0sAiLgBIGUKVNi4MCBKFGihBPiJOMiBJhg8frrr2PWrFkJOv+/8sor+Oyzz3DkyJGLaNFXiSWQOnVqNG7cGPfeey/Spk2r0v9nAU3oRwUAJJSc9hMBERABERABERABERABlwjwIbV3794uSZdYERABnxNw3XxmWnChop07d+Ldd9/FzJkzcXId12sRAREQAREQAb8QYBDA/PnzUbFiRfz0008JGgTyi6+yUwREwPsEjDHIli0bRo4ciSxZsnjfYJ9bOG/ePAwbNgx//vlnvJ9n+NwzZ84cvPTSS9i2bZvPCXjbfGMMbr31VlsZI1euXKcG/40xp9572wPXrUuwAgUAJBiddhQBERABERABERABERABdwiwCsC0adOwZ88edxRIqgiIgI8JuG+6Mf91tvBc9Ntvv+G5555zX6k0iIAIiIAIiIDLBLZu3YpatWrhjz/+UBCAy6wlXgREIG4CxYoVw9ChQ5EiRYq4N9YWCSbAwfxPP/0UkyZNwvbt2+Mlh5XPunTpgo0bN8Zre22UcAI33HAD2rdvj6uuuirhQgK9Z8KdUwBAwtlpTxEQAREQAREQAREQARFwjcCuXbvw2muvuSZfgkVABHxKIEpms6NsxYoV6NSpE/75558oaZUaERABERABEXCXAIMAqlWrhunTp+PYsWPuKpN0ERABEbgIgeTJk+Ouu+5CmzZtLrKVvnKCAAObWQXg22+/xaFDhy4qktv27NkTCxYsULDYRUkl/su8efPa0v9VqlRJvLCgSkiEXwoASAQ87SoCIiACIiACIiACIiACbhHgHHOT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kTTGhMZ37zoafcsUABB95tIoAiIgAiIQDwIsKVq5cmVbZorBAPHYRZuIgAhEgQAzSTds2IDXX3/dzgPHrH9WAIiC6lCr4EBUjRo1Qs1AzotA4AhEwaHt27erAkACOefOnRtly5ZN4N7h2I1BJpdffjkuueSScDgsL0VABERABERABEQgpAQ45ROnAmBfLStlhRSDL9xOmzYtHn30Udx8882qZualFouBLQoAiAF0qRQBERABEYgfAQYBVKpUyd60XHfddTZiMX57aisREIGEEGBHPhcO8h8/ftyW8j906BB2796NZcuWYfz48WjWrBluuOEGPPDAA1i8eDH0z30CfLguUKAA8uTJ474yaRABEYgagWgoOnbsWDTUBFJHzpw57fUukM455JQxxnYqpkiRwr5C/0RABERABERABERABAJLgAPLAwYMwLXXXmuncA2soz52LGXKlHj44Ydx99132+nMfOxK4EyPhUNJYqFUOkVABERABEQgvgQ48FWrVi106NABHAAzRiWL4stO24WTwJEjR7B///6Il3379oHLrl27sG7dOvz666/4+OOP8fTTT6Nu3bp2Oo4mTZrYIABWAAgn3dh4nT59ejRv3jw2yqVVBETALQKuyzXGgNcE1xUFVAEHtVOnTh1Q75xzi9lgvF9n4K5zUiVJBERABERABERABETAiwRYWp6VAHLlyqVELY81EO/H77zzTjt9JIOZPWZe2M2Jif8KAIgJdikVAREQARGIhACjFznPVPv27ZEtWzbdYEYCT9uGhoAxBjt37sS9996Lhg0bRrzUqVMHLOVWrlw5WyaM1Tfq1auHfv364ZtvvsGmTZtCw9JLjhpjwAe3mjVresks2SICIpBoAtERwIou0dEULC3GGJvRzk406N9FCZBRunTpwICJi26oL0VABERABERABERABAJBgNnl999/PzJmzBgIf4LixNVXX42OHTuiaNGi6jv3XKPGxiAFAMSGu7SKgAiIgAhESIAdi61bt0bTpk1VwihCdto8HARYuv/w4cP4/PPP8emnn0a8fPXVVzbr/6+//rIVAA4ePBgOcB73Mk2aNGBQRtasWT1uqcwTARGIiEAUNuZ1wRhVTkoIag5qM6vdGPGLD7/cuXMjU6ZM8dlU24iACIiACIiACIiACASAQNeuXVG1alWkSpUqAN743wX2GbVr185OYcYK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tGlT5MyZ07NVAEiFJc1nzJiBJ598EoMGDbLVALZv345//vmHX0dt4eA/AxJ+/fVXDB8+HN26dcOnn34a04CE+DifMWNGdO7c2dNtHB8/tE3cBHiM7tu3z1amiHtrbSECIiACIiACIuBFArJJBERABERABERABERABERABERABEQg+ATC6KECAMLY6vI5JgSKFy+OW265xdNVAE6CWb9+PV555RX06NEDo0aNwi+//AJm3jMj/+Q2Tr9yQJWBBhxU/fPPP201gscff9xWJPBDmXVjDKpVq4ZixYo5jUbyPEiAx+qWLVtshQoPmieTREAEREAEREAE4iagLURABERABERABERABERABERABERABIJPIJQeKgAglM0up6NJgAPbXJInT4727dujQIECSJLE+z89lv//6aefbDWARx55BIMHD8aXX36JFStW4ODBg6BPTnCknCNHjmDjxo2YPn26zfhn4EHXrl3x9ddfg1UJnNDjtozs2bOjU6dOSJo0qduqJN8DBHjMLl261AOWyAQREAEREAEREIGEEdBeIiACIiACIiACIiACIiACIiACIiACwScQTg+9PwoZznaR1wElcN1116FWrVpIkyaNbzw8cOAAZs+ejWeffdZWBHjhhRfw7rvv2sF6DoDu2LHDDtJzID++TjF7evfu3Vi1ahVmzZqFiRMn2ooDPXv2RK9evfD5559j//798RUX8+0Y0FG7dm2UKFEi5rbIAPcJ8Fg/fPgw5s6d674yaRABERABERABEXCHgKSKgAiIgAiIgAiIgAiIgAiIgAiIgAgEn0BIPVQAQEgbXm5Hj4AxBsYYqzBZsmRo1aoVrr76avC9XemTPxzwnD9/PoYNG4bu3bvbYID+/fvjjTfesAP4X3zxBX744QcsWLAALNm/evVqrFmzxi7Lli3DokWL8OOPP2LatGn44IMPbKb/iy++CA76s8IA3//8888Fjb0nAAAQAElEQVRgwIFPkJwyM1++fGjTpo0vpnc4ZbTeJIrA3r178e233yZKhnYWAREQAREQARGIHQFpFgEREAEREAEREAEREAEREAEREAERCD6BsHqoAICwtrz8jhmBQoUKoV27dsiaNWvMbEis4q1bt4KD9W+99Rb69OmDzp072/L3LNv/+OOP44knnsDTTz99aundu7fN7H/00Uftdg888AC43dChQzFnzhxs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yzGAAEUgARIgARLwnAA85xFVx/OKHapQKXXOwBYLRA888IB89tlnAr1gFOBQVSyGBAJPAEYAzz77rLzzzjtStmxZptMKfIvaqwAmEW+++WZp27at1KpV65z3vL1aUzMSiJ4AjAAefPBBbQSQL1++6Avy8krWRQIk4DmBNWvWyNq1a+X06dOe121bhVj8r1+/viBakW26ea3PjTfeKCVKlGCk3xjB79u3T6cB8DK9h1L/zcdg7gUGAG+88YYgsgPGnjGqxMtJgARIgASSQMAqAwCEWcqSJQsng5JwA5h+KuUjARIgARLwngAmjDHg9r5m92qEUUO9evWkZ8+eUrFiRU7KuIeaJQeQAJ6PRx55RJo1ayblypVjRK0AtqHtIiME7J133ikIJXr99dfbri71IwHHCGTKlElgBImoGTACUEo5VrYbBbFMEiABbwkgVHevXr1kz5493lZsYW0wOofnP9IU4bOFKnqqEuYkrr32WsHvmKcVW1bZyZMnZdmyZbJixQrB8+6Xeo0aNdIGiUjtkCZNGr/EYL0kQAIkEDoCVhkAoFNw9dVX03PJntuYmpAACZAACfhAIFu2bHLLLbf4ULO7VSLkXI0aNaRPnz5yxx13CBaU3K2RpZNAcAjAO+O+++6T9957T2666SbJkCFDcISnpFYTyJUrlzz66KN68R8GXFYrS+VIwAUCmTNnloceekg++OADKViwoAs1OFYkCyIBEvCYwO7du2XWrFly4sQJj2u2rzqMoatWrSrZs2e3TzmfNGrQoIFcfvnlQoOK2BoAUQCGDx/ue5QPpCbq1KmT3H777RxrxtakvJoESIAEIiZglQEAtEY4SEzw4zO3oBOg/CRAAiRAAl4TwOAanguIqON13V7UB/0QSrBz585y//33S968eb2olnWQgNEElFI6ghaMAG677TZ59913BX+xaGS04BTOagJKKZ37FSkqmjZtKsWLF7daXypHAm4SgLMEJt7feustKVSokJtVxVA2LyUBEvCaABYFt27d6qtnsNc6u1XfZZddJrfeeiud0hwEXKxYMR2dLG3atA6WGr6iDh06JDNmzJBt27b5qrxSSq655hpp3769IDojjHyVUr7KxMpJgARIwHYC1hkAwLOPk5WW3LZUgwRIgARIwHMCyMlWs2ZNz+v1skKllPYk6Nixo7z66qtyxRVX0KvAywZgXUYT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6aefNvwi9fW5YGtW25n4bp577rkgIMe/wjW4FhXxyOjH94Odie1L8gW2MIkfiNOxMfGtyMYs8Yms7qeGgAQAqRlKdUQEREAEREAEREAEsiPg7uaeCYQiDsBApbQ+wXTK65ORTKARxTdBRwLuGIYESAcNGmQEI8m2r++Lc1S/UKP36dMnVCvgWlyTcuy0AcU6inMU7BiiZE1jULt7dh2N2VFqTnwJuLuRwUFAveb9gDOE+6Fnz57GHGW+8qqew3zP9l7g3uF4skM4B9sUcE7OzTWoMIBIh4wNBAq0hXuUSgbumvemLxEQAREQAREQAREQAREQARFIAIHqJrq7UTWOSm0kZiDmLi8vN3we+D6oYkiCBvZhfX0u+9uZ+FiwMzkndibXwM5ESE6QH98Pdia2Lzawu+zM6rHSdxFIGwEJANI2ouqPCIiACIiACIiACIhAHAmoTSIgAiIgAiIgAiIgAiIgAiIgAiIgAuknoB6KgAiIQOQEJACIfAjUABEQAREQAREQAREQgfQTUA9FQAREQAREQAREQAREQAREQAREQATST0A9FAEREIHoCUgAEP0YqAUiIAIiIAIiIAIiIAJpJ6D+iYAIiIAIiIAIiIAIiIAIiIAIiIAIpJ+AeigCIiACMSAgAUAMBkFNEAEREAEREAEREAERSDcB9U4EREAEREAEREAEREAEREAEREAERCD9BNRDERABEYgDAQkA4jAKaoMIiIAIiIAIiIAIiECaCahvIiACIiACIiACIiACIiACIiACIiAC6SegHoqACIhALAhIABCLYVAjREAEREAEREAEREAE0ktAPRMBERABERABERABERABERABERABEUg/AfVQBERABOJBQAKAeIyDWiECIiACIiACIiACIpBWAuqXCIiACIiACIiACIiACIiACIiACIhA+gmohyIgAiIQEwISAMRkINQMERABERABERABERCBdBJQr0RABERABERABERABERABERABERABNJPQD0UAREQgbgQkAAgLiOhdoiACIiACIiACIiACKSRgPokAiIgAiIgAiIgAiIgAiIgAiIgAiKQfgLqoQiIgAjEhoAEALEZCjVEBERABERABERABEQgfQTUIxEQAREQAREQAREQAREQAREQAREQgfQTUA9FQAREID4EJACIz1ioJSIgAiIgAiIgAiIgAmkjoP6IgAiIgAiIgAiIgAiIgAiIgAiIgAikn4B6KAIiIAIxIvA/AAAA///L0PvOAAAABklEQVQDALFUmoln053pAAAAAElFTkSuQmCC';

    if ($logo === '') {
        return '<div class="brand-fallback"><span class="brand-fallback-mark">PC</span><span>PHOENIX CONTACT</span></div>';
    }

    return '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="Phoenix Contact">';
}
