# Mixed-format booking storage

With a configured encryption key, a site accepts legacy 2.3.3 data, fully or
partly encrypted V1 options and V2 records. Calendar, administration and token
previews merge these into one booking view. Opening a link displays its action
and requires a nonce-protected POST to confirm, cancel, change waitlist
preferences or claim an opening. Unchanged legacy bookings remain in their
existing format. Expiration and retention use background cleanup.

## Record and format boundaries

A business operation owns one reservation, confirmed booking or opening
subscription together with its slot, metadata, action tokens and outstanding
jobs. Each encrypted record has a random 128-bit ID and its own non-autoloaded
WordPress option. A confirmed V2 reservation keeps its ID. Rebooking a
cancelled slot creates a fresh ID. The option name binds the kind and ID to
the cipher's authenticated data; each site's random context binds the location.

| Field | Purpose |
| --- | --- |
| `state_version=2` | Format of `rrze_appointment_storage_state_v2`. Its MAC covers the site ID, random context, key ID, `mode=mixed` and `write_data_version=2`. |
| `data_version=2` | Format of the record or index inside the authenticated envelope. An ordinary business edit preserves its schema version. |
| `envelope.version=1` | Existing XChaCha20-Poly1305 encoding, with a fresh nonce for each write. |

The encrypted `rrze_appointment_record_index_v2` authenticates record membership,
ciphertext digests, slot/token lookups and keyed fingerprints of retired fields.
Exact replays of removed source fields are hidden; unexpected conflicting data
stops booking operations for review. The directory and all indexed records must
agree. Missing policies, keys or rows, unknown versions and invalid
authentication tags cause errors. The earlier V1 marker remains authoritative
for the original five options, including its individual completion checkpoints.

The first V2 write creates the policy and index with its record. Mutations
hold a named site lock across the availability check. On the same native mysqli
connection an InnoDB transaction compares the observed source rows, writes the
record and index, and removes only the adopted fields from their source options.
An unconverted V1 source keeps its prior format when other entries remain.
An interrupted transaction can be retried. After a lost commit acknowledgement,
a fresh request reads the committed state. Confirmation mail follows the
commit. An SMTP acceptance followed by a process crash can cause duplicate delivery.

## Scheduled work

Expiration, reminder and opening jobs include their due times and delivery
state inside the encrypted record. WordPress runs `rrze_appointment_run_jobs`
with no arguments when work is due. The next plugin/cron start repairs a lost
wake from committed work. Timely delivery requires a working cron runner for
the site. Legacy jobs retain their original due times and may contain plaintext
arguments until they run or are removed. Their callbacks resolve the current
owner and honor its job state. The former network migration event is retired.

## Staging rollout and verification

1. Preserve a consistent database backup and a separate copy of the configured
   key. The staging candidate requires PHP Sodium, native mysqli and InnoDB
   for atomic updates. Test against the intended WordPress hosting configuration.
2. Verify the update boundary on all servers which can run Appointment. Old
   2.3.3 web, background and CLI processes must finish before the first new
   encrypted booking write. An old booking request can already have passed its
   availability check and still write into its original option afterwards.
   Draining old requests protects that first transition. This condition does
   not require pausing unrelated database writes across the network.
3. Optionally exclude the four old Appointment hooks in the actual WP-CLI cron
   launcher during the switch. Resume the normal launcher afterwards. Exclusion
   only affects that launcher; visitor-triggered cron and other runners have
   their own execution paths.
4. On the target site, inspect **Appointments** or **Settings → RRZE Appointment**.
   Only superadministrators receive these diagnostics on multisite. The indicated
   policy applies to new and changed data. It does not certify that every
   historical record is encrypted.

Run optional read-only checks with the exact URL and the installed plugin path:

```sh
wp --url=https://SITE.example --skip-plugins --skip-themes eval-file /PATH/rrze-appointment/bin/storage-maintenance.php preflight
wp --url=https://SITE.example --skip-plugins --skip-themes eval-file /PATH/rrze-appointment/bin/storage-maintenance.php verify-mixed
wp --url=https://SITE.example --skip-plugins --skip-themes eval-file /PATH/rrze-appointment/bin/storage-maintenance.php verify
```

Append `network` for an explicit, bounded inventory of the current network.
`verify-mixed` requires the configured key and InnoDB and validates both the mixed storage format and known job structures.
`verify` requires complete encryption, including the stricter legacy cron checks.
`migrate` exits with an explanation before writing. Ordinary requests never
schedule a network-wide conversion. Operations on an unconfigured site with
no protected data continue in legacy mode.

Restore the site database, including its original site ID, policy, index and
records, with the matching master key. A rollback to a complete older snapshot
can discard later bookings. V2 data requires a compatible plugin reader.
Backups made before the update can still contain plaintext. PHP code with
access to the key can decrypt stored bookings.

## Auxiliary stores and error boundaries

Encryption of booking options does not protect every possible copy. The plugin
uses the following controls outside those five stores, including when encryption
is not configured where applicable:

