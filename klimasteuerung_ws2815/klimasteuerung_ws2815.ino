/*
 * =============================================================================
 *  Klima- und Jalousie-Visualisierung mit WS2815-LED-Streifen
 * =============================================================================
 *  Board      : Arduino Uno / Nano (ATmega328P, 5 V, 16 MHz)
 *  Bibliothek : FastLED (Bibliotheksverwalter -> "FastLED")
 *
 *  Funktion
 *  --------
 *  Streifen 1 (14 LEDs) : rotes Lauflicht  = HEIZEN
 *  Streifen 2 (14 LEDs) : blaues Lauflicht = KUEHLEN
 *  Streifen 3 (21 LEDs) : neutralweiss     = JALOUSIE (leuchtende LEDs = geschlossen)
 *
 *  A1 : Temperaturspannung 0..5 V  (linear auf TEMP_BEI_0V .. TEMP_BEI_5V)
 *  A2 : Helligkeitsspannung 0..5 V (0 V = dunkel, 5 V = hell)
 *  D2 : Drehschalter Sommer/Winter (Pegel und Zuordnung siehe Konfiguration)
 *
 *  Regelverhalten
 *  --------------
 *  - Sommerbetrieb: Sollwert 22,0 Grad C, Winterbetrieb: Sollwert 25,0 Grad C.
 *  - Zweipunktverhalten mit Hysterese um den Sollwert (kein Flattern an der
 *    Schaltschwelle). Zwischen Heizen und Kuehlen liegt ein Totband.
 *  - Je groesser die Regelabweichung, desto schneller laeuft das Lauflicht.
 *  - Die Jalousie faehrt mit begrenzter Geschwindigkeit (Motorsimulation) und
 *    reagiert erst ab einer Mindestabweichung (kein staendiges Nachregeln).
 *
 *  Verdrahtung
 *  -----------
 *  D5 -> 330..470 Ohm -> DI Streifen HEIZEN
 *  D6 -> 330..470 Ohm -> DI Streifen KUEHLEN
 *  D7 -> 330..470 Ohm -> DI Streifen JALOUSIE
 *  D2 -> Drehschalter. Der Eingang vertraegt maximal 5 V! Liefert der
 *        Schalter 12 V oder 24 V, ist ein Spannungsteiler oder Optokoppler noetig.
 *  BI (Backup-Eingang) der jeweils ersten LED auf GND legen.
 *  12-V-Netzteil versorgt die Streifen. GND Netzteil und GND Arduino verbinden!
 *  NIEMALS 12 V an den 5-V-Pin oder an einen I/O-Pin des Arduino legen.
 *  Empfohlen: 1000 uF Elko zwischen +12 V und GND an der Einspeisung.
 *
 *  Programmaufbau
 *  --------------
 *  Komplett nicht blockierend (kein delay() im Betrieb). Feste Zykluszeit von
 *  20 ms (50 Bilder/s): Einlesen -> Verarbeiten -> Ausgeben (EVA-Prinzip).
 * =============================================================================
 */

#include <FastLED.h>

// =============================================================================
//  1. KONFIGURATION  (alles, was an die Anlage angepasst wird, steht hier)
// =============================================================================

// ---- Anschluesse -----------------------------------------------------------
const uint8_t PIN_LED_HEIZEN   = 5;
const uint8_t PIN_LED_KUEHLEN  = 6;
const uint8_t PIN_LED_JALOUSIE = 7;
const uint8_t PIN_TEMPERATUR   = A1;
const uint8_t PIN_HELLIGKEIT   = A2;
const uint8_t PIN_DREHSCHALTER  = 2;              // Drehschalter Sommer / Winter

// ---- Drehschalter Sommer / Winter ------------------------------------------
// true : Schalter schaltet gegen GND, interner Pull-up aktiv (offen = HIGH)
// false: Schalter liefert selbst 0 V / 5 V (z. B. Wechsler oder externer Pull-down)
const bool DREHSCHALTER_PULLUP = true;
const bool SOMMER_BEI_HIGH     = false;           // Pegel am Pin, der Sommerbetrieb bedeutet

