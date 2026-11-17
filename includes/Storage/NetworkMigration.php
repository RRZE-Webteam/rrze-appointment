<?php

namespace RRZE\Appointment\Storage;

defined('ABSPATH') || exit;

/** Retires the V1 coordinator and supplies site inventory for explicit CLI checks. */
final class NetworkMigration
{
    public const HOOK = 'rrze_appointment_migrate_network';

    /** Lets WordPress consume an already queued V1 event without scheduling its successor. */
    public static function register(): void
    {
        if (is_multisite()) {
            add_filter('pre_reschedule_event', [self::class, 'retireEvent'], 10, 2);
        }
    }

    /**
     * Acknowledges a reschedule of the retired coordinator without touching another site's queue.
     *
     * @param mixed $result Earlier filter's decision.
     * @param object $event WordPress cron event.
     * @return mixed Filter decision for this event.
     */
    public static function retireEvent($result, object $event)
    {
        return $event->hook === self::HOOK ? true : $result;
    }

    /** Checks both network-wide and site-local plugin activation. */
    private static function activeOn(int $site, int $network): bool
    {
        $plugin = plugin_basename(dirname(__DIR__, 2) . '/rrze-appointment.php');
        $networkPlugins = get_network_option($network, 'active_sitewide_plugins', []);
        return isset($networkPlugins[$plugin]) || in_array($plugin, (array) get_blog_option($site, 'active_plugins', []), true);
    }

    /**
     * Selects active sites and dormant data for an explicitly requested CLI inspection.
     *
     * @return bool Active plugin installation or an inactive site retaining protected stores.
     */
    public static function isRelevantSite(): bool
    {
        return self::activeOn(get_current_blog_id(), get_current_network_id()) || BookingStorage::hasStoredData();
    }

    /** Returns the network lock for explicit CLI inspections, compatible with V1 maintenance. */
    public static function lockName(int $network): string
    {
        global $wpdb;
        return 'rrze-appt-net:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->blogs . ':' . $network), 0, 40);
    }
}
