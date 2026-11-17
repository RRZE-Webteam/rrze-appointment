<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Validates the configured master key and derives purpose-specific storage keys.
 *
 * No keys are generated, persisted, or derived from WordPress authentication
 * salts. The master key must be backed up separately from the database.
 */
final class EncryptionKey
{
    public const CONFIGURATION_CONSTANT = 'RRZE_APPOINTMENT_ENCRYPTION_KEY';

    /**
     * Keeps validated binary key material in request-local memory.
     *
     * @param string $material Cryptographically random master key bytes.
     */
    private function __construct(#[\SensitiveParameter] private readonly string $material)
    {
    }

    /**
     * Reads the master key from server configuration without exposing its value.
     *
     * @return self Validated master key.
     * @throws AppointmentException When the constant is missing or invalid.
     */
    public static function fromConfiguration(): self
    {
        if (!defined(self::CONFIGURATION_CONSTANT)) {
            throw new AppointmentException('The appointment encryption key is not configured.');
        }

        $encoded = constant(self::CONFIGURATION_CONSTANT);
        if (!is_string($encoded)) {
            throw new AppointmentException('The appointment encryption key must be a Base64 string.');
        }

        return self::fromBase64($encoded);
    }

    /**
     * Accepts canonical Base64 representing 32 or 64 random bytes.
     *
     * ASCII spaces, tabs, and line breaks are ignored to support wrapped output
     * from `openssl rand -base64 64`. Validation cannot establish randomness;
     * operators must generate the input with a cryptographic random generator.
     *
     * @param string $encoded Base64-encoded master key, never a password.
     * @return self Validated master key.
     * @throws AppointmentException When encoding or decoded length is invalid.
     */
    public static function fromBase64(#[\SensitiveParameter] string $encoded): self
    {
        $normalized = str_replace(["\r", "\n", "\t", ' '], '', $encoded);
        $material = base64_decode($normalized, true);
        if (
            $material === false
            || !in_array(strlen($material), [32, 64], true)
            || base64_encode($material) !== $normalized
        ) {
            throw new AppointmentException(
                'The appointment encryption key must encode 32 or 64 random bytes as Base64.'
            );
        }

        return new self($material);
    }

    /**
     * Derives the 256-bit key used by the version-one storage cipher.
     *
     * @param string $siteContext Stable site identifier retained across URL changes and restores.
     * @return string Raw binary encryption key; never persist or log this value.
     */
    public function forStorage(string $siteContext): string
    {
        return hash_hkdf('sha256', $this->material, 32, 'rrze-appointment:storage:v1', $siteContext);
    }

    /**
     * Derives an independent key for opaque references in the WordPress cron queue.
     *
     * @param string $siteContext Persistent site's storage context.
     * @return string Binary HMAC key; never persist or log it.
     */
    public function forJobs(string $siteContext): string
    {
        return hash_hkdf('sha256', $this->material, 32, 'rrze-appointment:jobs:v1', $siteContext);
    }

    /**
     * Derives an independent authentication key for migration progress markers.
     *
     * @param string $siteContext Persistent site's storage context.
     * @return string Binary HMAC key; never persist or log it.
     */
    public function forState(string $siteContext): string
    {
        return hash_hkdf('sha256', $this->material, 32, 'rrze-appointment:state:v1', $siteContext);
    }

    /**
     * Derives a public key identifier independently from the encryption key.
     *
     * @param string $siteContext Stable site identifier used by the storage cipher.
     * @return string Hexadecimal identifier for format validation and future key rotation.
     */
    public function identifier(string $siteContext): string
    {
        return bin2hex(hash_hkdf('sha256', $this->material, 16, 'rrze-appointment:key-id:v1', $siteContext));
    }

    /**
     * Redacts master key material from ordinary object debug output.
     *
     * @return array{configured: bool} Non-secret diagnostic information.
     */
    public function __debugInfo(): array
    {
        return ['configured' => true];
    }

    /**
     * Prevents accidental persistence of master key material in serialized objects.
     *
     * @return array<never, never> Never returned.
     * @throws AppointmentException Always; keys must remain in server configuration.
     */
    public function __serialize(): array
    {
        throw new AppointmentException('Appointment encryption keys cannot be serialized.');
    }

    /**
     * Rejects reconstruction of a key object from untrusted serialized data.
     *
     * @param array<mixed> $data Serialized object properties, which are not used.
     * @throws AppointmentException Always; only validated configuration is accepted.
     */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new AppointmentException('Appointment encryption keys cannot be unserialized.');
    }
}
