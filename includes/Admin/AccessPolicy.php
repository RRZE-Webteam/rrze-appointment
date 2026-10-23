<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Storage\OptionDatabase;

defined('ABSPATH') || exit;

/** Stores deliberate, site-local grants independently of ordinary plugin settings. */
final class AccessPolicy
{
    public const OPTION_NAME = 'rrze_appointment_access_policy';

    /** Returns null only for an unconfigured site; malformed policies remain closed. */
    public static function read(): array|\WP_Error|null
    {
        $missing = new \stdClass();
        $value = get_option(self::OPTION_NAME, $missing);
        global $wpdb;
        if (isset($wpdb) && $wpdb->last_error !== '') {
            return self::unavailable();
        }
        return $value === $missing ? null : self::validate($value);
    }

    /** Validates persisted structure without silently repairing or broadening grants. */
    private static function validate(mixed $value): array|\WP_Error
    {
        if (!is_array($value) || ($value['version'] ?? null) !== 1
            || ($value['restricted'] ?? null) !== true
            || !is_int($value['revision'] ?? null) || $value['revision'] < 1
            || !is_array($value['owners'] ?? null) || !$value['owners']
            || !is_array($value['managers'] ?? null)) {
            return self::unavailable();
        }
        foreach (array_merge($value['owners'], $value['managers']) as $id) {
            if (!is_int($id) || $id < 1) {
                return self::unavailable();
            }
        }
        return $value;
    }

    /** Recognizes configured restrictions, including damaged configurations. */
    public static function restricted(): bool
    {
        return self::read() !== null;
    }

    /** Requires current site membership and capabilities equivalent to an editor. */
    public static function eligible(int $id): bool
    {
        $user = $id > 0 ? get_userdata($id) : false;
        return $user && is_user_member_of_blog($id, get_current_blog_id())
            && user_can($user, 'read')
            && (user_can($user, 'edit_others_posts') || user_can($user, 'manage_options'));
    }

    /** Checks ownership independently of SSO so a responsible user can reach login. */
    public static function owner(): bool
    {
        $policy = self::read();
        return is_array($policy) && self::eligible(get_current_user_id())
            && in_array(get_current_user_id(), $policy['owners'], true);
    }

    /** Produces an opaque revision token even for a damaged policy that support must repair. */
    public static function token(): string
    {
        return hash('sha256', serialize([get_option(self::OPTION_NAME, null), BackendSsoAccess::required()]));
    }

    /** Serializes all security writes and refreshes their shared option snapshots. */
    public static function locked(callable $operation): true|\WP_Error
    {
        global $wpdb;
        $database = null;
        try {
            $database = new OptionDatabase($wpdb, $wpdb->options);
            foreach ([self::OPTION_NAME, BackendSsoAccess::OPTION_NAME, 'alloptions', 'notoptions'] as $key) {
                wp_cache_delete($key, 'options');
            }
            return $operation($database);
        } catch (\Throwable $exception) {
            return self::unavailable();
        } finally {
            $database?->release();
        }
    }

    /**
     * Applies an explicitly confirmed setup, owner update or network recovery.
     *
     * @param string $operation setup, save or recover.
     * @param array $owners Submitted responsible user IDs.
     * @param array $managers Submitted ordinary manager IDs.
     * @param string $token Observed policy token, bound into the form nonce.
     */
    public static function change(string $operation, array $owners, array $managers, string $token): true|\WP_Error
    {
        return self::locked(static function (OptionDatabase $database) use ($operation, $owners, $managers, $token) {
            $policy = self::read();
            if ($operation === 'recover') {
                if (!BackendSsoAccess::canReset() || !current_user_can('manage_options') || $policy === null) {
                    return AppointmentPermissions::forbidden();
                }
            } elseif ($operation === 'setup') {
                if (!current_user_can('manage_options') || !self::eligible(get_current_user_id()) || $policy !== null) {
                    return AppointmentPermissions::forbidden();
                }
            } elseif ($operation !== 'save' || !self::owner()) {
                return AppointmentPermissions::forbidden();
            }
            if ($operation !== 'recover') {
                $access = BackendSsoAccess::check();
                if (is_wp_error($access)) {
                    return $access;
                }
            }
            if (!hash_equals(self::token(), $token)) {
                return self::conflict();
            }
            $owners = self::submittedIds($owners);
            $managers = $operation === 'recover' && is_array($policy)
                ? $policy['managers'] : self::submittedIds($managers);
            if (is_wp_error($owners) || is_wp_error($managers)) {
                return self::invalidUsers();
            }
            if ($operation === 'setup') {
                $owners[] = get_current_user_id();
                $owners = array_values(array_unique($owners));
            }
            if (!$owners) {
                return new \WP_Error('rrze_appointment_owner_required',
                    __('Keep at least one eligible responsible person. Add a successor before removing yourself.', 'rrze-appointment'), ['status' => 400]);
            }
            $next = ['version' => 1, 'restricted' => true, 'revision' => is_array($policy) ? $policy['revision'] + 1 : 1,
                'owners' => $owners, 'managers' => $operation === 'recover' ? $managers : array_values(array_diff($managers, $owners))];
            $previous = $database->read(self::OPTION_NAME);
            return $database->replace(self::OPTION_NAME, $previous, serialize($next)) ? true : self::conflict();
        });
    }

    /** Rejects malformed or ineligible submitted IDs rather than silently dropping grants. */
    private static function submittedIds(array $values): array|\WP_Error
    {
        $ids = [];
        foreach ($values as $value) {
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
                || !self::eligible((int) $value)) {
                return self::invalidUsers();
            }
            $ids[] = (int) $value;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /** Explains rejected grants without disclosing account details. */
    private static function invalidUsers(): \WP_Error
    {
        return new \WP_Error('rrze_appointment_invalid_users',
            __('Select current members of this website with at least editor permissions.', 'rrze-appointment'), ['status' => 400]);
    }

    /** Reports a stale form without overwriting another responsible person's changes. */
    public static function conflict(): \WP_Error
    {
        return new \WP_Error('rrze_appointment_access_conflict',
            __('Access settings have changed. Reload the page and review the current permissions before saving again.', 'rrze-appointment'), ['status' => 409]);
    }

    /** Returns a safe closed state for unreadable policies or security storage failures. */
    public static function unavailable(): \WP_Error
    {
        return new \WP_Error('rrze_appointment_access_unavailable',
            __('Access settings are unavailable. Please try again or contact support to reassign responsibility.', 'rrze-appointment'), ['status' => 503]);
    }
}
