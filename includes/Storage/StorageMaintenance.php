<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/** Inspects selected sites in bounded batches using the current site’s storage lock. */
final class StorageMaintenance
{
    /**
     * Verifies the requested storage contract without starting migration or dispatching jobs.
     *
     * @param string $mode One of preflight, verify-mixed or strict verify.
     * @param bool $network Include the current network's active/dormant installations.
     * @param callable(array): void $report Receives safe per-site results and phase totals.
     * @return bool True only if every required pass completed without failures.
     * @throws AppointmentException On invalid configuration, inventory or lock errors.
     */
    public static function run(string $mode, bool $network, callable $report): bool
    {
        global $wpdb;
        if ($mode === 'migrate') {
            throw new AppointmentException('Bulk migration is disabled. New and changed records are encrypted automatically.');
        }
        if (!in_array($mode, ['preflight', 'verify-mixed', 'verify'], true) || ($network && !is_multisite())) {
            throw new AppointmentException('Invalid appointment maintenance mode or network scope.');
        }
        BookingStorage::release();
        $lock = $network ? NetworkMigration::lockName(get_current_network_id()) : null;
        $primary = $network ? PrimaryConnection::forTable($wpdb, $wpdb->blogs, 'blog_id') : null;
        if ($lock !== null && self::networkValue($primary, 'SELECT GET_LOCK(%s, 0)', $lock) !== '1') {
            throw new AppointmentException('Appointment network maintenance is already running or unavailable.');
        }
        try {
            $upper = $network ? self::networkValue(
                $primary,
                'SELECT MAX(blog_id) FROM %i WHERE site_id = %d', $wpdb->blogs, get_current_network_id()
            ) : get_current_blog_id();
            $counts = ['phase' => $mode, 'checked' => 0, 'skipped' => 0, 'failed' => 0];
            foreach (self::siteIds($lock, (int) $upper, $primary) as $id) {
                $switched = $id !== get_current_blog_id();
                if ($switched) {
                    switch_to_blog($id);
                }
                $result = 'ok';
                try {
                    if ($network && !NetworkMigration::isRelevantSite()) {
                        $counts['skipped']++;
                        $result = 'skipped';
                    } else {
                        $counts['checked']++;
                        match ($mode) {
                            'preflight' => BookingStorage::preflight(),
                            'verify-mixed' => BookingStorage::verifyMixed(),
                            'verify' => BookingStorage::verify(),
                        };
                    }
                } catch (\Throwable) {
                    $counts['failed']++;
                    $result = 'storage_error';
                } finally {
                    try {
                        BookingStorage::release();
                    } finally {
                        if ($switched) {
                            restore_current_blog();
                        }
                    }
                }
                $report(['phase' => $mode, 'site_id' => $id, 'result' => $result]);
            }
            $report($counts);
            return $counts['failed'] === 0;
        } finally {
            try {
                BookingStorage::release();
            } finally {
                if ($lock !== null) {
                    try {
                        self::networkValue($primary, 'SELECT RELEASE_LOCK(%s)', $lock);
                    } catch (\Throwable) {
                        // A disconnected session loses its lock. Preserve any inspection failure.
                    }
                }
            }
        }
    }

    /**
     * Runs a scalar network query on the same connection that acquired the maintenance lock.
     *
     * @param PrimaryConnection|null $primary Pinned writer for the blogs table on routed installations.
     * @param string $query SQL template for the selected connection.
     * @param int|string ...$args Template arguments.
     * @return string|null First column or null if no value is available.
     */
    private static function networkValue(?PrimaryConnection $primary, string $query, int|string ...$args): ?string
    {
        global $wpdb;
        $sql = ($primary ?? $wpdb)->prepare($query, ...$args);
        if ($primary !== null) {
            return $primary->value($sql);
        }
        $value = $wpdb->get_var($sql);
        self::assertQuery();
        return $value;
    }

    /**
     * Requires the network lock and, for a routed connection, the original inventory database.
     *
     * @param PrimaryConnection|null $primary Pinned writer for the blogs table on routed installations.
     * @param string $lock Network maintenance lock name.
     * @throws AppointmentException When ownership or database selection has changed.
     */
    private static function assertNetworkLock(?PrimaryConnection $primary, string $lock): void
    {
        $owned = $primary !== null
            ? $primary->ownsLock($lock)
            : self::networkValue(null, 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock) === '1';
        if (!$owned) {
            throw new AppointmentException('The appointment network maintenance lock was lost.');
        }
    }

    /**
     * Streams site IDs from the network inventory in primary-key batches.
     *
     * @param string|null $lock Network maintenance lock, or null for a single-site check.
     * @param int $upper Largest site ID included in this pass.
     * @param PrimaryConnection|null $primary Pinned writer for the blogs table on routed installations.
     * @return \Generator<int> IDs in this pass's fixed upper bound.
     */
    private static function siteIds(?string $lock, int $upper, ?PrimaryConnection $primary): \Generator
    {
        global $wpdb;
        if ($lock === null) {
            yield get_current_blog_id();
            return;
        }
        $cursor = 0;
        self::assertNetworkLock($primary, $lock);
        do {
            $sql = ($primary ?? $wpdb)->prepare(
                'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d AND blog_id <= %d ORDER BY blog_id ASC LIMIT %d',
                $wpdb->blogs, get_current_network_id(), $cursor, $upper, 10
            );
            if ($primary !== null) {
                $ids = array_column($primary->rows($sql), 'blog_id');
            } else {
                $ids = $wpdb->get_col($sql);
                self::assertQuery();
            }
            foreach ($ids as $id) {
                $cursor = (int) $id;
                yield $cursor;
                // Site checks can switch databases before the next site or inventory batch.
                self::assertNetworkLock($primary, $lock);
            }
        } while ($ids && $cursor < $upper);
    }

    /** Rejects failed inventory queries rather than reporting an empty successful pass. */
    private static function assertQuery(): void
    {
        global $wpdb;
        if ($wpdb->last_error !== '') {
            throw new AppointmentException('Appointment maintenance inventory could not be read.');
        }
    }
}
