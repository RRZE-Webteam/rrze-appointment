# Lokale Staging-Abnahme

Der aktuelle Mischbetrieb wird gegen echte Bestandsdaten aus Plugin-Version 2.3.3
geprüft. Die Testdaten entstehen durch Buchungsanfragen und Bestätigungen in
einer isolierten WordPress-Multisite. Ergebnisse, ZIP-Prüfsumme und die offene
Produktionsbedingung stehen im [Staging-Bericht](MIXED-REPORT.md).

```sh
python3 tests/readiness/prepare.py --working-tree --root=/private/tmp/rrze-appointment-mixed-audit
python3 tests/readiness/run.py --root=/private/tmp/rrze-appointment-mixed-audit
python3 tests/readiness/run.py --root=/private/tmp/rrze-appointment-mixed-audit --phases=shutdown
```

Für eine andere PHP- oder WordPress-Version bei der Vorbereitung `--php` bzw.
`--source` angeben. Die Standardprüfung für einen Mischbetriebs-Kandidaten
enthält Build, Seed, HTTP-Abläufe, Cron-Verlust und -Wiederherstellung, V1-
Kompatibilität und Schlüsselausfall, native Transaktionen und CLI-Prüfungen.
Der Versionswechsel im HTTP-Test nutzt den WordPress-Upgrader über
`wp plugin install candidate.zip --force`. Der Prüf-Build verwendet die
Plugin-Version des Quellstands. Für einen neuen Teststand innerhalb derselben
Einweg-Installation `--phases=working_tree,build,seed,mixed_workflows,mixed_jobs,mixed_compat,mixed_atomic,mixed_cli`
verwenden. Das ersetzt das eingefrorene Testpaket und seine bisherigen Ergebnisse.

Die Negativkontrolle hält eine alte Buchungsanfrage gezielt zwischen
Belegungsprüfung und Legacy-Schreibzugriff an. Ihr Bestehen dokumentiert den
reproduzierten Konflikt beim Betrieb zweier Codeversionen. Die Positivkontrolle
lässt diese Anfrage vor dem Paketwechsel abschließen. Der Test überwacht keine
produktiven Server oder deren FPM-/CLI-Prozesse.

## Historische Prüfungen

Die früheren [V1-Ergebnisse](UPDATE-REPORT.md) und [ursprünglichen Befunde](REPORT.md)
beziehen sich auf die damalige Vollmigration. Die folgenden V1-Phasen sind im
jeweils dokumentierten historischen Git-Stand reproduzierbar.

## Isolation

- Ausschließlich synthetische Daten in einer neuen `rrze_appt_audit_*`-Datenbank.
- Eigene MySQL-Instanz mit `--skip-networking`, eigener Datadir und Unix-Socket.
- HTTP ausschließlich `127.0.0.1`; kein öffentlicher Listener, Upload oder Rollout.
- Die lokale Ausgangsinstallation liefert nur Core- und Theme-Dateien. Ihre
  Konfiguration, Datenbank, Schlüssel, Uploads und Cache-Drop-ins werden nicht kopiert.
- PHPMailer erzeugt echte MIME-Nachrichten, der Testtransport schreibt nur lokale
  `.eml`-Dateien. WordPress blockiert externe HTTP-Aufrufe. Browser-Subressourcen
  werden durch eine zusätzliche lokale CSP auf lokale Quellen beschränkt.
- `fault.json`, Uhr, Datenbankausfall und Sperrbarrieren gelten ausschließlich
  innerhalb der so markierten Testinstallation. MU-Plugin und Fixture prüfen diese Markierung.
- Niemals diese Instrumentierung als reguläres Plugin/MU-Plugin installieren.

## Voraussetzungen und Start

