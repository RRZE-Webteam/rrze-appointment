# Update-Prüfung: atomare Bestätigung und kontrollierte Migration

Stand: **6. November 2026**. Änderungen im aktuellen Arbeitsverzeichnis;
ausschließlich synthetische Daten, kein produktiver Rollout.

## Ergebnis

Die implementierten Änderungen bestehen die lokale Prüfung:

- **52 Suites, 547 Unit-Tests bestanden** (`npm run test:unit -- --runInBand`).
- **51 benannte Integrationschecks bestanden**, einschließlich einer ausdrücklich
  bezeichneten Negativkontrolle unter 2.3.3 und eines wiederholten Testaufbaus.
  Diese Zahl umfasst zusammengefasste Checks, keine 51 voneinander unabhängigen Fehlerfälle.
- PHP-Syntaxprüfung mit PHP 8.3 für 50 Dateien; Formatprüfung der geänderten
  TypeScript-Tests und `git diff --check` bestanden.
- Reale Integration: **WordPress 7.1.4 / PHP 8.4.17 / MySQL 5.7.44**, fünf Sites,
  native mysqli-Verbindungen, getrennte PHP-Prozesse, lokale HTTP-Anfragen und
  ausschließlich lokal aufgezeichnete MIME-Nachrichten.

Die vollständigen, personenbezogen bereinigten Ergebnisse stehen in
[evidence/update-results.json](evidence/update-results.json). Testaufbau und
Wiederholung sind in [README.md](README.md) beschrieben. Der Testbestand liegt
unter `/private/tmp/rrze-appointment-update-20261106-native`; seine SQL-Snapshots
und synthetischen Schlüssel bleiben außerhalb des Repositories. Das Prüf-ZIP
behält Version 2.3.3 und ist kein veröffentlichtes Release.

## Nachgewiesenes Verhalten

**Hinweise:** Tatsächliche WordPress-Screens begrenzen Speicher-/Migrationshinweise
auf Appointments und Appointment-Einstellungen. Auf Multisite sehen ausschließlich
Superadministratoren diese Diagnosen. Verweigerte Kontexte lesen den Speicherstatus
nicht. Normale Bedienfehler bleiben sichtbar. Die isolierten PHP-Renderingtests
prüfen Rollen, Screens, sämtliche Speicherphasen und Initialisierungsfehler.

**Bestätigung:** Reservierungsentfernung, Slot, Metadaten und Stornotoken werden
in einer InnoDB-Transaktion gespeichert. Fehler an jedem der vier Schreibschritte
rollen alle Änderungen zurück. Der alte Link bleibt wiederholbar. Das gilt für
Legacy- und verschlüsselten Betrieb. Prozessabbruch vor Commit und ein tatsächlich
serverseitig getrennter Datenbankzugriff hinterlassen keine Teilbuchung. Ein echter
Commit mit anschließend künstlich unterdrückter Empfangsbestätigung hinterlässt
eine vollständige Buchung; ein erneuter Aufruf erzeugt kein Duplikat. Diese letzte
Prüfung simuliert die Anwendungsgrenze, keinen Netzwerkpaketverlust.

**HTTP-Fehlerfall:** Ein frisch unter 2.3.3 erzeugter Bestätigungslink verliert dort
bei einem serverseitigen SQL-Fehler seine Reservierung (Negativkontrolle). Unter
dem Kandidaten erhält derselbe Fehler sie; Wiederholung liefert HTTP 200 und ein
weiterer Aufruf HTTP 410. Ein Mailfehler nach Commit erhält die fertige Buchung
und zeigt ausdrücklich an, dass sie gespeichert wurde.

**Migration:** Alle fünf entschlüsselten Speicher bleiben beim Upgrade identisch.
Alte Bestätigungs-, Storno-, Opt-out- und Öffnungslinks funktionieren. Abbrüche an
Migrationsgrenzen werden wiederaufgenommen. Fehlende/falsche Schlüssel, fehlende
Zeilen und beschädigte Nutzdaten/Markierungen werden nicht als leerer Bestand
behandelt. Vollständige Datenbankwiederherstellung mit passendem Schlüssel erhält
die Daten. Parallele HTTP-Buchungen vergeben einen Slot einmal; überlappende
Buchungsabläufe und Netzwerk-Worker verlieren keine erwarteten Änderungen.

**Wartungshelfer:** Eine fehlerhafte Site verhindert beim Modus `migrate` bereits
in der vollständigen Netzwerk-Vorprüfung die erste Migration. `preflight` und
`verify` verändern die geprüften Speicher und Cron-Werte nicht. Inaktive Sites
mit Daten werden berücksichtigt, leere inaktive Sites übersprungen. Ein erneuter
vollständiger Lauf schreibt abgeschlossene Speicher nicht neu. Wieder eingeführte
Cron-Klartextargumente werden erkannt und nicht stillschweigend repariert.

**Rollout:** Die lokale Probe lässt alte HTTP- und fremde Cron-Schreiber auslaufen,
migriert und verifiziert alle fünf Sites und öffnet danach den Zugriff wieder.
Die letzte gemessene Pause betrug **5,0 Sekunden**, bei 85 erfolgreichen öffentlichen
HTML-Stichproben. Persönliche Anfragen erhalten während der Pause HTTP 503.
Das ist eine lokale Messung und keine Produktions-Laufzeitzusage.

## Freigabebedingungen und Grenzen

Der frühere Befund F2 ist durch die atomare Bestätigung behoben. F1/F3 werden durch
den vereinbarten vollständigen Schreibstopp vor und während der gesamten Migration
betrieblich ausgeschlossen. Der Plugin-Lock kann fremde, nicht kooperierende
Schreiber nicht kontrollieren. Nachträgliche Prüfung kann verlorene fremde Cron-
Ereignisse nicht rekonstruieren. Der vollständige Drain bleibt verpflichtend.

InnoDB und native mysqli-Unterstützung werden geprüft; es erfolgt keine automatische
Tabellenkonvertierung. Ohne Schlüssel und ohne Migrationsbeginn bleibt der
vereinbarte Legacy-Modus erhalten. Der Wartungshelfer fordert hingegen immer einen
gültigen Schlüssel und vollständige Verschlüsselung für die Abschlussprüfung.

Eine allgemeine Produktionsfreigabe wird hier nicht behauptet:

- WordPress 6.8 und PHP 8.2 waren nicht als lokale Laufzeit vorhanden und wurden
  nicht ausgeführt. Die Mindestangaben sind mit dem Plugin-Header konsistent;
  ihre Laufzeitmatrix muss vor entsprechender Freigabe ergänzt werden.
- FPM/OPcache-Wechsel, mehrere Webserver, persistenter Object-Cache, Replikate und
  die vollständigen Cache-Regeln der Zielplattform wurden nicht simuliert.
- Die öffentliche Ersatzseite der Rollout-Probe deckt genau einen HTML-Pfad ab.
- Die separat dokumentierten doppelten Erinnerungen und langen Sperrzeiten bei
  langsamer synchroner Mailzustellung sind unveränderte Folgearbeiten. Andere
  mehrteilige Fachoperationen erhalten durch diese Änderung keine Transaktion.
- SMTP-Empfang ist nicht transaktional. Frageantworten bleiben request-lokal;
  eine genau-einmalige Mailzustellung wird nicht zugesagt.

Der ältere [Prüfbericht](REPORT.md) bleibt als Nachweis des Ausgangszustands erhalten.
