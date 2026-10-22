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
     * Retains the legacy cron argument in tests that do not enable encryption.
     */
    public static function jobReference(string $hook, string $value): string
    {
        return $value;
    }

    /**
     * Mirrors the production request boundary; this fixture has no database lock.
     */
    public static function release(): void
    {
    }
}

require_once dirname(__DIR__, 2) . '/includes/Storage/BookingScheduler.php';
