# Booking storage and migration

All booking workflows use `BookingStorage::get()` / `set()`. This includes
availability, pending bookings, confirmations, cancellation, administrative
lists, retention, waiting lists, reminders and opening notifications. Never use
`get_option()` or `update_option()` directly for the five protected options.

Configuring a valid `RRZE_APPOINTMENT_ENCRYPTION_KEY` starts migration
**automatically on the next request**. Customers do not need to open a settings
page or press a button. `Main` initializes storage before maintenance, and the
storage layer also initializes itself before direct booking access, including
after a multisite switch. Completed migration does not trigger repeated writes.
**Settings → RRZE Appointment** shows diagnostics to site administrators; it has
no migration action. The server-side key configuration authorizes automatic
migration. Public booking remains available to guests and does not require SSO.
Optional RRZE-AC protection for management is configured separately in the general
settings. It additionally requires the managing WordPress user's matching SSO
identity; public bookings and storage maintenance do not use that requirement.
See the main README for setup, dependencies and network-admin emergency recovery.

## Rollout and recovery

1. Back up the database and preserve the configured key separately **before
   deploying this version or enabling the key**. If the key already exists,
   updating the plugin enables migration immediately on the next request. Do not
   paste the key into tickets, logs, source control or a database option.
2. Drain web requests and cron workers during deployment, update **all** workers,
   then admit requests again. Old code does not understand ciphertext or honor
   the new storage lock. Removing an active encryption key is not a migration
   pause mechanism and will block access to an already migrated site.
3. The next request initializes the current site. The plugin preflights all five
   arrays, records an authenticated migration marker, then replaces and verifies
   each store. Existing action tokens and URLs are preserved byte-for-byte.
   Multisite also schedules a bounded network worker so unvisited sites are
   reached without requiring customer actions; see the network operation below.
4. Check the encrypted status. All five stores, including previously missing
   empty stores, must be encrypted, and old cron arguments must be converted.
   Settings, public appointment blocks and mail templates remain editable.
5. If interrupted, the next request resumes from the saved progress. Correct any
   storage or configuration errors first. Do **not** delete migration state,
   regenerate the key or replace a failed store with `[]`. Ordinary booking
   workflows only continue after initialization succeeds; there is no plaintext
   write fallback after an unsuccessful automatic migration.

Diagnostics distinguish legacy mode (no key), ready (not yet initialized),
migrating, encrypted and errors. Status inspection itself is read-only; normal
plugin initialization starts or resumes migration before rendering the UI.
Malformed configured keys cause
errors even before migration; they never silently select legacy mode. Missing
or wrong keys, invalid authentication tags, malformed legacy arrays, database
failures and missing encrypted rows block booking access. Administrator recovery
pages and unrelated WordPress routes remain available; cleanup is not allowed
to treat these errors as empty data.

## Networks with thousands of sites

`NetworkMigration` automatically schedules the recurring
`rrze_appointment_migrate_network` event once a key is configured. There is one
coordinator per network, preferably the main site. With individual plugin
activation, a site where the plugin is active is chosen instead. A later request
can replace a deleted, archived, spammed or deactivated coordinator; event health
checks run at most every five minutes. Separate networks have separate locks,
site queries and progress.

Each event processes at most **10 sites**, with a **10-second soft time budget**
checked between sites. It reads only the next bounded set of IDs from `blogs`
using a primary-key cursor, not all sites or ever-growing SQL offsets. A pass
captures its upper ID once; newly created sites are reached in a subsequent pass
or immediately through their ordinary booking access. Network activation covers
all sites. With individual activation, inactive sites with existing booking
stores are also protected; empty inactive sites are skipped. Archived/spam sites
are included when their tables still exist; missing tables are reported as
failures, not as empty data.

Only one network worker can run at a time, using a non-waiting advisory lock.
For each site it switches the WordPress table/cache context, initializes the
existing storage layer, releases the site lock and decrypted snapshots, then
restores the original context. At most one booking lock and the network lock are
held by this worker. There is one shutdown cleanup callback, whose registry
retains only live locks, rather than retaining an object for every visited site.
Other long-running integrations must still explicitly release storage at their
own work-unit boundaries; arbitrary nested `switch_to_blog()` calls do not imply
that a booking operation is finished.

Progress is saved **before and after each site** in the network option
`rrze_appointment_network_migration`. A normal failure advances the cursor and
increments the error count. An interrupted in-flight site is counted as failed
on the next run so a repeatedly fatal site cannot block all later sites. The
site's own authenticated checkpoints allow its migration to resume safely.
Passes with errors are retried after one hour; successful passes are rechecked
daily to discover new or restored data. Already completed stores are validated
without re-encryption. The network option contains only counters, site IDs,
timestamps and generic error codes; it is **not** an authenticated security
marker and cannot authorize plaintext access or replace per-site verification.