Benötigt werden Python 3.9+, lokale WordPress-Dateien, FAU-Elemental und
Twenty Twenty-Five, PHP mit mysqli/sodium, WP-CLI, MySQL 5.7 und ein kompatibler
MySQL-Client sowie die bereits installierten Node-Abhängigkeiten des Repositories.
Die voreingestellten Binärpfade entsprechen dem lokalen MAMP-Rechner und lassen
sich mit `prepare.py --help` anpassen. Es werden keine Abhängigkeiten heruntergeladen.

Vom Repository-Verzeichnis aus, mit einem **neuen** temporären Verzeichnis:

```sh
python3 tests/readiness/prepare.py --root=/private/tmp/rrze-appointment-audit-new
python3 tests/readiness/run.py --root=/private/tmp/rrze-appointment-audit-new
```

`prepare.py` verweigert ein nicht leeres Ziel. Es friert beide Git-Revisionen ein,
installiert eine synthetische Multisite und erzeugt `setup.sql`. Nur nach einem
Abbruch nach der Core-Installation lässt sich dieser Schritt mit `--finish`
fortsetzen; dafür dieselben Pfad-, Port- und Revisionsargumente verwenden.

`run.py` baut DEV in der separaten Kopie mittels `wp-scripts build
--webpack-copy-php` und `build:email`. Das normale `npm run build` mit Versionsbump
wird absichtlich nicht verwendet. Das Prüf-ZIP behält Version 2.3.3.

Die vollständige Prüfung erzeugt danach unter **echter Version 2.3.3** Buchungen,
Bestätigungen, Storno-/Opt-out-Links und Benachrichtigungen. `legacy.sql` ist der
unveränderte Ausgangspunkt der Upgrade-Tests. `encrypted.sql` enthält die überprüfte
Migration. SQL-Snapshots, Testschlüssel und synthetische Zugangsdaten bleiben im
privaten temporären Verzeichnis, außerhalb des Repositories.

Offene Reservierungen laufen regulär nach 30 Minuten ab. Einen Gesamtlauf ohne
längere Pause ausführen. Für zeitabhängige Wiederholungen nach längerer Pause
`seed,upgrade` neu ausführen oder ein neues Audit-Verzeichnis verwenden. Ein
abgelaufener Snapshot ist kein gültiger Nachweis für den Verlust eines noch
gültigen Bestätigungslinks. Die zusätzlichen Überlappungs-/Ablauftests erzeugen
deshalb jeweils frische Reservierungen unter 2.3.3.

## Phasen und Nachweise

| Phase | Zweck |
| --- | --- |
| `build,seed,upgrade` | Prüf-ZIP, Altbestand, erster Besucher, Idempotenz und alte Links |
| `cron_race` | Fremder `_get_cron_array()`/`_set_cron_array()`-Schreiber nach Migration |
| `concurrency,first_concurrent` | Echte Verbindungen, Sperrablauf, konkurrierende Buchungen beim Erstaufruf |
| `faults,recovery` | Abbruchgrenzen, Schreibfehler, Sperrverlust, Schlüssel-/Zeilenfehler, Wiederherstellung |
| `network` | Besuchte, unbesuchte, deaktivierte und beschädigte Sites; Wiederholung nach Reparatur |
| `mail_and_routes` | Echte AJAX-/REST-Abläufe, MIME, Kalenderanhänge, Erinnerungen und Öffnungslink |
| `load,cron_load` | Kleine Bestände, 1.000/10.000 zusätzliche Buchungen, große Cron-Warteschlange |
| `native_smoke` | Vorhandener MySQL-Test mit zwei Verbindungen und temporärer Tabelle |
| `mixed_versions` | Bewusst unsicherer Gegenversuch mit einer nicht ausgelaufenen Altanfrage |
| `slow_mail` | Einfluss einer sieben Sekunden dauernden lokalen Mailzustellung auf Seitenaufrufe |
| `confirmation_failure` | Schreibfehler nach Tokenverbrauch unter 2.3.3 und DEV |
| `sso_boundary` | Standardrechte und sicheres Sperren bei fehlender optionaler SSO-Abhängigkeit |
| `rollout` | Lokale Ablaufprobe mit öffentlicher Ersatzseite, Anfrage-Drain und Versionswechsel |
| `notification_cycle` | Echte Cron-Dispatches, täglicher Fallback und beschleunigter lokaler Tageswechsel |
| `additional` | Echter DB-Ausfall, Cron-Wiederaufnahme, Ablauf alter Reservierungen, gemischte HTTP-/Cron-Last und parallele Netzwerk-Worker |
| `repeat_build` | Zweiter Build derselben eingefrorenen Quellen mit Vergleich der vollständigen ZIP-Prüfsumme |