// ---- LED-Streifen ----------------------------------------------------------
// WS2815 nutzt dasselbe Datenprotokoll wie WS2812B (800 kHz, Farbfolge GRB).
#define LED_TYP          WS2812B
#define LED_FARBFOLGE    GRB

const uint8_t ANZAHL_HEIZEN   = 14;
const uint8_t ANZAHL_KUEHLEN  = 14;
const uint8_t ANZAHL_JALOUSIE = 21;

const uint8_t GESAMT_HELLIGKEIT = 160;            // 0..255, begrenzt Strom und Blendung

const CRGB FARBE_HEIZEN   = CRGB(255, 0, 0);
const CRGB FARBE_KUEHLEN  = CRGB(0, 0, 255);
const CRGB FARBE_JALOUSIE = CRGB(255, 220, 180);  // Neutralweiss (reines 255/255/255 wirkt kalt-blaeulich)

// ---- Temperatur (alle Werte in Zehntel Grad: 215 = 21,5 Grad C) ------------
const int16_t TEMP_BEI_0V     = 0;                // Temperatur bei 0 V an A1
const int16_t TEMP_BEI_5V     = 400;              // Temperatur bei 5 V an A1
const int16_t TEMP_SOLL_SOMMER = 220;             // Sollwert im Sommerbetrieb
const int16_t TEMP_SOLL_WINTER = 250;             // Sollwert im Winterbetrieb
const int16_t HYSTERESE_EIN   = 10;               // Einschalten bei Soll -/+ 1,0 K
const int16_t HYSTERESE_AUS   = 3;                // Ausschalten bei Soll -/+ 0,3 K
const int16_t ABWEICHUNG_VOLL = 100;              // ab 10 K Abweichung: maximales Tempo

// false: Heizen und Kuehlen sind in beiden Betriebsarten moeglich, nur der Sollwert wechselt
// true : im Sommer wird nur gekuehlt, im Winter nur geheizt
const bool SOMMER_NUR_KUEHLEN_WINTER_NUR_HEIZEN = false;

// ---- Lauflicht -------------------------------------------------------------
const uint8_t TEMPO_MIN_LED_S   = 4;              // LEDs pro Sekunde bei kleiner Abweichung
const uint8_t TEMPO_MAX_LED_S   = 28;             // LEDs pro Sekunde bei grosser Abweichung
const uint8_t SCHWEIF_LEDS      = 6;              // Laenge des Schweifs hinter dem Kopf
const uint8_t BLEND_SCHRITT     = 10;             // Ein-/Ausblenden je Zyklus (255/10*20 ms = ca. 0,5 s)

// ---- Jalousie --------------------------------------------------------------
const bool     HELLIGKEIT_INVERTIERT = false;     // true, wenn der Sensor bei Licht WENIGER Spannung liefert
const uint16_t JALOUSIE_AUF_BIS      = 200;       // bis 20,0 % Helligkeit: ganz offen   (Promille)
const uint16_t JALOUSIE_ZU_AB        = 800;       // ab  80,0 % Helligkeit: ganz zu      (Promille)
const uint16_t JALOUSIE_FAHRZEIT_MS  = 4000;      // Fahrzeit fuer den gesamten Weg
const uint8_t  JALOUSIE_ANLAUF_LEDS  = 1;         // Mindestabweichung, bevor der "Motor" anlaeuft

// ---- Zeitverhalten ---------------------------------------------------------
const uint8_t  ZYKLUS_MS    = 20;                 // Programmzyklus (50 Hz)
const uint16_t DIAGNOSE_MS  = 500;                // Ausgabeintervall serieller Monitor
const uint32_t BAUDRATE     = 115200;
const uint16_t LAMPENTEST_MS = 400;               // Dauer je Streifen beim Einschalttest (0 = aus)

