<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Visits multisite storage in bounded, resumable batches, including unvisited sites.
 *
 * Network progress is operational metadata, never authorization to accept plaintext.
 * Every site still verifies its own authenticated migration state and payloads.
 */
final class NetworkMigration
{
    public const HOOK = 'rrze_appointment_migrate_network';
    public const OPTION = 'rrze_appointment_network_migration';
    private const INTERVAL = 'rrze_appointment_migration_minute';
    private const BATCH_SIZE = 10;
    private const BUDGET_SECONDS = 10;
    private static bool $failed = false;

    /** Registers one coordinator per network; single-site installations need no worker. */
    public static function register(): void
    {
        if (!is_multisite()) {
            return;
        }
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action('init', [self::class, 'schedule'], 20);
        add_action(self::HOOK, [self::class, 'run']);
        add_action('network_admin_notices', [self::class, 'renderStatus']);
    }

    /** Adds the recurring interval so WordPress schedules the successor before dispatch. */
    public static function schedules(array $schedules): array
    {
        $schedules[self::INTERVAL] = [
            'interval' => 60,
            'display' => __('Appointment network migration (every minute)', 'rrze-appointment'),
        ];
        return $schedules;
    }

    /**
     * Elects an active coordinator and repairs its event, at most every five minutes.
     *
     * This runs after site startup releases its booking locks. Scheduling is cheap;
     * it never migrates another site's payloads during a visitor's request.
     */
    public static function schedule(): void
    {
        if (!defined(EncryptionKey::CONFIGURATION_CONSTANT)) {
            return;
        }
        $network = get_current_network_id();
        $cached = get_network_option($network, self::OPTION, []);
        if (is_array($cached) && ($cached['checked_at'] ?? 0) > time() - 300) {
            return;
        }
        self::locked($network, static function (string $lock) use ($network): void {
            $state = self::readState($network);
            if (($state['checked_at'] ?? 0) > time() - 300) {
                return;
            }
            $owner = (int) ($state['owner'] ?? 0);
            if (!self::canCoordinate($owner, $network)) {
                $main = get_main_site_id($network);
                $owner = self::canCoordinate($main, $network) ? $main : get_current_blog_id();
            }
            $previousOwner = (int) ($state['owner'] ?? 0);
            if ($previousOwner && $previousOwner !== $owner && get_site($previousOwner)) {
                switch_to_blog($previousOwner);
                try {
                    wp_clear_scheduled_hook(self::HOOK);
                } finally {
                    restore_current_blog();
                }
            }
            switch_to_blog($owner);
            try {
                if (!wp_next_scheduled(self::HOOK)
                    && !wp_schedule_event(time() + 60, self::INTERVAL, self::HOOK)) {
                    throw new AppointmentException('Appointment network migration could not be scheduled.');
                }
            } finally {
                restore_current_blog();
            }
            $state['owner'] = $owner;
            $state['checked_at'] = time();
            $state['updated_at'] ??= time();
            self::saveState($network, $state, $lock);
        });
    }

    /**
     * Processes at most ten sites, checking the soft time budget between sites.
     *
     * A site is checkpointed as in-flight before processing. After a fatal exit it
     * is counted as failed on the next run; the cursor moves on and a later pass
     * retries it. One poison site therefore cannot starve the rest of the network.
     */
    public static function run(): void
    {
        $network = get_current_network_id();
        self::locked($network, static function (string $lock) use ($network): void {
            global $wpdb;
            $state = self::readState($network);
            if (($state['owner'] ?? 0) !== get_current_blog_id() || ($state['next_batch'] ?? 0) > time()) {
                return;
            }
            $state['updated_at'] = time();
            try {
                EncryptionKey::fromConfiguration();
            } catch (AppointmentException $exception) {
                $state['error'] = 'configuration';
                self::saveState($network, $state, $lock);
                return;
            }
            $state['error'] = '';
            if (($state['in_flight'] ?? 0) > 0) {
                $state['failed']++;
                $state['last_failed'] = $state['in_flight'];
                $state['in_flight'] = 0;
            }
            if (!isset($state['upper']) || ($state['finished_at'] ?? 0) > 0) {
                $upper = $wpdb->get_var($wpdb->prepare('SELECT MAX(blog_id) FROM %i WHERE site_id = %d', $wpdb->blogs, $network));
                self::assertQuery();
                $state = array_merge($state, [
                    'cursor' => 0, 'upper' => (int) $upper, 'scanned' => 0,
                    'encrypted' => 0, 'skipped' => 0, 'failed' => 0,
                    'last_failed' => 0, 'in_flight' => 0, 'finished_at' => 0,
                ]);
            }
            // Reserve a minute even when duplicate/stale cron events are dispatched.
            $state['next_batch'] = time() + 60;
            self::saveState($network, $state, $lock);
            $ids = $wpdb->get_col($wpdb->prepare(
                'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d AND blog_id <= %d ORDER BY blog_id ASC LIMIT %d',
                $wpdb->blogs, $network, $state['cursor'], $state['upper'], self::BATCH_SIZE
            ));
            self::assertQuery();
            $started = microtime(true);
            BookingStorage::release();
            foreach ($ids as $id) {
                $id = (int) $id;
                $state['cursor'] = $id;
                $state['scanned']++;
                $state['in_flight'] = $id;
                self::saveState($network, $state, $lock);
                switch_to_blog($id);
                try {
                    if (self::activeOn($id, $network) || self::hasStoredData()) {
                        BookingStorage::initialize();
                        $state['encrypted']++;
                    } else {
                        $state['skipped']++;
                    }
                } catch (\Throwable $exception) {
                    // Never persist exception messages, booking values or key material.
                    $state['failed']++;
                    $state['last_failed'] = $id;
                } finally {
                    try {
                        BookingStorage::release();
                    } finally {
                        restore_current_blog();
                    }
                }
                $state['in_flight'] = 0;
                $state['updated_at'] = time();
                self::saveState($network, $state, $lock);
                if (microtime(true) - $started >= self::BUDGET_SECONDS) {
                    break;
                }
            }
            if (!$ids || $state['cursor'] >= $state['upper']) {
                $state['finished_at'] = time();
                // Retry errors hourly; daily sweeps discover new/restored/inactive sites.
                $state['next_batch'] = time() + ($state['failed'] ? 3600 : 86400);
                self::saveState($network, $state, $lock);
            }
        });
    }