Einzelne Phasen lassen sich auf einer vorbereiteten Umgebung wiederholen:

```sh
python3 tests/readiness/run.py --root=/private/tmp/rrze-appointment-audit-new --phases=cron_race
```

Voraussetzung sind die zuvor erzeugten Snapshots und das Prüf-ZIP. Jede Phase
stellt ihren synthetischen Ausgangszustand wieder her. Ergebnisse mit demselben
Namen werden bei Wiederholung ersetzt; `results.json` beschreibt den letzten
gemessenen Zustand, keine unveränderliche Laufhistorie. Unerwartete Fehler der
Testhelfer tragen den Zusatz `harness-completion` und sind von Pluginbefunden zu
unterscheiden. Der Runner liefert Exitcode **1**, solange ein gespeicherter Check
fehlschlägt. Dazu gehören der absichtliche Gegenversuch `mixed_versions` und die
lokale Diagnosegrenze von fünf Sekunden in `slow_mail`; diese Einträge sind
keine voneinander unabhängigen Pluginregressionen.

Die Tests erwarten für die festgehaltenen Fehler bewusst das gewünschte sichere
Verhalten. Sie werden dadurch nach einer später separat beauftragten Behebung als
Regressionsnachweis verwendbar. Der Bericht ordnet erwartete Gegenversuche,
Altfehler und neue Risiken getrennt ein.

## Browser und Ende

```sh
python3 tests/readiness/run.py --root=/private/tmp/rrze-appointment-audit-new --phases=preview --keep-server
```

Lokale URLs: `/nat/audit-appointments/`, `/nat/audit-list/`, `/nat/wp-admin/`.
Benutzer `audit`; das zufällige lokale Passwort steht in `admin-password.txt`.
Prüfen: Kalender, belegte Slots, Anfrageformular, Liste, Verwaltung/Storno,
Einstellungen, Mailvorlagen, Editor sowie Site-Wechsel und Sitzungsgrenze.
Browserprüfungen sind manuell geführt und separat im Bericht dokumentiert.
`baseline_preview` stellt für einen Browservergleich zuerst die unverschlüsselte
Baseline-Datenbank und dann Version 2.3.3 wieder her. `invalidate_session` verwirft
nur die Sitzungen des synthetischen Administrators; mit `--keep-server` lässt sich
danach im geöffneten Browser die erneute Anmeldepflicht prüfen. Keine dieser
Hilfsphasen wird als automatisiert bestandener Browsernachweis gezählt.

Zum Abschluss unbedingt die **eigenen** Dienste stoppen:

```sh
python3 tests/readiness/run.py --root=/private/tmp/rrze-appointment-audit-new --phases=shutdown
```

`shutdown` verifiziert die gespeicherte PHP-Prozesszuordnung und nutzt ausschließlich
den Socket dieser Audit-Instanz. Daten und Nachweise bleiben erhalten. Ein
Code-Downgrade auf der verschlüsselten Datenbank ist kein Wiederherstellungsverfahren.

## Messgrenzen

HTTP arbeitet mit acht PHP-CLI-Server-Workern, 256 MiB und einem PHP-Zeitlimit von
30 Sekunden; OPcache ist ausgeschaltet. CLI-Prozesse haben andere Limits. Die
HTTP-Spitzenwerte stehen separat in `request-metrics.jsonl`, ohne Query-Strings.
Das ist keine Simulation von FPM, Redis, mehreren Webservern oder Replikaten.