// ---- Messwertaufbereitung --------------------------------------------------
const uint8_t MESSUNGEN_JE_ZYKLUS = 8;            // Ueberabtastung (Mittelung gegen Rauschen)
const uint8_t FILTER_TEILER       = 8;            // Tiefpass: groesser = ruhiger, aber traeger
const uint8_t ENTPRELL_ZYKLEN     = 3;            // Drehschalter muss 3 Zyklen (60 ms) stabil anliegen

// =============================================================================
//  2. ABGELEITETE KONSTANTEN  (nicht aendern)
// =============================================================================

// Positionen werden in 1/256 LED gerechnet (Festkomma). Dadurch bewegen sich
// Lauflicht und Jalousie stufenlos statt in sichtbaren LED-Spruengen.
const uint16_t SUB = 256;

const uint16_t MESSWERT_MAX   = 1023U * MESSUNGEN_JE_ZYKLUS;      // Filterwert bei 5 V
const uint16_t JALOUSIE_WEG   = ANZAHL_JALOUSIE * SUB;            // gesamter Fahrweg
const uint16_t JALOUSIE_SCHRITT =
    (uint32_t)JALOUSIE_WEG * ZYKLUS_MS / JALOUSIE_FAHRZEIT_MS;    // Fahrweg je Zyklus

// Plausibilitaetspruefung der Konfiguration schon beim Kompilieren
static_assert(HYSTERESE_AUS < HYSTERESE_EIN, "HYSTERESE_AUS muss kleiner als HYSTERESE_EIN sein");
static_assert(TEMP_BEI_0V != TEMP_BEI_5V, "Temperaturbereich darf nicht 0 sein");
static_assert(JALOUSIE_AUF_BIS < JALOUSIE_ZU_AB, "JALOUSIE_AUF_BIS muss kleiner als JALOUSIE_ZU_AB sein");
static_assert(SCHWEIF_LEDS < ANZAHL_HEIZEN && SCHWEIF_LEDS < ANZAHL_KUEHLEN, "Schweif laenger als Streifen");
static_assert(JALOUSIE_SCHRITT > 0, "JALOUSIE_FAHRZEIT_MS ist zu gross");
static_assert(MESSUNGEN_JE_ZYKLUS >= 1 && MESSUNGEN_JE_ZYKLUS <= 64, "MESSUNGEN_JE_ZYKLUS: 1..64");
static_assert(FILTER_TEILER >= 1, "FILTER_TEILER muss mindestens 1 sein");
static_assert(ENTPRELL_ZYKLEN >= 1, "ENTPRELL_ZYKLEN muss mindestens 1 sein");

// =============================================================================
//  3. BAUSTEINE
// =============================================================================

enum class Regelzustand : uint8_t { Neutral, Heizen, Kuehlen };

// -----------------------------------------------------------------------------
//  Analogeingang mit Ueberabtastung und Tiefpass 1. Ordnung
//  Ergebnis: 0 .. MESSWERT_MAX (entspricht 0 .. 5 V)
// -----------------------------------------------------------------------------
class AnalogEingang {
public:
  explicit AnalogEingang(uint8_t pin) : pin_(pin), speicher_(0) {}

  // Filter mit echtem Messwert vorbelegen (kein Einschwingen nach dem Einschalten)
  void begin() { speicher_ = (uint32_t)messen() * FILTER_TEILER; }

  // Tiefpass: neuer Wert = alter Wert + (Messung - alter Wert) / FILTER_TEILER
  // Der Speicher haelt den Wert mit FILTER_TEILER-facher Aufloesung, damit durch
  // die Ganzzahldivision kein bleibender Fehler entsteht.
  void update() { speicher_ += messen() - speicher_ / FILTER_TEILER; }

  uint16_t wert() const { return speicher_ / FILTER_TEILER; }

private:
  uint16_t messen() const {
    analogRead(pin_);                             // Blindmessung: ADC-Multiplexer einschwingen lassen
    uint16_t summe = 0;
    for (uint8_t i = 0; i < MESSUNGEN_JE_ZYKLUS; i++) {
      summe += analogRead(pin_);
    }
    return summe;
  }

