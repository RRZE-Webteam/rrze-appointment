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
     * Administrators always have access; listed site users receive delegated access.
     */
    public static function currentUserCanManage(): bool
    {
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
}