`audit-clock.php` verschiebt nur `current_datetime()` im Notification-Namespace.
Die Systemuhr und Kryptographie bleiben unverändert. Der Tageswechsel und die
Netzwerk-Wiederholung werden beschleunigt; ein echter 24-Stunden-Dauerlauf wurde
damit nicht ersetzt. Der Rollout-Proxy hält genau die geprüfte öffentliche Seite
als HTML erreichbar und sperrt persönliche Antworten. Vollständige Website-
Auslieferung, OPcache-Wechsel und die Infrastruktur eines Hosters sind separat
nachzuweisen. Es werden dafür keine Produktionsdaten angefordert oder verwendet.

## Prüfung der atomaren Bestätigung und des Wartungshelfers

`prepare.py --working-tree` friert die aktuellen versionierten und nicht ignorierten
Quelldateien ein und erfasst einen Inhaltsdigest. Damit werden auch neue Dateien
getestet, ohne sie zuvor committen zu müssen. Der bestehende Git-Referenzmodus bleibt
verfügbar. Die Datenbank und Testschlüssel bleiben vollständig getrennt von der
lokalen Ausgangsinstallation.

```sh
python3 tests/readiness/prepare.py --root=/private/tmp/rrze-appointment-update-new --working-tree
python3 tests/readiness/run.py --root=/private/tmp/rrze-appointment-update-new --phases=build,seed,upgrade,atomic,confirmation_failure,confirmation_delivery_failure,maintenance,rollout,concurrency,faults,recovery,network,mail_and_routes,first_concurrent,additional
python3 tests/readiness/run.py --root=/private/tmp/rrze-appointment-update-new --phases=shutdown
```

- `atomic`: echtes InnoDB-Rollback an allen vier Bestätigungsschreibschritten,
  Wiederholung alter Tokens, Abbruch des PHP-Prozesses vor Commit, serverseitiger
  Verbindungsabbruch, Ablehnung bestehender Transaktionen und von MyISAM.
  Ein zusätzlicher Test führt den echten Commit aus und simuliert danach die
  fehlende Bestätigung an die Anwendung; er ist kein echter Netzwerkpaketverlust.
- `confirmation_failure`: frischer unter 2.3.3 erzeugter Link, serverseitiger
  SQL-Trigger als Fehlerquelle, danach erfolgreicher HTTP-Retry unter dem Kandidaten.
  Der Verlust unter 2.3.3 wird als ausdrücklich bezeichnete Negativkontrolle erwartet.
- `confirmation_delivery_failure`: fehlgeschlagener Mailversand nach Commit;
  Buchung bleibt gespeichert und ein erneuter Linkaufruf erzeugt kein Duplikat.
- `maintenance`: fehlgeschlagene Netzwerk-Vorprüfung ohne Teilmigration,
  unverändernde Prüfmodi, inaktive Sites, wiederholter Lauf ohne Schreibzugriffe,
  ausgelaufener fremder Cron-Schreiber und Erkennung nachträglich eingefügten Klartexts.
- `rollout`: berücksichtigt zusätzlich einen fremden Cron-Schreiber und prüft
  alle fünf Sites mit dem produktiven CLI-Helfer vor Wiederfreigabe.

Die atomaren SQL-Zugriffe umgehen bewusst WordPress' automatische SQL-Wiederholung.
Darum lösen diese Tests Fehler direkt in MySQL aus; ein WordPress-Query-Filter
allein würde den entscheidenden Schreibpfad nicht erreichen. Erfolgreiche Wiederholungen
einer zuvor abgebrochenen Prüfphase ersetzen deren `harness-completion`-Fehler durch
einen dokumentierten Wiederherstellungsnachweis. Fachliche Assertions bleiben getrennt.