Network administration shows the current/last pass counts, coordinator ID and
last failing site ID. An active pass without worker progress for 15 minutes
shows an attention notice; scheduling health checks do not reset that timer.
Visit a failing site's administration for its storage diagnosis. A pass that
has ended with failures is not a successful migration of the whole network.

**Reliable cron execution is required on the coordinator site**, including when
`DISABLE_WP_CRON` is enabled. Use the platform's existing server cron integration;
for example, regularly run the following with the coordinator's actual URL:

```sh
wp --url=https://COORDINATOR.example cron event run --due-now
```

Do not use `--skip-plugins=rrze-appointment` for that worker. A visitor-triggered
WP-Cron alone cannot guarantee completion on quiet networks. At one event per
minute, 1,000 sites require at least 100 batches, potentially more for slow or
failed sites. This limits network breadth; a **single site's existing option
arrays must still fit in PHP memory and finish within the worker's execution
limit**. Large per-site datasets require separate load testing or a later
record-based storage design, not merely a larger site batch.

Restore the complete database, its `rrze_appointment_storage_state` marker and
the matching master key together. The marker contains the persistent random
site context, so moving the site to another URL does not change its keys. Test a
restore in isolation. Reverting only the plugin code is **not** a rollback:
older versions cannot read ciphertext. Restoring a pre-migration database also
discards subsequent booking changes; arrange an explicit recovery procedure.

Existing backups, database logs, mail archives and cache snapshots made before
migration may still contain plaintext. Migration changes current storage; it
does not erase historical copies. PHP code with access to the configured key
can decrypt bookings. This protects database-only disclosure, not a compromised
WordPress runtime. Access controls and operational security remain necessary.

## Auxiliary stores and error boundaries

Encryption of booking options does not protect every possible copy. The plugin
uses the following controls outside those five stores, including when encryption
is not configured where applicable:

| Location | Content and protection |
| --- | --- |
| WordPress cron | Purpose-bound HMAC references once migration starts; no new token/slot plaintext on retries. Execution timestamps and counts remain operational metadata. |
| Slot lock options | Site- and purpose-bound HMAC names (`v2_…`) and creation timestamps. Encrypted sites derive the HMAC key from their persistent context; legacy sites use the WordPress auth salt and site ID. |
| Guest rate limits | Existing keyed hashes of email/IP identity, counters and expiry only; no plaintext address or IP. Daily cleanup removes expired counters. |
| Object cache | Decrypted booking arrays stay request-local. Replacements invalidate individual options, `alloptions` and `notoptions`, including after a failed verification. |
| Calendar attachments | Calendar bytes are passed to PHPMailer as string attachments. Confirmation creates no temporary `.ics` file, including on mail failure. |
| Mail preparation | Plugin HTML/attachment globals and hooks are cleared in `finally`. False transport results and unexpected failures produce safe exceptions. |
| Personal HTTP responses | Token pages and booking AJAX receive private/no-store headers early, plus `no-referrer` and `noindex`. REST booker/booking responses use the same policy after dispatch, including permission and storage errors. Ordinary public content keeps its normal cache behavior. |
| Network diagnostics | Counts, site IDs, timestamps and generic error codes only; no exception dumps or booking payloads. |

Startup removes at most 100 expired locks per site, restricted to the exact two
known slot-lock prefixes and their old/current formats. It does not delete live
locks or unrelated options. This also runs for already encrypted sites through
normal requests and the network worker, so the earlier MD5 names are removed
without resetting encryption state. Both lock types expire after five minutes;
physical removal depends on a later initialization of that site. No encrypted
booking payload needs to be rewritten for this cleanup.

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

## Concurrency and cache behavior

`OptionDatabase` reads raw SQL values and does not put decrypted arrays in the
WordPress object cache. Writes turn off autoload and invalidate the individual
option, `alloptions` and `notoptions`. Plaintext snapshots are request-local.
Public availability sent to the frontend is intentionally still public.

Every access takes a MySQL/MariaDB named lock scoped to database and site table.
It waits at most five seconds and otherwise fails with a retryable storage error.
The lock spans a request's read/modify/write operations and is released at PHP
shutdown (or explicitly with `BookingStorage::release()`). Startup maintenance
releases it before registering the request handlers. Long-running CLI workers
must release it at their work-unit boundaries. See the [MySQL lock semantics](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html).

