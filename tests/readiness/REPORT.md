# Produktionsreifeprüfung: 2.3.3 → DEV 13cacbc

> Historischer Prüfstand `13cacbc`. Den aktuellen Änderungsumfang und die erneuten
> Nachweise beschreibt [UPDATE-REPORT.md](UPDATE-REPORT.md).

Stand: **23. Oktober 2026**. Ausschließlich lokal, mit synthetischen Daten.

## Entscheidung: No-Go

Die normale Migration erhält alle fünf Speicher; bestehende Links und die normale
Buchungskette funktionieren in der lokalen Prüfung. Auch Last und die kurze
lokale Rollout-Pause sind unauffällig. Eine Freigabe ist dennoch nicht begründet:

1. **P1: Ein fremder Cron-Schreiber kann nach der Migration Klartext-Argumente
   wiederherstellen und inzwischen angelegte Ereignisse verlieren lassen.**
2. **P1, bereits in 2.3.3 vorhanden: Ein Schreibfehler während der Bestätigung
   kann die offene Reservierung verbrauchen, ohne eine Buchung zu speichern.**
3. Der sichere Versionswechsel muss sämtliche alten PHP-Anfragen und andere
   Schreiber berücksichtigen. Ein bewusst unsicherer Gegenversuch bestätigt,
   dass eine gewöhnliche Altanfrage verschlüsselte Daten überschreiben kann.

Zusätzlich bestehen Risiken durch doppelte Erinnerungen und lange Sperrzeiten
bei langsamer synchroner Mailzustellung. Es wurden **keine Pluginfehler behoben**,
keine Produktionsdaten verwendet und nichts veröffentlicht oder ausgerollt.

Die leere Editorfläche im internen Browser ist **kein Freigabehindernis**:
Sie trat im Vergleich sowohl mit 2.3.3 als auch mit DEV auf. Der Nutzer bestätigt,
dass der Editor in Firefox funktioniert. Diese Firefox-Beobachtung stammt vom
Nutzer; sie wurde nicht als eigener automatisierter Test gezählt.

## Reproduzierbarer Prüfstand

| Bestandteil | Tatsächlich geprüft |
| --- | --- |
| Baseline | Version 2.3.3, lokaler Git-Stand `28b5552dc96171826ce22603964f4c67dd06a0b7` |
| Kandidat | DEV `13cacbc75a39ddef599c005ca4f1fbd984143547` |
| WordPress / PHP / Datenbank | 7.1.3 / 8.4.17 / MySQL 5.7.44 |
| Netzwerk | Fünf synthetische Sites; `nat` ist Site 2 |
| Theme / Zeitzone | FAU-Elemental / Europe/Berlin |
| HTTP | Acht PHP-CLI-Server-Worker auf `127.0.0.1:8898`, 256 MiB, PHP-Zeitlimit 30 Sekunden |
| CLI | Separater PHP-Prozess je Aufruf; `memory_limit=-1`, Zeitlimit 0 |
| Cache / OPcache | Kein persistenter Object-Cache, keine übernommenen Cache-Drop-ins, CLI-OPcache ausgeschaltet |
| Cron / Mail | Automatischer Besucher-Cron deaktiviert; kontrollierte echte WordPress-Dispatches; lokaler PHPMailer-MIME-Sink |
| DB-Routing | Eigene MySQL-Instanz, Unix-Socket, `--skip-networking`, keine Replikate |
| Build | Node 26.12.1; installierte `@wordpress/scripts` 30.27.0, Maizzle 6.0.13 |

Die lokale MAMP-Site `nat.multisite.localhost:8890` wurde zunächst direkt lesend
inspiziert, ohne DEV über WordPress zu starten. Dort waren die fünf Speicher
bereits verschlüsselt. Für einen belastbaren Upgrade-Test wurden deshalb eigene
Daten unter der tatsächlichen Version 2.3.3 erzeugt. Die Vorlage lieferte nur
Core- und Theme-Dateien; ihre Datenbank und ihr Schlüssel wurden nicht kopiert.

Die getrennte Testinstallation liegt unter:

```text
/private/tmp/rrze-appointment-readiness-20261023-run2
```