  const uint8_t pin_;
  uint32_t speicher_;
};

// -----------------------------------------------------------------------------
//  Entprellter Digitaleingang
//  Ein Wechsel wird erst uebernommen, wenn er ENTPRELL_ZYKLEN lang stabil anliegt.
// -----------------------------------------------------------------------------
class DigitalEingang {
public:
  DigitalEingang(uint8_t pin, bool pullup) : pin_(pin), pullup_(pullup), pegel_(false), zaehler_(0) {}

  void begin() {
    pinMode(pin_, pullup_ ? INPUT_PULLUP : INPUT);
    pegel_ = lesen();
  }

  void update() {
    if (lesen() == pegel_) {
      zaehler_ = 0;                               // kein Wechsel oder nur Prellen
    } else if (++zaehler_ >= ENTPRELL_ZYKLEN) {
      pegel_ = !pegel_;                           // Wechsel liegt stabil an -> uebernehmen
      zaehler_ = 0;
    }
  }

  bool high() const { return pegel_; }            // entprellter Pegel am Pin

private:
  bool lesen() const { return digitalRead(pin_) == HIGH; }

  const uint8_t pin_;
  const bool pullup_;
  bool pegel_;
  uint8_t zaehler_;
};

// -----------------------------------------------------------------------------
//  Zweipunktregler mit Hysterese und Totband
//  Der Sollwert haengt von der Betriebsart ab (Sommer / Winter).
//
//        HEIZEN            NEUTRAL            KUEHLEN
//   -----------|----|-------- Soll --------|----|----------->  Temperatur
//          EIN ^    ^ AUS              AUS ^    ^ EIN
// -----------------------------------------------------------------------------
class Klimaregler {
public:
  void update(int16_t temperatur, bool sommerbetrieb) {
    sollwert_   = sommerbetrieb ? TEMP_SOLL_SOMMER : TEMP_SOLL_WINTER;
    abweichung_ = temperatur - sollwert_;

    const bool heizenFrei  = !(SOMMER_NUR_KUEHLEN_WINTER_NUR_HEIZEN && sommerbetrieb);
    const bool kuehlenFrei = !(SOMMER_NUR_KUEHLEN_WINTER_NUR_HEIZEN && !sommerbetrieb);

    switch (zustand_) {
      case Regelzustand::Heizen:
        if (abweichung_ >= -HYSTERESE_AUS || !heizenFrei) zustand_ = Regelzustand::Neutral;
        break;

      case Regelzustand::Kuehlen:
        if (abweichung_ <= HYSTERESE_AUS || !kuehlenFrei) zustand_ = Regelzustand::Neutral;
        break;

      case Regelzustand::Neutral:
        if (abweichung_ <= -HYSTERESE_EIN && heizenFrei)       zustand_ = Regelzustand::Heizen;
        else if (abweichung_ >= HYSTERESE_EIN && kuehlenFrei)  zustand_ = Regelzustand::Kuehlen;
        break;
    }
  }

  Regelzustand zustand() const { return zustand_; }
  int16_t sollwert() const { return sollwert_; }
  bool heizt()  const { return zustand_ == Regelzustand::Heizen; }
  bool kuehlt() const { return zustand_ == Regelzustand::Kuehlen; }

  // Lauflichttempo in 1/256 LED pro Sekunde, abhaengig vom Betrag der Abweichung
  uint16_t tempo() const {
    const int16_t betrag = constrain(abs(abweichung_), 0, ABWEICHUNG_VOLL);
    return map(betrag, 0, ABWEICHUNG_VOLL, TEMPO_MIN_LED_S * SUB, TEMPO_MAX_LED_S * SUB);
  }

private:
  Regelzustand zustand_ = Regelzustand::Neutral;
  int16_t sollwert_ = TEMP_SOLL_WINTER;
  int16_t abweichung_ = 0;
};

