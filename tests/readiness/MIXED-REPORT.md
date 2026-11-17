# Staging-Abnahme des Mischbetriebs

**10. November 2026.** Das geprüfte ZIP kann auf Staging installiert werden. Für die erste Produktivinstallation ist der Versionswechsel der laufenden HTTP-, Cron- und CLI-Prozesse auf den tatsächlichen Servern offen.

## Geprüftes Paket

Das ZIP wurde aus dem Arbeitsstand gebaut und über den WordPress-Upgrader mit `wp plugin install candidate.zip --force` in zwei isolierte Multisite-Installationen eingespielt. Beide erhielten bytegenau dasselbe Paket. Sein SHA-256 ist `0385cecfd4ab8d4dc3e25ef69496201f3bbdccae80f1aa009f5afcc93ca3b432`. Der Staging-Build trägt die Plugin-Version 2.3.3. Die neue Datenversion ist 2. Für eine Veröffentlichung über den regulären Updatekanal ist eine höhere Plugin-Version zu vergeben.

| Umgebung | Ergebnis |
| --- | --- |
| WordPress 6.8, PHP 8.2.35, MySQL 5.7.44, fünf Subsites | 141 benannte Integrationsprüfungen bestanden |
| WordPress 7.1.4, PHP 8.2.35, MySQL 5.7.44, fünf Subsites | Dieselben 141 Prüfungen sowie die Buildprüfung bestanden |
| Gesamte Unit-Suite mit PHP 8.5.12 für die PHP-Fixtures | 535 Tests in 53 Suites bestanden |
| PHP 8.2.35 | Syntax aller 55 PHP-Dateien des Plugins geprüft |

Die Zahlen enthalten einzelne Fehler- und Wiederholungszusicherungen sowie die gezielte Negativkontrolle. Die lokalen Testinstanzen wurden nach der Abnahme beendet. Die [maschinellen Ergebnisse](evidence/mixed-results.json) enthalten die einzelnen Prüfungen. Die [Anleitung](README.md) beschreibt die Wiederholung.

## Nachgewiesenes Verhalten

- Kalender, Listenansicht, Verwaltung, REST und alte Aktionslinks wurden über reale WordPress-Anfragen geöffnet. Die geschützten Optionen einschließlich Autoload-Einstellung blieben bei den Vorschauen bytegleich. Eine fachliche Änderung über das jeweilige Formular übernahm anschließend die betroffene Buchung. Vier unbeteiligte Subsites und ein fremdes Cron-Ereignis dienten als Kontrollbestand.
- Die erste öffentliche Reservierung erzeugte bereits einen verschlüsselten Datensatz. Die Bestätigung behielt seine interne ID. Von vier gleichzeitigen HTTP-Anfragen auf denselben Slot erhielt eine die Reservierung. Verbrauchte Links wurden mit HTTP 410 abgewiesen.
- Ein realer WordPress-Prozess schrieb einen älteren Cron-Schnappschuss zurück. Der Wecktermin ging dabei verloren. Ein frischer Plugin-Start stellte ihn aus den verschlüsselten Aufträgen wieder her. Die Prüfung umfasste auch die ursprüngliche Fälligkeit eines übernommenen Auftrags und einen verspäteten alten Callback.
- Vollständige und begonnene V1-Migrationen wurden gelesen und gezielt übernommen. Ein falscher oder fehlender Schlüssel, manipulierte Versionsangaben und beschädigte oder fehlende Datensätze führten zum Speicherfehler. Die Fehlertests verglichen die gespeicherten Bytes vor und nach dem Zugriff.
- Serverfehler beim Schreiben, ein Prozessabbruch vor dem Commit und der Verlust der Datenbankverbindung ließen die alte Reservierung wiederholbar. Nach einem erfolgreichen Commit mit künstlich unterdrückter Empfangsbestätigung blieb genau eine fertige Buchung bestehen. Die CLI-Integritätsprüfung für den Mischbetrieb war erfolgreich. Die strenge Vollverschlüsselungsprüfung und der alte Befehl zur Komplettmigration lieferten für denselben gemischten Bestand einen Fehlerstatus.

## Produktionsbedingung

Im Gegenversuch wurde ein echter 2.3.3-Request nach seiner Belegungsprüfung vor dem Schreiben angehalten. Währenddessen wurde das ZIP eingespielt und derselbe Slot mit der neuen Version reserviert. Nach Freigabe des alten Requests meldeten beide Anfragen Erfolg. Der verschlüsselte Datensatz blieb bytegleich. Der Mischspeicher erkannte den widersprüchlichen Legacy-Eintrag und blockierte die Buchungsansichten der betroffenen Site.

Im zweiten Versuch schloss die alte Anfrage vor dem Update ab. Die neue Version erkannte die vorhandene Reservierung, lehnte denselben Slot ab und konnte einen anderen Slot buchen. Vor dem Produktivstart muss der Betrieb diese Reihenfolge für alle potenziell schreibenden alten Prozesse nachweisen. Das zeitweise Auslassen der Appointment-Hooks im Cron-Runner deckt den Hintergrundteil ab. Der Webserverwechsel einschließlich OPcache und langlebiger CLI-Prozesse gehört ebenfalls zum Nachweis. Die lokalen Tests verwendeten weder einen produktiven FPM-Pool noch einen persistenten Objektcache.

Der Mischbetrieb schützt neue und geänderte Daten. Unveränderte Altbuchungen und ihre historischen Cron-Argumente können bis zur Änderung, Ausführung oder regulären Löschung im Altformat vorliegen.