`prepare.py` und `run.py` einschließlich Aufrufreihenfolge und Sicherheitsgrenzen
sind in [README.md](README.md) beschrieben. Der Build läuft in einer eingefrorenen
Git-Kopie, ohne den normalen Versionsbump. Das ZIP bleibt absichtlich Version
2.3.3 und ist kein fertig versioniertes Produktionsrelease.

**Prüf-ZIP:** `candidate.zip` im genannten temporären Verzeichnis. Zwei unabhängige
Build-Ausführungen innerhalb derselben lokalen Toolchain lieferten byteidentische
ZIPs mit SHA-256:

```text
afa66ad80bcbba62cd5bbadac86f7c1b53db098293430cbf8ceb821612d7e7ac
```

Die normale MAMP-Datenbank wurde nicht verändert. Beide ausschließlich für diese
Prüfung gestarteten MySQL-Instanzen sowie die Test-HTTP-Worker wurden anschließend
gestoppt. Die privaten lokalen Snapshots, Schlüssel und MIME-Dateien bleiben zur
Nachvollziehbarkeit im temporären Verzeichnis erhalten.

## Ergebnisübersicht

- Vorhandene Tests erneut ausgeführt: **52 Suites, 539 Tests bestanden**, 38,336 s.
  Befehl: `npm run test:unit -- --runInBand`.
- Ergänzende reale Integrationsprüfungen: **54 benannte Checks, davon 48 bestanden
  und 6 nicht bestanden**. Keine verbliebenen `harness-completion`-Fehler.
- Die sechs negativen Assertions sind nicht sechs neue Regressionen: Sie enthalten
  denselben Bestätigungsfehler für beide Versionen, einen bewusst unsicheren
  Rollout-Gegenversuch und eine lokale Latenz-Diagnosegrenze.
- Vollständige Zahlen und logische/physische Speicherhashes:
  [evidence/results.json](evidence/results.json). Manuelle Browserbeobachtungen
  werden getrennt dokumentiert und erhöhen die automatisierte Testanzahl nicht.

| Prüffeld | Nachweis |
| --- | --- |
| Erster Besucher / Daten und Typen | SHA-256 über PHP-`serialize()` aller fünf entschlüsselten Arrays vor/nach Migration identisch; 5/5 abgeschlossen, HTTP 200 |
| Wiederholte Initialisierung | Speicherwerte und Migrationsmarkierung werden nicht erneut geschrieben |
| Abbruch / Fehler | Vor erster Markierung, nach Nutzdaten vor Fortschritt, mittlerer Speicher, Cron-Schreibfehler und verlorene Sperre: Wiederaufnahme mit identischen logischen Inhalten |
| Schlüssel / beschädigte Speicher | Fehlender, falscher oder ungültiger Schlüssel; beschädigtes Payload, fehlende Zeile und kaputte Markierung blockieren alle fünf Zugriffe; keine Behandlung als leerer Bestand |
| Wiederherstellung | Vollständiger verschlüsselter DB-Snapshot mit passendem Schlüssel liefert identische Speicher |
| DB-Ausfall | Eigene MySQL-Instanz tatsächlich gestoppt und neu gestartet: öffentlicher Fehler HTTP 500; AJAX liefert WordPress-DB-Fehler mit HTTP 200, aber keine Erfolgsmeldung; Bestand unverändert, anschließend HTTP 200 |
| Konkurrenz | Fünf getrennte PHP-Schreiber verlieren keine Änderung; fünf HTTP-Buchungen desselben Slots erzeugen genau eine erfolgreiche Reservierung |
| Erstaufruf unter Konkurrenz | Zwei Seitenaufrufe und drei Buchungen während des ersten Starts: genau eine Reservierung, Altbuchungen unverändert |
| Gemischte Abläufe | Seitenaufruf, Buchung, Bestätigung, Storno und Cron gleichzeitig beim Upgrade: erwartete drei bestätigte Buchungen plus genau eine neue Reservierung, keine verlorene Änderung |
| Sperren | Reale zweite DB-Verbindung blockiert Zugriff; nach Freigabe funktioniert Lesen wieder. Die gemessenen 30,765 s gehören zum gesamten CLI-Probeablauf mit mehreren Versuchen, nicht zu einem einzelnen `GET_LOCK()` |
| Multisite | Besuchte/unbesuchte/deaktivierte Site, leere Site und beschädigte Site unterschieden; Fehler isoliert, reparierte Site im beschleunigten Wiederholungslauf migriert |
| Parallele Netzwerk-Worker | Zwei Worker plus Besucher; ein Worker bearbeitet fünf Sites, der konkurrierende Worker überspringt den belegten Koordinator; Site-2- und Site-3-Payloads bleiben getrennt und identisch |
| Altlinks | Bestätigung, Storno, Opt-out und Öffnungslink funktionieren; verbrauchte Bestätigung/Storno und abgelaufene Bestätigung liefern HTTP 410; persönliche Antwort trägt `no-store, private` |
| Reservierungsablauf | Unter 2.3.3 frisch erzeugte Reservierung bleibt nach Upgrade zunächst gesperrt; beschleunigter Ablauf entfernt sie, bestätigte Buchungen bleiben erhalten |
| Neue Buchung / Mail | Echter AJAX-Aufruf und Bestätigung; drei MIME-Nachrichten an synthetischen Booker/Host, zwei gültige Kalenderteile mit `BEGIN:VCALENDAR` |
| Erinnerungen / Öffnung | Drei migrierte Reminder-Jobs liefern sechs Nachrichten; Öffnungsjob liefert eine Nachricht und funktionierenden Claim-Link |
| Vorübergehend fehlender Schlüssel | Drei opaque Reminder-Jobs werden ohne Mailversand mit denselben Referenzen erneut geplant; nach Schlüsselwiederherstellung liefern sie sechs Nachrichten |
| Tageswechsel | Echte `wp cron event run`-Dispatches, Notification-Kalender von 23:59 auf 00:01 verschoben: gleiche Ergebnisse wie 2.3.3; Dublettenbefund siehe F4 |
| Rechte / SSO-Grenze | Management-REST anonym 403, Administrator mit gültiger REST-Nonce 200; aktivierter optionaler SSO-Schutz ohne Abhängigkeit 503, öffentliche Seite weiterhin 200 |