// -----------------------------------------------------------------------------
//  Lauflicht mit weichem Schweif
//  Der Kopf wandert stufenlos ueber den Streifen, dahinter faellt die
//  Helligkeit linear bis auf 0 ab. Ein- und Ausschalten wird ueberblendet.
// -----------------------------------------------------------------------------
class Lauflicht {
public:
  Lauflicht(CRGB* leds, uint8_t anzahl, const CRGB& farbe)
      : leds_(leds), anzahl_(anzahl), farbe_(farbe), position_(0), pegel_(0) {}

  void update(bool aktiv, uint16_t tempo) {
    // Ein-/Ausblenden (qadd8/qsub8 = Addition/Subtraktion mit Begrenzung auf 0..255)
    pegel_ = aktiv ? qadd8(pegel_, BLEND_SCHRITT) : qsub8(pegel_, BLEND_SCHRITT);

    if (pegel_ == 0) {                            // Streifen ist aus
      fill_solid(leds_, anzahl_, CRGB::Black);
      position_ = 0;                              // naechster Start beginnt am Streifenanfang
      return;
    }

    // Kopf weiterbewegen: Weg = Tempo * Zeit
    const uint16_t umfang = anzahl_ * SUB;
    position_ = (position_ + (uint32_t)tempo * ZYKLUS_MS / 1000) % umfang;

    // Bild berechnen
    const uint16_t schweif = SCHWEIF_LEDS * SUB;
    for (uint8_t i = 0; i < anzahl_; i++) {
      // Abstand dieser LED hinter dem Kopf (ringfoermig gerechnet)
      const uint16_t hinter = (position_ + umfang - i * SUB) % umfang;
      const uint16_t davor  = umfang - hinter;

      uint8_t helligkeit = 0;
      if (hinter < schweif) {
        helligkeit = 255 - (uint32_t)hinter * 255 / schweif;   // Schweif: linear abfallend
      } else if (davor < SUB) {
        helligkeit = 255 - davor;                 // LED direkt vor dem Kopf weich einblenden
      }
      helligkeit = dim8_video(helligkeit);        // Anpassung an die Wahrnehmung des Auges

      leds_[i] = farbe_;
      leds_[i].nscale8(scale8(helligkeit, pegel_));
    }
  }

private:
  CRGB* const leds_;
  const uint8_t anzahl_;
  const CRGB farbe_;
  uint16_t position_;                             // Kopfposition in 1/256 LED
  uint8_t pegel_;                                 // Ueberblendung 0 (aus) .. 255 (voll)
};

// -----------------------------------------------------------------------------
//  Jalousie
//  Leuchtende LEDs = geschlossener Teil. LED 0 ist die Oberkante.
//  Die Istposition folgt der Sollposition mit konstanter Fahrgeschwindigkeit.
// -----------------------------------------------------------------------------
class Jalousie {
public:
  Jalousie(CRGB* leds, uint8_t anzahl, const CRGB& farbe)
      : leds_(leds), anzahl_(anzahl), farbe_(farbe), soll_(0), ist_(0), faehrt_(false) {}

  // helligkeit: 0..1000 Promille
  void update(uint16_t helligkeit) {
    // Sollposition: unterhalb AUF_BIS ganz offen, oberhalb ZU_AB ganz zu, dazwischen linear
    helligkeit = constrain(helligkeit, JALOUSIE_AUF_BIS, JALOUSIE_ZU_AB);
    soll_ = map(helligkeit, JALOUSIE_AUF_BIS, JALOUSIE_ZU_AB, 0, JALOUSIE_WEG);

    const int16_t differenz = soll_ - ist_;
    const bool endlage = (soll_ == 0 || soll_ == (int16_t)JALOUSIE_WEG);

    // Anlaufen erst ab Mindestabweichung; Endlagen werden immer exakt angefahren
    if (!faehrt_) {
      if (abs(differenz) >= (int16_t)(JALOUSIE_ANLAUF_LEDS * SUB) || (endlage && differenz != 0)) {
        faehrt_ = true;
      }
    }

    if (faehrt_) {
      if (abs(differenz) <= (int16_t)JALOUSIE_SCHRITT) {
        ist_ = soll_;                             // Ziel erreicht -> Motor stopp
        faehrt_ = false;
      } else {
        ist_ += (differenz > 0) ? (int16_t)JALOUSIE_SCHRITT : -(int16_t)JALOUSIE_SCHRITT;
      }
    }

    // Bild berechnen: volle LEDs, eine teilweise leuchtende Kante, Rest dunkel
    const uint8_t volleLeds = ist_ / SUB;
    const uint8_t kante     = ist_ % SUB;
    for (uint8_t i = 0; i < anzahl_; i++) {
      uint8_t helligkeitLed = 0;
      if (i < volleLeds)       helligkeitLed = 255;
      else if (i == volleLeds) helligkeitLed = dim8_video(kante);

      leds_[i] = farbe_;
      leds_[i].nscale8(helligkeitLed);
    }
  }

