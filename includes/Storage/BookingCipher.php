<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Encodes booking option arrays as authenticated, versioned ciphertext strings.
 *
 * This codec performs no database operations. Migration must supply a stable
 * site context and switch all readers before encrypted values are persisted.
 * The format protects confidentiality and detects modification or substitution
 * across storage areas/sites; it does not detect replay of an older valid value.
 */
final class BookingCipher
{
    public const PREFIX = 'rrze-appointment:encrypted:';
    private const VERSION = 1;
    private const MAX_PAYLOAD_DEPTH = 64;
    private const STORAGE_NAMES = [
        'rrze_appointment_booked_slots',
        'rrze_appointment_booked_slots_meta',
        'rrze_appointment_pending_slots',
        'rrze_appointment_cancel_tokens',
        'rrze_appointment_booking_opening_notifications',
    ];

    /**
     * Binds encryption to one site's persistent, non-secret random identifier.
     *
     * @param EncryptionKey $key Validated master key from server configuration.
     * @param string $siteContext Lowercase hex encoding of a persistent 16-byte site identifier.
     * @throws AppointmentException When Sodium is unavailable or the context is invalid.
     */
    public function __construct(
        #[\SensitiveParameter] private readonly EncryptionKey $key,
        private readonly string $siteContext
    ) {
        if (
            !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
        ) {
            throw new AppointmentException('Appointment encryption requires the PHP Sodium extension.');
        }
        if (!preg_match('/\A[0-9a-f]{32}\z/', $siteContext)) {
            throw new AppointmentException('The appointment encryption site context is invalid.');
        }
    }

    /**
     * Encrypts an entire booking storage area with a fresh 192-bit nonce.
     *
     * @param array<mixed> $data Booking values containing only arrays and JSON-compatible scalars.
     * @param string $storageName One of the five protected booking option names.
     * @return string Versioned ciphertext suitable for storage as an option value.
     * @throws AppointmentException When the scope, data, or encryption operation is invalid.
     */
    public function encrypt(#[\SensitiveParameter] array $data, string $storageName): string
    {
        $this->assertStorageName($storageName);
        self::assertPayload($data);

        try {
            $plaintext = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, self::MAX_PAYLOAD_DEPTH);
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $keyId = $this->key->identifier($this->siteContext);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $this->additionalData($storageName, $keyId),
                $nonce,
                $this->key->forStorage($this->siteContext)
            );

            return self::PREFIX . json_encode([
                'version' => self::VERSION,
                'key_id' => $keyId,
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException | \SodiumException | \Random\RandomException) {
            // Do not chain exceptions whose arguments could contain plaintext or keys.
            throw new AppointmentException('Appointment data could not be encrypted.');
        }
    }

    /**
     * Authenticates and decrypts a value without a fallback to empty or legacy data.
     *
     * @param string $stored Versioned ciphertext read from the database.
     * @param string $storageName Expected option name, supplied by the caller rather than the envelope.
     * @return array<mixed> Original booking values, including sparse array keys.
     * @throws AppointmentException On wrong keys, tampering, unsupported formats, or invalid payloads.
     */
    public function decrypt(string $stored, string $storageName): array
    {
        $this->assertStorageName($storageName);

        try {
            $envelope = $this->readEnvelope($stored);
            $nonce = self::decodeBase64($envelope['nonce']);
            $ciphertext = self::decodeBase64($envelope['ciphertext']);
            if (
                strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
                || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
                || !hash_equals($this->key->identifier($this->siteContext), $envelope['key_id'])
            ) {
                throw new AppointmentException('Stored appointment data could not be authenticated.');
            }

            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $this->additionalData($storageName, $envelope['key_id']),
                $nonce,
                $this->key->forStorage($this->siteContext)
            );
            if ($plaintext === false) {
                throw new AppointmentException('Stored appointment data could not be authenticated.');
            }

            $data = json_decode($plaintext, true, self::MAX_PAYLOAD_DEPTH, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new AppointmentException('Decrypted appointment data has an invalid format.');
            }
            self::assertPayload($data);
            return $data;
        } catch (\JsonException | \SodiumException) {
            throw new AppointmentException('Stored appointment data could not be authenticated.');
        }
    }

    /**
     * Reads the exact version-one envelope schema before invoking cryptography.
     *
     * @param string $stored Raw database value; plaintext and unknown formats are rejected.
     * @return array{version: int, key_id: string, nonce: string, ciphertext: string} Validated envelope.
     * @throws AppointmentException When the envelope does not match the supported schema.
     * @throws \JsonException When the envelope is not valid JSON.
     */
    private function readEnvelope(string $stored): array
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new AppointmentException('Stored appointment data has an unsupported encryption format.');
        }

        $envelope = json_decode(substr($stored, strlen(self::PREFIX)), true, 8, JSON_THROW_ON_ERROR);
        if (
            !is_array($envelope)
            || count($envelope) !== 4
            || ($envelope['version'] ?? null) !== self::VERSION
            || !is_string($envelope['key_id'] ?? null)
            || !preg_match('/\A[0-9a-f]{32}\z/', $envelope['key_id'])
            || !is_string($envelope['nonce'] ?? null)
            || !is_string($envelope['ciphertext'] ?? null)
        ) {
            throw new AppointmentException('Stored appointment data has an unsupported encryption format.');
        }

        return $envelope;
    }

    /**
     * Binds the ciphertext to its format, key, site, and intended storage area.
     *
     * @param string $storageName Validated booking option name.
     * @param string $keyId Public identifier of the expected encryption key.
     * @return string Unambiguous authenticated context; contains no personal data.
     * @throws \JsonException When the authenticated context cannot be encoded.
     */
    private function additionalData(string $storageName, string $keyId): string
    {
        return json_encode([
            'rrze-appointment', self::VERSION, $keyId, $this->siteContext, $storageName,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Restricts this format to booking data rather than arbitrary WordPress options.
     *
     * @param string $storageName Requested database option name.
     * @throws AppointmentException When the option is outside the protected scope.
     */
    private function assertStorageName(string $storageName): void
    {
        if (!in_array($storageName, self::STORAGE_NAMES, true)) {
            throw new AppointmentException('The appointment encryption storage area is invalid.');
        }
    }

    /**
     * Decodes only the canonical Base64 emitted by the version-one writer.
     *
     * @param string $encoded Encoded nonce or authenticated ciphertext.
     * @return string Decoded binary value.
     * @throws AppointmentException When the encoded value is malformed.
     */
    private static function decodeBase64(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            throw new AppointmentException('Stored appointment data could not be authenticated.');
        }
        return $decoded;
    }

    /**
     * Rejects objects, resources, and excessive nesting instead of coercing values.
     *
     * @param mixed $value Booking data; arrays may contain only arrays and scalars/null.
     * @param int $depth Current nesting depth; bounded to reject recursive arrays.
     * @throws AppointmentException When a value cannot be represented by this storage format.
     */
    private static function assertPayload(#[\SensitiveParameter] mixed $value, int $depth = 0): void
    {
        if ($depth >= self::MAX_PAYLOAD_DEPTH || (!is_array($value) && !is_scalar($value) && $value !== null)) {
            throw new AppointmentException('Appointment data has an unsupported storage value.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertPayload($item, $depth + 1);
            }
        }
    }
}