Geänderte Ablaufzeiten werden nur im Testbestand bzw. im Notification-Namespace
gesetzt. Systemuhr, Schlüssel und WordPress-Core bleiben unverändert. Der
Netzwerk-Retry wird über `next_batch=0` beschleunigt. Dies ist ein geprüfter
logischer Tageszyklus, kein 24-Stunden-Dauerlauf.

## Lokale Laufzeit- und Speicherwerte

Einzelmessungen auf diesem Rechner, keine Perzentile oder Hosting-Zusagen.
Zusätzliche Datensätze sind rein synthetisch. Die Hashvergleiche berücksichtigen
den unmittelbar vor dem Upgrade gelesenen Ausgangszustand einschließlich der
regulären Bereinigung bereits abgelaufener Einträge.

| Szenario | Erster HTTP-Aufruf | HTTP-Spitzenspeicher |
| --- | ---: | ---: |
| Kleine Ausgangsmenge | 0,3731 s | 40,5 MiB |
| 1.000 zusätzliche Buchungen | 0,4857 s | 46,5 MiB |
| 10.000 zusätzliche Buchungen, ca. 6,7 MB Klartextspeicher | 1,7629 s | 121,9 MiB |
| 10.000 zusätzliche Buchungen und 10.003 zusätzliche Cron-Jobs | 2,5783 s | 142,5 MiB |

Im großen Cron-Test blieben **10.010/10.010 erfasste Ereignisse** einschließlich
Zeitstempeln und Hooks erhalten; persönliche Argumente waren anschließend opaque.
Fünf weitere normale Seitenaufrufe benötigten zuletzt 0,3753–0,6366 s, jeweils
HTTP 200. Ein warmer persistenter Object-Cache wurde nicht simuliert.

Die Zehn-Sekunden-Grenze des Netzwerk-Workers wird laut
`includes/Storage/NetworkMigration.php:174` erst **zwischen** Sites geprüft. Sie
begrenzt weder eine einzelne große Site noch garantiert sie deren Abschluss.
Die gemessenen Szenarien erzeugten keinen wiederholten Abbruch ohne Fortschritt.

