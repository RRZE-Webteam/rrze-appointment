<?php

namespace RRZE\Appointment\Storage;

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * In-memory storage boundary for existing isolated workflow unit tests.
 *
 * Database locking, encryption and migration use the real classes in the separate
 * booking-storage integration fixture. These tests exercise domain workflows.
 */
final class BookingStorage
{
    /**
     * Records bootstrap ordering and supports an unavailable-storage fixture.
     */
    public static function initialize(): void
    {
        $GLOBALS['storageInitOrder'][] = 'storage';
        if ($GLOBALS['storageInitFails'] ?? false) {
            throw new \RRZE\Appointment\AppointmentException('Storage unavailable.');
        }
    }

    /**
     * Reads the workflow fixture's array storage, rejecting corrupt values.
     *
     * @return array<mixed> Fixture booking data.
     */
    public static function get(string $name): array
    {
        $data = get_option($name, []);
        if (!is_array($data)) {
            throw new \RRZE\Appointment\AppointmentException('Legacy appointment data is malformed. Migration was stopped.');
        }
        return $data;
    }

    /**
     * Writes updated domain data to the existing workflow fixture.
     *
     * @param array<mixed> $data Booking data.
     */
    public static function set(string $name, array $data): void
    {
        update_option($name, $data, false);
    }

    /**
     * Applies a single domain operation to the in-memory fixture.
     *
     * @param array<string, array<mixed>> $updates Complete logical booking arrays.
     */
    public static function setMany(array $updates): void
    {
        foreach ($updates as $name => $data) {
            self::set($name, $data);
        }
    }

    /**
     * Retains the legacy cron argument in tests that do not enable encryption.
     */
    public static function jobReference(string $hook, string $value): string
    {
        return $value;
    }

    /** Legacy fixtures have no persistent encrypted record identities. */
    public static function recordId(string $type, string $target): ?string
    {
        return null;
    }

    /** The unconfigured fixture keeps new events in its legacy WordPress queue. */
    public static function promoteForJob(string $type, string $target, int $timestamp): ?string
    {
        return null;
    }

    /** The legacy workflow fixtures have no V2 wake to repair at startup. */
    public static function hasMixedState(): bool
    {
        return false;
    }

    /** Supplies the fixture's per-site inventory result, including the empty-site case. */
    public static function hasStoredData(): bool
    {
        return $GLOBALS['fixtureHasBookingData'] ?? true;
    }

    /** Supplies opaque lock identifiers for isolated workflows without real keys. */
    public static function lockReference(string $purpose, string $slot): string
    {
        return 'v2_' . hash_hmac('sha256', $purpose . "\0" . $slot, 'synthetic-test-key');
    }

    /**
     * Mirrors the production request boundary; this fixture has no database lock.
     */
    public static function release(): void
    {
    }
}

require_once dirname(__DIR__, 2) . '/includes/Storage/BookingScheduler.php';
