# Booking encryption foundation

This is the first implementation stage. `EncryptionKey` and `BookingCipher`
provide key validation and a versioned ciphertext format. They perform no
database operations and are not yet called by the booking workflows. Setting
`RRZE_APPOINTMENT_ENCRYPTION_KEY` alone therefore does **not** encrypt existing
or new bookings. Storage integration and migration are the next stage.

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

The caller must supply a persistent site context: a lowercase 32-character hex
encoding of 16 random bytes. The migration/storage integration will create and
persist it once per site. It is not secret and must survive domain changes and
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

Before enabling ciphertext writes, the next implementation stage must:

1. Route all five stores' reads and writes through one storage layer, including
   startup cleanup, frontend availability, cancellation, and notification jobs.
2. Add guarded, resumable legacy migration and concurrent-write protection.
   Legacy plaintext acceptance must be explicit and limited to migration.
3. Keep missing/wrong keys and authentication failures from triggering empty
   defaults, destructive cleanup, or a fallback to plaintext writes.
4. Remove booking details/action tokens from cron arguments and persistent
   caches; preserve existing guest links and scheduled workflows.
5. Provide per-site status and a documented backup/restore procedure. Old plugin
   versions cannot read this format; copying old code back is not a rollback.

Tests use synthetic keys and real PHP/Sodium without loading `wp-config.php`.
A frozen vector generated independently of the codec guards compatibility with
the version-one derivation and envelope contract.
