<?php

namespace RRZE\Appointment\Admin;

defined('ABSPATH') || exit;

/** Restricts operational diagnostics to the plugin's own administration screens. */
final class DiagnosticNotice
{
    /**
     * Checks the real screen before any storage inspection or lock acquisition.
     *
     * @return bool Whether this operator may see technical storage diagnostics here.
     */
    public static function allowed(): bool
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, [BookingsPage::screenHook(), SettingsPage::screenHook()], true)) {
            return false;
        }
        return is_multisite() ? is_super_admin() : current_user_can('manage_options');
    }
}