## Priorisierte Befunde

### F1 — P1: Fremder Cron-Schreiber überschreibt die migrierte Warteschlange

**Reproduktion:** `--phases=cron_race` nach `build,seed,upgrade`. Ein separater
WordPress-Prozess ohne Appointment-Plugin liest `_get_cron_array()` und wartet.
Der Kandidat migriert die fünf Speicher und Cron. Danach wird ein weiterer
fremder Cron-Eintrag angelegt. Nun schreibt der wartende Prozess seinen alten
Stand mit dem echten `_set_cron_array()` zurück.

**Beobachtet:** Vorher null Klartext-Argumente, danach fünf; Markierung weiterhin
`encrypted`. Der nach der Migration angelegte Eintrag fehlt. Der fremde Prozess
endet erfolgreich. Der nachfolgende Pluginstart repariert das nicht.

**Auswirkung:** Die Datenschutzannahme über Cron-Argumente stimmt nicht mehr;
gleichzeitig können neu geplante Aufgaben verloren gehen. Dieser Schreiber
modelliert insbesondere WordPress auf einer inaktiven Site oder CLI-/Fremdcode,
der die Appointment-Sperre nicht verwendet. Es wird nicht behauptet, dass jeder
normale Seitenaufruf der aktivierten Site genau diese Reihenfolge durchläuft.
Auch bei wenigen Buchungen bleibt der Ablauf möglich.

**Ursache:** Die bedingte Aktualisierung in
`includes/Storage/BookingScheduler.php:134` schützt den eigenen Schreibzugriff.
Sie schützt nicht vor späteren unkoordinierten WordPress-Schreibern der gesamten
Cron-Option. Die per-Site-Sperre wird von diesen Schreibern nicht beachtet.

**Erforderliche Abhilfe:** Ein belegtes Verfahren gegen veraltete Gesamtstände
aller beteiligten Cron-Schreiber oder eine vollständige betriebliche Stilllegung
dieser Schreiber bis zum Abschluss der Migration aller relevanten Sites.
Ein nachträgliches Entfernen von Klartext allein stellt verlorene Ereignisse
nicht wieder her. Den Gegenversuch anschließend erneut bestehen lassen.

### F2 — P1: Bestätigung verliert Reservierung bei einem Schreibfehler

**Reproduktion:** `--phases=confirmation_failure` auf einem frischen Baseline-
Snapshot. Den echten alten Bestätigungslink aufrufen und genau den Schreibzugriff
auf `rrze_appointment_booked_slots` nach dem Tokenverbrauch fehlschlagen lassen.
Der Test verwendet einen echten SQL-Fehler in der isolierten Datenbank.

**Beobachtet:** Bei **2.3.3 und DEV** fehlt danach sowohl die offene Reservierung
als auch die bestätigte Buchung. Baseline antwortet HTTP 200, DEV HTTP 500. Die
Fehlerantwort von DEV ist eine Verbesserung; die Reservierung ist trotzdem
nicht wiederholbar. Der Fault wurde in beiden Abläufen tatsächlich ausgelöst.

**Ursache:** `includes/Controller/ConfirmationController.php:86` verbraucht zunächst über
`TokenManager::confirmPending()` den Token; erst anschließend wird der Slot
gespeichert (`includes/Controller/ConfirmationController.php:98`). Die einzelne Speichersperre
macht diese Folge mehrerer dauerhafter Änderungen nicht atomar.

**Erforderliche Abhilfe:** Wiederaufnehmbare oder atomare Bestätigung über
Reservierung, Slot, Metadaten und Folgeoperationen. Der Token darf bei einem
fehlgeschlagenen Abschluss nicht unwiederbringlich verloren sein. Fehlergrenzen
erneut prüfen, einschließlich wiederholtem Aufruf desselben Links.

**Einordnung:** Bestehender Zuverlässigkeitsfehler, keine neu eingeführte
Verschlüsselungsregression. Er verletzt dennoch das vereinbarte Freigabekriterium
für sichere Fehlerbehandlung und Wiederaufnahme.

### F3 — P1 als Rollout-Bedingung: Alte gewöhnliche Anfragen müssen auslaufen

