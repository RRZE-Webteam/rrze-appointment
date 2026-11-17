<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Configuration\PluginSettings;

defined('ABSPATH') || exit;

/**
 * Authorizes access to the appointment management screen and its actions.
 */
final class AppointmentPermissions
{
    /**
     * Checks the base grant; SSO is deliberately separate so users can reach login.
     */
    public static function currentUserCanManage(): bool
    {
        $policy = AccessPolicy::read();
        if ($policy !== null) {
            return is_array($policy) && AccessPolicy::eligible(get_current_user_id())
                && in_array(get_current_user_id(), array_merge($policy['owners'], $policy['managers']), true);
        }
        if (current_user_can('manage_options')) {
            return true;
        }

        $userId = get_current_user_id();
        if (
            $userId < 1
            || !is_user_member_of_blog($userId, get_current_blog_id())
        ) {
            return false;
        }

        $allowedUserIds = PluginSettings::get('appointment_manager_user_ids');
        if (!is_array($allowedUserIds)) {
            return false;
        }

        return in_array($userId, array_map('absint', $allowedUserIds), true);
    }

    /** Authorizes actual booking reads and cancellations through the same decision. */
    public static function dataAccess(): true|\WP_Error
    {
        $policy = AccessPolicy::read();
        if (is_wp_error($policy)) {
            return $policy;
        }
        return self::currentUserCanManage() ? BackendSsoAccess::check() : self::forbidden();
    }

    /** Authorizes delegation without requiring general WordPress settings access. */
    public static function grantsAccess(): true|\WP_Error
    {
        return AccessPolicy::owner() ? BackendSsoAccess::check() : new \WP_Error('rrze_appointment_grants_forbidden',
            __('Only responsible people can manage permissions. Contact a responsible person or support if responsibility needs to be reassigned.', 'rrze-appointment'), ['status' => 403]);
    }

    /** Returns a useful denial without revealing granted accounts in REST responses. */
    public static function forbidden(): \WP_Error
    {
        return new \WP_Error('rrze_appointment_forbidden',
            __('Your account is not authorized for this appointment management. Contact a responsible person if you need to view or cancel appointments.', 'rrze-appointment'),
            ['status' => 403]);
    }
}