  uint8_t sollProzent() const { return (uint32_t)soll_ * 100 / JALOUSIE_WEG; }
  uint8_t istProzent()  const { return (uint32_t)ist_ * 100 / JALOUSIE_WEG; }
  bool faehrt() const { return faehrt_; }

private:
  CRGB* const leds_;
  const uint8_t anzahl_;
  const CRGB farbe_;
  int16_t soll_;                                  // 0 = offen .. JALOUSIE_WEG = geschlossen
  int16_t ist_;
  bool faehrt_;
};

// =============================================================================
//  4. OBJEKTE UND ZUSTAND
// =============================================================================

CRGB ledsHeizen[ANZAHL_HEIZEN];
CRGB ledsKuehlen[ANZAHL_KUEHLEN];
CRGB ledsJalousie[ANZAHL_JALOUSIE];

AnalogEingang eingangTemperatur(PIN_TEMPERATUR);
AnalogEingang eingangHelligkeit(PIN_HELLIGKEIT);
DigitalEingang drehschalter(PIN_DREHSCHALTER, DREHSCHALTER_PULLUP);

Klimaregler regler;
Lauflicht   lauflichtHeizen(ledsHeizen, ANZAHL_HEIZEN, FARBE_HEIZEN);
Lauflicht   lauflichtKuehlen(ledsKuehlen, ANZAHL_KUEHLEN, FARBE_KUEHLEN);
Jalousie    jalousie(ledsJalousie, ANZAHL_JALOUSIE, FARBE_JALOUSIE);

int16_t  temperatur = 0;                          // Zehntel Grad
uint16_t helligkeit = 0;                          // Promille
bool     sommerbetrieb = false;                   // true = Sommer, false = Winter

uint32_t zeitLetzterZyklus  = 0;
uint32_t zeitLetzteDiagnose = 0;

// =============================================================================
//  5. HILFSFUNKTIONEN
// =============================================================================

// Filterwert (0..MESSWERT_MAX) -> Temperatur in Zehntel Grad
int16_t temperaturBerechnen(uint16_t messwert) {
  return TEMP_BEI_0V + (int32_t)messwert * (TEMP_BEI_5V - TEMP_BEI_0V) / MESSWERT_MAX;
}

// Filterwert (0..MESSWERT_MAX) -> Helligkeit in Promille
uint16_t helligkeitBerechnen(uint16_t messwert) {
  const uint16_t promille = (uint32_t)messwert * 1000 / MESSWERT_MAX;
  return HELLIGKEIT_INVERTIERT ? 1000 - promille : promille;
}

// Zehntelwert als Kommazahl ausgeben, z. B. 215 -> "21.5", -5 -> "-0.5"
void zehntelAusgeben(int16_t wert) {
  if (wert < 0) Serial.print('-');
  wert = abs(wert);
  Serial.print(wert / 10);
  Serial.print('.');
  Serial.print(wert % 10);
}