**Reproduktion:** `--phases=mixed_versions`. Eine echte 2.3.3-Seitenanfrage wird
unmittelbar vor ihrem bereinigenden `update_option()` angehalten. Ein zweiter
Prozess migriert bereits mit DEV. Danach darf die alte Anfrage weiterlaufen.

**Beobachtet:** Der Pending-Speicher wird wieder zu Klartext, während die Markierung
`encrypted` bleibt. Alle fünf Speicherzugriffe werden anschließend blockiert;
die Altanfrage endet im Gegenversuch mit HTTP 500.

**Auswirkung / Abhilfe:** Eine Formularsperre reicht nicht. Vor dem Versionswechsel
müssen alle alten PHP-Anfragen, Cron-/CLI-Prozesse und sonstigen Schreiber
beendet bzw. kontrolliert ausgelaufen sein. Alle neuen Worker müssen denselben
Code verwenden; OPcache muss dabei berücksichtigt werden. Dies ist ein
absichtlicher negativer Kontrollversuch für den Rollout, kein behaupteter Fehler
des erfolgreich getesteten Ablaufs mit vorherigem Drain.

### F4 — P2, bestehend: Doppelte Erinnerungen

**Reproduktion:** `--phases=notification_cycle`. Drei gültige Buchungen, jeweils
ein Host und ein Booker; am Erinnerungstag erst die Einzelereignisse und dann den
täglichen Prüflauf über WordPress ausführen.

**Beobachtet:** Beide Versionen senden zunächst sechs und anschließend weitere
sechs Nachrichten. Nach dem beschleunigten Tageswechsel sind es null.

**Ursache / Abhilfe:** Einzelcallback und täglicher Fallback senden ohne gemeinsamen
Versandnachweis (`includes/Notification/Reminder.php:87` und `:259`). Versand pro
Buchung/Empfänger/Erinnerungstermin idempotent machen und Fehler-/Wiederholungsfälle
einschließlich Tageswechsel prüfen. Ein erfolgreicher Mail-Sink beweist noch
keine Zustellgarantie eines tatsächlichen Mailservers.

### F5 — P2: Langsame Mailzustellung verlängert Speichersperren

**Reproduktion:** `--phases=slow_mail`; lokale Zustellung künstlich sieben Sekunden
verzögern und währenddessen dieselbe öffentliche Buchungsseite aufrufen.

**Beobachtet:** Die Buchungsanfrage dauert 7,3312 s, die gewöhnliche Seite 7,07 s;
beide liefern HTTP 200. Die negative Assertion verwendet fünf Sekunden als
**lokale Diagnosegrenze**, nicht als vereinbartes Produktions-SLA.

**Auswirkung / Abhilfe:** Der Schutz über den gesamten Request kann auch normale
Besucher auf synchrone Mailzustellung warten lassen. Sperren auf notwendige
Speicheränderungen begrenzen oder Mailversand zuverlässig entkoppeln; danach
Konsistenz- und Parallelitätsprüfungen wiederholen. Ein behaupteter kompletter
Seitenausfall wäre durch diese Messung nicht belegt.

### F6 — P2: Release-Metadaten widersprechen sich

`rrze-appointment.php` deklariert WordPress **6.8** und PHP **8.2**, während
`package.json` WordPress **6.0** und PHP **7.4** als Anforderungen sowie WordPress
6.5 als getestet nennt. Der README-Generator übernimmt diese Paketfelder. Diese
Paketwerte stehen bereits im lokalen Baseline-Commit.

**Abhilfe:** Metadaten vor einer Veröffentlichung vereinheitlichen, die tatsächlich
zugesagten Mindestversionen lokal prüfen und eine neue Release-Version vergeben.
Der unveränderte Versionsstring des Prüf-ZIPs ist beabsichtigt und kein Nachweis,
dass ein reguläres Update unter derselben Version angeboten werden sollte.

## Browsernachweise

Mit dem gebauten DEV-ZIP wurden im internen Browser lokal geprüft:

- Kalender sperrt drei bestätigte Alttermine und die noch gültige Reservierung.
- Formular nimmt synthetische Daten an und zeigt die erfolgreiche Anforderung;
  der reservierte Termin fehlt anschließend auch in der Listenansicht.