| Location | Content and protection |
| --- | --- |
| WordPress cron | V2 uses an argument-free site wake. Older jobs can contain historical tokens, slots or V1 HMAC references. Execution timestamps and counts remain operational metadata. |
| Slot lock options | Site- and purpose-bound HMAC names (`v2_…`) and creation timestamps. Encrypted sites derive the HMAC key from their persistent context; legacy sites use the WordPress auth salt and site ID. |
| Guest rate limits | Existing keyed hashes of email/IP identity, counters and expiry only; no plaintext address or IP. Daily cleanup removes expired counters. |
| Object cache | Decrypted booking arrays stay request-local. Replacements invalidate individual options, `alloptions` and `notoptions`, including after a failed verification. |
| Calendar attachments | Calendar bytes are passed to PHPMailer as string attachments. Confirmation creates no temporary `.ics` file, including on mail failure. |
| Mail preparation | Plugin HTML/attachment globals and hooks are cleared in `finally`. False transport results and unexpected failures produce safe exceptions. |
| Personal HTTP responses | Token pages and booking AJAX receive private/no-store headers early, plus `no-referrer` and `noindex`. REST booker/booking responses use the same policy after dispatch, including permission and storage errors. Ordinary public content keeps its normal cache behavior. |
| Network diagnostics | Counts, site IDs, timestamps and generic error codes only; no exception dumps or booking payloads. |

Daily background maintenance removes expired historical slot-lock options in bounded batches.
This includes the older MD5 lock names and the newer purpose-bound lock names.

Unexpected dependency errors are converted into a fixed message. Safe domain
messages and status codes survive, but original exception objects and chains do
not. Even deliberate domain exceptions are rebuilt at normalization boundaries
to discard deeper dependency traces. Payload-bearing plugin parameters use PHP
`SensitiveParameter`. Public handlers catch unexpected `Throwable` failures and
return safe errors; they do not report successful booking or expose raw details.
These mechanisms do not create database rollback or exactly-once email delivery.
A failed confirmation email can leave a correctly persisted booking in place.

Operational limits remain explicit:

- Hosting access logs may record token URLs. Configure query-string redaction or
  exclusions for appointment action links; response headers cannot erase an
  incoming URL from a server log or browser history.
- Page-cache/CDN rules must honor `no-store` and exclude token pages, personal
  AJAX and booking REST responses. A cache serving content before PHP executes
  cannot be controlled by this plugin. `DONOTCACHEPAGE` is set for token/AJAX
  requests as an additional hint to cooperating cache plugins.
- Configure `zend.exception_ignore_args=On` in production. The plugin redacts its
  arguments and does not log raw exceptions, but cannot annotate WordPress,
  SMTP, debugger or other plugins' entire call stacks.
- Mail delivery intentionally receives plaintext. Mail-logging, SMTP and queue
  plugins may store bodies, answers or attachments independently; review their
  storage and retention settings. Disk-based asynchronous mail integrations must
  explicitly support string attachments; verify the complete delivery path.
- Old temporary calendar files, backups, earlier logs and cache snapshots are
  not retroactively secured by this update. Existing copies require operational
  cleanup under their original retention/backup rules.
- The browser's SSO-resume state stores only the selected public slot and return
  page in per-tab session storage, not names, addresses or question answers.

## Configuration contract

`EncryptionKey::fromConfiguration()` reads the string constant
`RRZE_APPOINTMENT_ENCRYPTION_KEY`. It must contain canonical Base64 for **32 or
64 cryptographically random bytes**. ASCII spaces, tabs, CR, and LF are removed
before strict decoding, so wrapped output from `openssl rand -base64 64` works.
Missing configuration, incorrect types, invalid encoding, and other decoded
lengths throw `AppointmentException`. Passwords and WordPress authentication
salts are not substitutes for this key; length validation cannot prove entropy.

Master key objects reject serialization and redact ordinary debug output.
Never log keys or decrypted payloads, even in development. Back up the master
key separately from the database and preserve it when restoring or moving a
site. Key identifiers prepare the format for rotation; this stage does not
implement a keyring or rotation workflow. Replacing the configured key prevents
decryption of values created with the previous key.

## Version-one wire format

The stored value is a string: `rrze-appointment:encrypted:` followed by a JSON
object with exactly four fields:

| Field | Representation |
| --- | --- |
| `version` | Integer `1` |
| `key_id` | 32 lowercase hexadecimal characters |
| `nonce` | Canonical Base64 encoding of 24 fresh random bytes |
| `ciphertext` | Canonical Base64 encoding of ciphertext including the authentication tag |

The cipher is PHP Sodium's XChaCha20-Poly1305-IETF. Every encryption uses a new
nonce from `random_bytes()`, including repeated writes of the same payload.

Both derivations use HKDF-SHA-256 with the decoded master key as input and the
**ASCII site-context string** as the salt:

- Encryption key: 32 output bytes, info `rrze-appointment:storage:v1`.
- Key identifier: 16 output bytes, info `rrze-appointment:key-id:v1`, then hex encoding.

Additional authenticated data is the compact JSON array
`["rrze-appointment",1,key_id,site_context,option_name]`, encoded with PHP
`json_encode()` defaults plus `JSON_THROW_ON_ERROR`. The expected context and
option name are supplied by the caller. This authenticates version, key
identity, site, and storage area, preventing cross-context substitution.
It does not detect replay of an older valid ciphertext in the same context.

Payloads are arrays of arrays and JSON-compatible scalar/null values. JSON
encoding uses `JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION` with depth 64;
decoding returns associative arrays. Objects, resources, recursive/excessively
nested arrays, non-finite floats, and invalid UTF-8 are rejected. No PHP object
unserialization is used. Sparse array keys and scalar types are retained.