This deliberately serializes booking operations, including reads and mail work
in a booking request. Slow mail delivery can therefore cause other requests to
time out. It is a conservative solution for the existing whole-array schema,
not a high-throughput queue. It requires one primary MySQL/MariaDB connection
with working `GET_LOCK`, `IS_USED_LOCK`, `RELEASE_LOCK` and `SHA2`; connection
multiplexing, read-replica routing and multi-primary clusters need separate
validation. Lock ownership is checked to detect reconnects/lost connections.

Conditional SQL updates additionally compare a SHA-256 digest of the observed
raw value, so the old plaintext is not copied into the update predicate. A
conflict stops the workflow instead of overwriting a newer value. The lock is
**not a transaction**: existing multi-option domain operations and external mail
delivery do not gain rollback or exactly-once delivery. Migration is independently
resumable because every option replacement is verified and checkpointed.

## Migration marker and scheduled jobs

`rrze_appointment_storage_state` is a non-autoloaded JSON object containing
`version`, `phase`, `context`, `key_id`, `completed`, and `mac`. Its MAC is hex
HMAC-SHA-256 over the JSON array `[version,phase,context,key_id,completed]`.
The authentication key is HKDF-SHA-256, 32 bytes, info
`rrze-appointment:state:v1`, with the ASCII context as salt. Changing progress
cannot silently re-enable plaintext acceptance. Before a store is checkpointed,
both valid legacy arrays and valid ciphertext are accepted, which handles a
crash between the data write and its checkpoint. Checkpointed stores require
ciphertext, including encrypted empty arrays. Old valid data/state replays are
not detected; this is not a database rollback-protection system.

Cron arguments use `rrze-appt-job:v1:` plus hex HMAC-SHA-256 of
`hook + NUL + original_argument`. Their independent HKDF key uses info
`rrze-appointment:jobs:v1` and the same context salt. Callbacks resolve these
references using the encrypted stores. Times and random action tokens no longer
appear directly in those arguments. The scheduler's execution timestamps and
job counts remain visible as operational metadata.

Migration converts existing cron event arguments and event keys while retaining
timestamps and unrelated events. Conditional writes retry concurrent cron
changes three times. Unsupported serialized objects or malformed plugin events
stop migration rather than damaging another plugin's queue. Pending expiry,
opening notification and reminder callbacks understand references and already
dispatched legacy arguments. Opaque jobs are deferred on storage errors without
requiring the unavailable key; ordinary cleanup repairs missing opening jobs,
expires pending requests, and the daily reminder scan provides a fallback.

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

## Site context and scope

The storage layer supplies a persistent site context: a lowercase 32-character
hex encoding of 16 random bytes, generated once when migration starts. It is
not secret and must survive domain changes and
database restores. Do not derive it from the current URL or regenerate it on
each request. A separate site's storage gets a separate context. This binding
does not isolate plugins or administrators sharing the same PHP runtime and
master key.

The codec accepts these option names as the expected storage scope:

- `rrze_appointment_booked_slots`
- `rrze_appointment_booked_slots_meta`
- `rrze_appointment_pending_slots`
- `rrze_appointment_cancel_tokens`
- `rrze_appointment_booking_opening_notifications`

Pass the expected name from trusted application code, not from the encrypted
envelope. Settings, public blocks, and mail templates are outside this scope.

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

## Failure and integration requirements

Decrypting malformed, unsupported, modified, or wrong-context ciphertext throws
`AppointmentException`; it never returns an empty array as a fallback. A valid
encrypted empty array is distinguishable from failure. Exceptions do not chain
cryptographic/JSON errors containing input arguments. Sensitive PHP parameters
are annotated to reduce accidental disclosure through stack traces.

The automated storage tests use synthetic keys, real PHP/Sodium and conditional
SQL against isolated SQLite tables without loading `wp-config.php`. They cover
legacy compatibility, automatic startup and recovery, no repeated writes after
completion, interruption before/after checkpointing, concurrent writes, key
failures, corruption, missing rows, marker tampering, multisite, cron and old
action links. A frozen independent codec vector guards format compatibility.
The network fixture covers 1,005 synthetic sites, isolated corruption and repair,
crash recovery, bounded site counts/time, released snapshots/locks, coordinator
replacement, dormant installations, network isolation and checkpoint/query
failures. This is a functional scale regression test, not a production load test
of 1,005 real sites or their largest booking arrays.

An optional native database smoke test uses only synthetic data in a temporary
table and two connections; it never reads booking data or the encryption key:

```sh
wp --skip-plugins --skip-themes eval-file wp-content/plugins/rrze-appointment/tests/fixtures/native-option-database.php
```