- Verwaltung lädt drei Altbuchungen über ihre reale REST-Anbindung. Stornierung
  einer Altbuchung reduziert den Bestand sichtbar auf zwei und meldet den Versand
  der ausschließlich lokal abgefangenen Stornomails.
- Einstellungen zeigen den verschlüsselten Speicherstatus und die gespeicherten
  Erinnerungswerte; Mailvorlagenliste und bearbeitbare Vorlagenfelder öffnen sich.
- Nach serverseitigem Widerruf der synthetischen Administratorsitzung führt ein
  erneuter Verwaltungsaufruf wieder zur Anmeldung. Das prüft eine ungültig gewordene
  Sitzung, keinen mehrstündigen natürlichen Cookie-Ablauf.

Screenshots: [Buchungsanforderung](evidence/browser-request.png),
[Verwaltungsstorno](evidence/admin-cancellation.png),
[DEV-Editor im internen Browser](evidence/editor-inconclusive.png),
[Baseline-Vergleich](evidence/editor-baseline-inconclusive.png).
Die Editor-Einschränkung betrifft laut Nutzer nur den internen Browser; Firefox
funktioniert. Sie wird deshalb nicht als Pluginfehler bewertet.

## Rollout-Generalprobe und verbleibende Nachweise

Die lokale Probe mit `--phases=rollout` hält zuerst neue private/Buchungsanfragen
an, lässt eine tatsächlich laufende alte Buchungsanfrage mit verzögerter Mail
fertig werden, stoppt die alten Worker, installiert DEV, migriert und ermöglicht
eine neue erfolgreiche Buchungsanfrage. Die gemessene Pause beträgt **4,008 s**.
**68 öffentliche Stichproben** liefern HTTP 200. Die private Testanfrage während
der Pause erhält HTTP 503 und wird nicht gecacht. Die Buchungsantworten vor und
nach dem Wechsel sind erfolgreich; Datenerhalt wird zusätzlich in den getrennten
Migrationstests über alle fünf Speicher geprüft.

Der Ersatzserver hält nur **die exakt freigegebene öffentliche HTML-Seite**
erreichbar. Diese Probe bestätigt nicht die vollständige Auslieferung beliebiger
Seiten/Assets, FPM-Worker, mehrere Webserver, Redis, DB-Replikate oder einen
OPcache-Wechsel. Ein reiner Code-Downgrade ist nach Verschlüsselung kein Rückweg;
eine Rücksicherung nach Wiederfreigabe muss außerdem neu eingegangene Buchungen
erhalten. Das Zurückführen solcher nachträglicher Buchungen in ein altes Backup
wurde nicht implementiert oder als sicher nachgewiesen.

Für eine spätere Freigabe bleiben ausdrücklich offen:

1. F1 und F2 wirksam beheben oder den jeweiligen Fehlerfall belastbar ausschließen;
   anschließend die dokumentierten Reproduktionen bestehen lassen.
2. Den vorgesehenen Rollout mit vollständigem Anfrage-Drain, privatem Cache-
   Ausschluss, Worker-/OPcache-Wechsel und Wiederherstellung nach neuen Buchungen
   anhand einer passenden **lokalen** Infrastrukturprobe nachweisen.
3. Echte SSO-Anmeldung nur dann separat nachweisen, wenn sie für den Einsatz
   vorgesehen ist. Hier wurden keine externen Identitätsdienste angesprochen.
4. Mindestversionen und gegebenenfalls persistenten Cache prüfen; realen
   Langzeitbetrieb sowie Fehler genau über Tagesgrenzen ergänzen. Die lokale
   Zeitbeschleunigung und der erfolgreiche Reminder-Retry ersetzen das nicht.
5. Kalenderanhänge wurden strukturell und anhand der echten MIME-Nachrichten
   geprüft. Darstellung in verschiedenen Mail-/Kalenderclients sowie sämtliche
   Editor-/Template-Speicherpfade wurden nicht vollständig durchgespielt.

Es werden dafür keine Produktionsdaten benötigt oder angefordert. Geringe
Buchungszahlen reduzieren die Last; sie beseitigen die belegten Fehlerabläufe
nicht. Gemäß den vereinbarten Kriterien bleibt die Empfehlung **No-Go**.
