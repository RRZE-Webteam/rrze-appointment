<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class Rights
{
    /**
     * Returns idm, bookerName and bookerEmail from:
     * 1. RRZE\AccessControl\Permissions (rrze-ac / SSO) if available and authenticated
     * 2. Logged-in WordPress user
     * 3. Empty strings as fallback (triggers login redirect)
     */
    public static function get(): array
    {
        try {
            // 1. SSO via rrze-ac
            if (class_exists('\RRZE\AccessControl\Permissions')) {
                $permissions = new \RRZE\AccessControl\Permissions();
                if ($permissions->checkSSOLoggedIn()) {
                    $attrs = $permissions->personAttributes ?? [];

                    $idm   = sanitize_text_field($attrs['uid'][0] ?? '');
                    $first = sanitize_text_field($attrs['givenName'][0] ?? $attrs['gn'][0] ?? '');
                    $last  = sanitize_text_field($attrs['sn'][0] ?? '');
                    $email = sanitize_email($attrs['mail'][0] ?? '');

                    if ($idm) {
                        return [
                            'idm'         => $idm,
                            'bookerName'  => trim("$first $last"),
                            'bookerEmail' => $email,
                        ];
                    }
                }
            }

            // 2. WordPress user (incl. SSO users logged in via rrze-sso)
            if (is_user_logged_in()) {
                $user  = wp_get_current_user();
                $name  = sanitize_text_field(trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name);
                return [
                    'idm'         => sanitize_text_field($user->user_login),
                    'bookerName'  => $name,
                    'bookerEmail' => sanitize_email($user->user_email),
                ];
            }

            // 3. Not authenticated
            return ['idm' => '', 'bookerName' => '', 'bookerEmail' => ''];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
