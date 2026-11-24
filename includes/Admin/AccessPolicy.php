<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Configuration\PluginSettings;
use RRZE\Appointment\Storage\OptionDatabase;

defined('ABSPATH') || exit;

/** Stores deliberate, site-local grants independently of ordinary plugin settings. */
final class AccessPolicy
{
    public const OPTION_NAME = 'rrze_appointment_access_policy';

    /**
     * Reads the site's grants and their revision for use in access checks.
     *
     * @return array{
     *     version: 1|2, restricted: true, revision: positive-int,
     *     owners: int[], managers: int[], access_owners: int[]
     * }|\WP_Error|null Valid grants, null for an unconfigured site, or a WP_Error for unreadable or malformed grants.
     */
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

    /**
     * Validates the stored format and preserves independent booking grants from version 1.
     *
     * @param mixed $value Raw site option. Version 1 could name editors as responsible people.
     * @return array|\WP_Error Effective role groups, or an error for unsupported or malformed data.
     */
    private static function validate(mixed $value): array|\WP_Error
    {
        if (!is_array($value) || !in_array($value['version'] ?? null, [1, 2], true)
            || ($value['restricted'] ?? null) !== true
            || !is_int($value['revision'] ?? null) || $value['revision'] < 1
            || !is_array($value['owners'] ?? null)
            || !is_array($value['managers'] ?? null)) {
            return self::unavailable();
        }
        $legacy = $value['version'] === 1;
        $accessOwners = $legacy ? [] : ($value['access_owners'] ?? null);
        if (!is_array($accessOwners) || (!$value['owners'] && !$accessOwners)) {
            return self::unavailable();
        }
        $ids = array_merge($value['owners'], $value['managers'], $accessOwners);
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                return self::unavailable();
            }
        }
        if (!$legacy && count(array_unique($ids)) !== count($ids)) {
            return self::unavailable();
        }
        $value['access_owners'] = $accessOwners;
        if ($legacy) {
            $oldOwners = $value['owners'];
            $value['owners'] = array_values(array_filter($oldOwners, [self::class, 'eligibleOwner']));
            $bookingOnly = array_filter(array_diff($oldOwners, $value['owners']), [self::class, 'eligible']);
            $value['managers'] = array_values(array_unique(array_merge($value['managers'], $bookingOnly)));
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

    /**
     * Checks the member's effective administrator capabilities on the current site.
     *
     * @param int $id WordPress user ID to consider for a responsible role.
     * @return bool Whether the member has site administrator access independently of network privileges.
     */
    public static function eligibleOwner(int $id): bool
    {
        $user = $id > 0 ? get_userdata($id) : false;
        if (!$user || !is_user_member_of_blog($id, get_current_blog_id())
            || !user_can($user, 'read') || !user_can($user, 'manage_options')) {
            return false;
        }
        // WordPress automatically grants both capabilities to multisite super administrators.
        return !is_multisite() || !is_super_admin($id) || !empty($user->allcaps['manage_options']);
    }

    /**
     * Checks whether a policy still has a responsible administrator with either data-access choice.
     *
     * @param array $policy Valid policy from read().
     */
    public static function hasEligibleOwner(array $policy): bool
    {
        foreach (array_merge($policy['owners'], $policy['access_owners']) as $id) {
            if (self::eligibleOwner($id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks booking rights in a configured policy before the separate SSO check.
     *
     * @param array $policy Valid policy from read().
     * @param int $id Site member requesting access.
     */
    public static function hasBookingGrant(array $policy, int $id): bool
    {
        return (in_array($id, $policy['owners'], true) && self::eligibleOwner($id))
            || (in_array($id, $policy['managers'], true) && self::eligible($id));
    }

    /** Returns whether the acting account may reassign responsibility on an already restricted site. */
    public static function canRecover(): bool
    {
        $policy = self::read();
        if (is_multisite()) {
            return $policy !== null && BackendSsoAccess::canManageNetworkAccess();
        }
        return is_array($policy) && self::eligibleOwner(get_current_user_id()) && !self::hasEligibleOwner($policy);
    }

    /** Checks both kinds of responsibility independently of SSO so an administrator can reach login. */
    public static function owner(): bool
    {
        $policy = self::read();
        $id = get_current_user_id();
        return is_array($policy) && self::eligibleOwner($id)
            && (in_array($id, $policy['owners'], true) || in_array($id, $policy['access_owners'], true));
    }

    /** Binds a form to the current policy, SSO state and any legacy grants included in first-time setup. */
    public static function token(): string
    {
        $policy = get_option(self::OPTION_NAME, null);
        $state = [$policy, BackendSsoAccess::required()];
        if ($policy === null) {
            $state[] = PluginSettings::get('appointment_manager_user_ids');
        }
        return hash('sha256', serialize($state));
    }

    /**
     * Runs one access-policy or SSO update under the site's options lock after clearing the shared option caches.
     *
     * @param callable(OptionDatabase): (true|\WP_Error) $operation Update to perform with the locked database.
     * @return true|\WP_Error The callback result, or an unavailable-policy error if an exception occurs.
     */
    public static function locked(callable $operation): true|\WP_Error
    {
        global $wpdb;
        $database = null;
        try {
            $database = new OptionDatabase($wpdb, $wpdb->options);
            foreach ([self::OPTION_NAME, BackendSsoAccess::OPTION_NAME, PluginSettings::OPTION_NAME, 'alloptions', 'notoptions'] as $key) {
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
     * Applies a confirmed setup, role change or recovery, preserving the stored format for unchanged roles.
     *
     * @param string $operation setup, save, network_setup, network_save or recover.
     * @param array $owners Submitted responsible administrators with booking access.
     * @param array $managers Submitted ordinary manager IDs.
     * @param string $token Observed policy token, bound into the form nonce.
     * @param array|null $accessOwners Submitted responsible administrators without booking access.
     *                                Null denotes a 2.5.0 submission which cannot describe the new role.
     * @return true|\WP_Error True when the requested site-local grants are in effect.
     */
    public static function change(string $operation, array $owners, array $managers, string $token, ?array $accessOwners = null): true|\WP_Error
    {
        return self::locked(static function (OptionDatabase $database) use ($operation, $owners, $managers, $token, $accessOwners) {
            $policy = self::read();
            $access = self::authorizeChange($operation, $policy);
            if (is_wp_error($access)) {
                return $access;
            }
            $legacyForm = $accessOwners === null;
            if (!hash_equals(self::token(), $token) || ($legacyForm && is_array($policy) && $policy['version'] === 2)) {
                return self::conflict();
            }
            $owners = self::submittedIds($owners, true);
            $accessOwners = self::submittedIds($accessOwners ?? [], true);
            if (is_wp_error($owners) || is_wp_error($accessOwners) || array_intersect($owners, $accessOwners)) {
                return self::invalidOwners();
            }
            if ($operation === 'recover') {
                $managers = is_array($policy) ? $policy['managers'] : [];
            } else {
                $managers = self::submittedIds($managers);
            }
            if (is_wp_error($managers)) {
                return $managers;
            }
            $actor = get_current_user_id();
            if ($operation === 'setup' && $legacyForm) {
                $owners[] = $actor;
                $owners = array_values(array_unique($owners));
            }
            if ((!$owners && !$accessOwners)
                || ($operation === 'setup' && !in_array($actor, $owners, true) && !in_array($actor, $accessOwners, true))) {
                return new \WP_Error('rrze_appointment_owner_required',
                    __('Select at least one responsible person.', 'rrze-appointment'), ['status' => 400]);
            }
            $managers = array_values(array_diff($managers, $owners, $accessOwners));
            if ($policy !== null && (in_array($actor, $owners, true) || in_array($actor, $managers, true))
                && !(is_array($policy) && self::hasBookingGrant($policy, $actor))) {
                return new \WP_Error('rrze_appointment_self_grant_forbidden',
                    __('Ask another responsible administrator to grant you access to bookings.', 'rrze-appointment'), ['status' => 403]);
            }
            $next = ['version' => $legacyForm ? 1 : 2, 'restricted' => true, 'revision' => is_array($policy) ? $policy['revision'] + 1 : 1,
                'owners' => $owners, 'managers' => $managers];
            if (!$legacyForm) {
                $next['access_owners'] = $accessOwners;
            }
            if (is_array($policy)) {
                if ($policy['version'] === 1 && $operation !== 'recover') {
                    // The role form omits former members and ineligible managers. Their absence alone is not an edit.
                    $policy['managers'] = array_filter($policy['managers'], [self::class, 'eligible']);
                }
                if (self::rolesByUser($policy) == self::rolesByUser($next)) {
                    $database->assertOwnership();
                    return true;
                }
            }
            $previous = $database->read(self::OPTION_NAME);
            return $database->replace(self::OPTION_NAME, $previous, serialize($next)) ? true : self::conflict();
        });
    }

    /**
     * Maps grants to the role displayed for each user. A version 1 owner with a manager grant has one owner row.
     *
     * @param array{owners: int[], managers: int[], access_owners?: int[]} $policy Current or proposed grants.
     * @return array<int, string> Form role keyed by user ID.
     */
    public static function rolesByUser(array $policy): array
    {
        return array_fill_keys($policy['owners'], 'owners')
            + array_fill_keys($policy['access_owners'] ?? [], 'access_owners')
            + array_fill_keys($policy['managers'], 'managers');
    }

    /**
     * Authorizes a grant change while holding the site's options lock.
     *
     * @param string $operation Requested operation from the nonce-protected form.
     * @param array|\WP_Error|null $policy Current explicit grants, read error, or null before setup.
     * @return true|\WP_Error Whether the current account may perform this operation.
     */
    private static function authorizeChange(string $operation, array|\WP_Error|null $policy): true|\WP_Error
    {
        $permitted = match ($operation) {
            'network_setup' => BackendSsoAccess::canManageNetworkAccess() && $policy === null,
            'network_save' => BackendSsoAccess::canManageNetworkAccess() && is_array($policy),
            'recover' => self::canRecover(),
            'setup' => $policy === null && self::eligibleOwner(get_current_user_id()),
            'save' => self::owner(),
            default => false,
        };
        if (!$permitted) {
            return AppointmentPermissions::forbidden();
        }
        return match ($operation) {
            'recover' => is_multisite() ? true : BackendSsoAccess::check(),
            'network_setup', 'network_save' => BackendSsoAccess::verifyIdentity(),
            default => BackendSsoAccess::check(),
        };
    }

    /**
     * Validates IDs against the site capabilities required by their proposed role.
     *
     * @param array $values Submitted numeric IDs.
     * @param bool $responsible Whether the target users must be site administrators.
     * @return int[]|\WP_Error Sorted and deduplicated IDs, or a role-specific validation error.
     */
    private static function submittedIds(array $values, bool $responsible = false): array|\WP_Error
    {
        $ids = [];
        foreach ($values as $value) {
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
                || !($responsible ? self::eligibleOwner((int) $value) : self::eligible((int) $value))) {
                return $responsible ? self::invalidOwners() : self::invalidUsers();
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

    /** Explains the site administrator requirement for both types of responsibility. */
    private static function invalidOwners(): \WP_Error
    {
        return new \WP_Error('rrze_appointment_invalid_owners',
            __('Only administrators of this website can be responsible for access.', 'rrze-appointment'), ['status' => 400]);
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
