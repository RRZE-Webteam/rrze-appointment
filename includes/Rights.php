<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class Rights
{
    /**
     * Returns idm, bookerName and bookerEmail from:
     * 1. RRZE\AccessControl\Permissions (SSO) if available
     * 2. Logged-in WordPress user
     * 3. Empty strings as fallback
     */
    public static function get(): array
    {
        try {
            if (class_exists('\RRZE\AccessControl\Permissions')) {
                $permissions = new \RRZE\AccessControl\Permissions();
                $permissions->checkSSOLoggedIn();
                $attrs = $permissions->personAttributes ?? [];

                $idm   = sanitize_text_field($attrs['uid'][0]          ?? '');
                $first = sanitize_text_field($attrs['givenName'][0]     ?? $attrs['vorname'][0]    ?? '');
                $last  = sanitize_text_field($attrs['sn'][0]            ?? $attrs['nachname'][0]   ?? '');
                $email = sanitize_email($attrs['mail'][0]               ?? $attrs['email'][0]      ?? '');

                if ($idm) {
                    return [
                        'idm'         => $idm,
                        'bookerName'  => trim("$first $last"),
                        'bookerEmail' => $email,
                    ];
                }
            }

            if (is_user_logged_in()) {
                $user = wp_get_current_user();
                return [
                    'idm'         => sanitize_text_field($user->user_login),
                    'bookerName'  => sanitize_text_field(trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name),
                    'bookerEmail' => sanitize_email($user->user_email),
                ];
            }

            return ['idm' => '', 'bookerName' => '', 'bookerEmail' => ''];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
