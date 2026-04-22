<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class Rights
{
    /**
     * Returns idm, bookerName and bookerEmail.
     * Does NOT trigger SSO login — only reads existing sessions.
     * If not authenticated, returns empty idm to signal login is needed.
     */
    public static function get(): array
    {
        try {
            // 2. Check SSO session passively (no requireAuth trigger)
            if (class_exists('\RRZE\AccessControl\Permissions')) {
                try {
                    $permissions = new \RRZE\AccessControl\Permissions();
                    $auth = $permissions->simplesamlAuth();
                    if ($auth && is_object($auth) && $auth->isAuthenticated()) {
                        $attrs = $auth->getAttributes();

                        $idm   = sanitize_text_field($attrs['uid'][0]       ?? '');
                        $first = sanitize_text_field($attrs['givenName'][0] ?? $attrs['gn'][0] ?? '');
                        $last  = sanitize_text_field($attrs['sn'][0]        ?? '');
                        $email = sanitize_email($attrs['mail'][0]            ?? '');

                        if ($idm) {
                            return ['idm' => $idm, 'bookerName' => trim("$first $last"), 'bookerEmail' => $email];
                        }
                    }
                } catch (\Exception $e) {
                    // SSO not available — fall through
                }
            }

            // 3. Not authenticated
            return ['idm' => '', 'bookerName' => '', 'bookerEmail' => ''];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