    /** Reports aggregate progress and one failing site ID only to network administrators. */
    public static function renderStatus(): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        $state = get_network_option(get_current_network_id(), self::OPTION, []);
        if (!is_array($state) || !$state) {
            if (!self::$failed) {
                return;
            }
            $state = [];
        }
        $message = sprintf(
            __('Appointment network migration: %1$d sites checked, %2$d encrypted, %3$d skipped, %4$d failed. Coordinator site: %5$d. Last failed site: %6$d.', 'rrze-appointment'),
            $state['scanned'] ?? 0, $state['encrypted'] ?? 0, $state['skipped'] ?? 0,
            $state['failed'] ?? 0, $state['owner'] ?? 0, $state['last_failed'] ?? 0
        );
        $stalled = !($state['finished_at'] ?? 0) && ($state['updated_at'] ?? 0) < time() - 900;
        $problem = self::$failed || !empty($state['error']) || $stalled;
        if ($problem) {
            $message .= ' ' . __('Migration needs attention. Check the encryption key, database and cron execution on the coordinator site.', 'rrze-appointment');
        } elseif ($state['finished_at'] ?? 0) {
            $message .= ' ' . __('This pass has finished. Failed sites are retried in a later pass.', 'rrze-appointment');
        } else {
            $message .= ' ' . __('Migration continues automatically in small batches.', 'rrze-appointment');
        }
        $class = $problem || ($state['failed'] ?? 0) ? 'notice-warning' : 'notice-info';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    /** Determines whether the coordinator can load this plugin on its own cron request. */
    private static function activeOn(int $site, int $network): bool
    {
        $plugin = plugin_basename(dirname(__DIR__, 2) . '/rrze-appointment.php');
        $networkPlugins = get_network_option($network, 'active_sitewide_plugins', []);
        return isset($networkPlugins[$plugin]) || in_array($plugin, (array) get_blog_option($site, 'active_plugins', []), true);
    }

    /** Requires a routable site in this network where the cron callback will be loaded. */
    private static function canCoordinate(int $id, int $network): bool
    {
        $site = $id ? get_site($id) : null;
        return $site && (int) $site->network_id === $network && !$site->deleted && !$site->archived && !$site->spam
            && self::activeOn($id, $network);
    }

    /** Includes dormant installations with booking data, without populating option caches. */
    private static function hasStoredData(): bool
    {
        global $wpdb;
        $names = [...BookingStorage::OPTIONS, BookingStorage::STATE_OPTION];
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name IN (' . implode(',', array_fill(0, count($names), '%s')) . ') LIMIT 1',
            $wpdb->options, ...$names
        ));
        self::assertQuery();
        return $found !== null;
    }

    /** Reads fresh coordinator metadata after acquiring the network lock. */
    private static function readState(int $network): array
    {
        wp_cache_delete($network . ':' . self::OPTION, 'site-options');
        wp_cache_delete($network . ':notoptions', 'site-options');
        $state = get_network_option($network, self::OPTION, []);
        self::assertQuery();
        if (!is_array($state)) {
            throw new AppointmentException('Appointment network migration state is invalid.');
        }
        return $state;
    }

    /** Verifies lock ownership and database persistence before advancing the cursor. */
    private static function saveState(int $network, array $state, string $lock): void
    {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock)) !== '1') {
            throw new AppointmentException('Appointment network migration lock was lost.');
        }
        update_network_option($network, self::OPTION, $state);
        if (self::readState($network) !== $state) {
            throw new AppointmentException('Appointment network migration progress could not be saved.');
        }
    }

    /** Rejects failed SQL instead of treating it as an empty or completed network. */
    private static function assertQuery(): void
    {
        global $wpdb;
        if ($wpdb->last_error !== '') {
            throw new AppointmentException('Appointment network migration database access failed.');
        }
    }

    /**
     * Serializes coordinator work without waiting or holding other sites' booking locks.
     *
     * @param callable(string): void $operation Work to run while owning the network lock.
     */
    private static function locked(int $network, callable $operation): void
    {
        global $wpdb;
        $lock = 'rrze-appt-net:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->blogs . ':' . $network), 0, 40);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ($acquired !== '1') {
            self::$failed = self::$failed || $acquired !== '0';
            return;
        }
        try {
            $operation($lock);
        } catch (\Throwable $exception) {
            self::$failed = true;
            try {
                $state = self::readState($network);
                $state['error'] = 'worker';
                self::saveState($network, $state, $lock);
            } catch (\Throwable $persistenceFailure) {
                // Database/lock failures may also prevent recording the safe error code.
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