void diagnoseAusgeben() {
  Serial.print(sommerbetrieb ? F("SOMMER") : F("WINTER"));
  Serial.print(F("  T="));
  zehntelAusgeben(temperatur);
  Serial.print(F(" C  Soll="));
  zehntelAusgeben(regler.sollwert());
  Serial.print(F(" C  Modus="));
  switch (regler.zustand()) {
    case Regelzustand::Heizen:  Serial.print(F("HEIZEN "));  break;
    case Regelzustand::Kuehlen: Serial.print(F("KUEHLEN")); break;
    case Regelzustand::Neutral: Serial.print(F("NEUTRAL")); break;
  }
  Serial.print(F("  |  Licht="));
  zehntelAusgeben(helligkeit);
  Serial.print(F(" %  Jalousie Ist="));
  Serial.print(jalousie.istProzent());
  Serial.print(F(" % Soll="));
  Serial.print(jalousie.sollProzent());
  Serial.print(F(" %"));
  Serial.println(jalousie.faehrt() ? F("  (faehrt)") : F(""));
}

// Einschalttest: jeder Streifen leuchtet kurz in seiner Farbe (Verdrahtungskontrolle)
void lampentest() {
  if (LAMPENTEST_MS == 0) return;

  fill_solid(ledsHeizen, ANZAHL_HEIZEN, FARBE_HEIZEN);
  FastLED.show();
  delay(LAMPENTEST_MS);
  fill_solid(ledsKuehlen, ANZAHL_KUEHLEN, FARBE_KUEHLEN);
  FastLED.show();
  delay(LAMPENTEST_MS);
  fill_solid(ledsJalousie, ANZAHL_JALOUSIE, FARBE_JALOUSIE);
  FastLED.show();
  delay(LAMPENTEST_MS);

  FastLED.clear(true);                            // alles aus
}

// =============================================================================
//  6. SETUP UND LOOP
// =============================================================================

void setup() {
  Serial.begin(BAUDRATE);
  Serial.println(F("\nKlima- und Jalousie-Visualisierung gestartet"));

  FastLED.addLeds<LED_TYP, PIN_LED_HEIZEN,   LED_FARBFOLGE>(ledsHeizen,   ANZAHL_HEIZEN);
  FastLED.addLeds<LED_TYP, PIN_LED_KUEHLEN,  LED_FARBFOLGE>(ledsKuehlen,  ANZAHL_KUEHLEN);
  FastLED.addLeds<LED_TYP, PIN_LED_JALOUSIE, LED_FARBFOLGE>(ledsJalousie, ANZAHL_JALOUSIE);
  FastLED.setBrightness(GESAMT_HELLIGKEIT);
  FastLED.setDither(DISABLE_DITHER);              // kein Flimmern bei dunklen Werten
  FastLED.clear(true);                            // definierter Startzustand: alles aus

  lampentest();

  eingangTemperatur.begin();
  eingangHelligkeit.begin();
  drehschalter.begin();

  zeitLetzterZyklus = millis();
}

void loop() {
  const uint32_t jetzt = millis();

  // ---- fester Programmzyklus ------------------------------------------------
  if (jetzt - zeitLetzterZyklus >= ZYKLUS_MS) {
    zeitLetzterZyklus += ZYKLUS_MS;

    // EINGABE
    eingangTemperatur.update();
    eingangHelligkeit.update();
    drehschalter.update();
    sommerbetrieb = (drehschalter.high() == SOMMER_BEI_HIGH);
    temperatur = temperaturBerechnen(eingangTemperatur.wert());
    helligkeit = helligkeitBerechnen(eingangHelligkeit.wert());

    // VERARBEITUNG
    regler.update(temperatur, sommerbetrieb);
    lauflichtHeizen.update(regler.heizt(), regler.tempo());
    lauflichtKuehlen.update(regler.kuehlt(), regler.tempo());
    jalousie.update(helligkeit);

    // AUSGABE
    FastLED.show();
  }

  // ---- Diagnose auf dem seriellen Monitor -----------------------------------
  if (jetzt - zeitLetzteDiagnose >= DIAGNOSE_MS) {
    zeitLetzteDiagnose = jetzt;
    diagnoseAusgeben();
  }
}
