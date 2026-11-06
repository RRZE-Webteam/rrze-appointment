<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/** Runs explicit, bounded maintenance passes while external writers are drained. */
final class StorageMaintenance
{
    /**
     * Checks every target before migration and verifies every target afterwards.
     *
     * No coordinator options or cron events are scheduled here. Preflight/verify
     * take locks but do not write stored data. The network lock coordinates our
     * workers only; callers must pause all other web, cron and CLI writers.
     *
     * @param string $mode One of preflight, migrate or verify.
     * @param bool $network Include the current network's active/dormant installations.
     * @param callable(array): void $report Receives safe per-site results and phase totals.
     * @return bool True only if every required pass completed without failures.
     * @throws AppointmentException On invalid configuration, inventory or lock errors.
     */
    public static function run(string $mode, bool $network, callable $report): bool
    {
        global $wpdb;
        if (!in_array($mode, ['preflight', 'migrate', 'verify'], true) || ($network && !is_multisite())) {
            throw new AppointmentException('Invalid appointment maintenance mode or network scope.');
        }
        EncryptionKey::fromConfiguration();
        BookingStorage::release();
        $lock = $network ? NetworkMigration::lockName(get_current_network_id()) : null;
        if ($lock !== null && $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            throw new AppointmentException('Appointment network maintenance is already running or unavailable.');
        }
        try {
            $upper = $network ? $wpdb->get_var($wpdb->prepare(
                'SELECT MAX(blog_id) FROM %i WHERE site_id = %d', $wpdb->blogs, get_current_network_id()
            )) : get_current_blog_id();
            self::assertQuery();
            $phases = $mode === 'migrate' ? ['preflight', 'migrate', 'verify'] : [$mode];
            foreach ($phases as $phase) {
                $counts = ['phase' => $phase, 'checked' => 0, 'skipped' => 0, 'failed' => 0];
                foreach (self::siteIds($network, (int) $upper) as $id) {
                    if ($lock !== null && $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock)) !== '1') {
                        throw new AppointmentException('The appointment network maintenance lock was lost.');
                    }
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
                            match ($phase) {
                                'preflight' => BookingStorage::preflight(),
                                'migrate' => BookingStorage::initialize(),
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
                    $report(['phase' => $phase, 'site_id' => $id, 'result' => $result]);
                }
                $report($counts);
                if ($counts['failed']) {
                    return false;
                }
            }
            return true;
        } finally {
            BookingStorage::release();
            if ($lock !== null) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * Streams site IDs in primary-key batches without loading an entire network.
     *
     * @return \Generator<int> IDs in this pass's fixed upper bound.
     */
    private static function siteIds(bool $network, int $upper): \Generator
    {
        global $wpdb;
        if (!$network) {
            yield get_current_blog_id();
            return;
        }
        $cursor = 0;
        do {
            $ids = $wpdb->get_col($wpdb->prepare(
                'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d AND blog_id <= %d ORDER BY blog_id ASC LIMIT %d',
                $wpdb->blogs, get_current_network_id(), $cursor, $upper, 10
            ));
            self::assertQuery();
            foreach ($ids as $id) {
                $cursor = (int) $id;
                yield $cursor;
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
