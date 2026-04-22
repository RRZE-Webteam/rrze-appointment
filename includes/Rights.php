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
            // 1. WordPress user logged in (covers SSO users after login via rrze-sso)
            // if (is_user_logged_in()) {
            //     $user  = wp_get_current_user();
            //     $name  = sanitize_text_field(trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name);
            //     $email = sanitize_email($user->user_email);
            //     $idm   = sanitize_text_field($user->user_login);

            //     // Enrich from SSO attributes stored by rrze-sso after login
            //     $ssoAttrs = get_user_meta($user->ID, 'sso_attributes', true);
            //     if (!empty($ssoAttrs) && is_array($ssoAttrs)) {
            //         $uid   = $ssoAttrs['uid'][0]       ?? '';
            //         $first = $ssoAttrs['givenName'][0] ?? $ssoAttrs['gn'][0] ?? '';
            //         $last  = $ssoAttrs['sn'][0]        ?? '';
            //         $mail  = $ssoAttrs['mail'][0]       ?? '';

            //         if ($uid)          $idm   = sanitize_text_field($uid);
            //         if ($mail)         $email = sanitize_email($mail);
            //         if ($first || $last) $name = sanitize_text_field(trim("$first $last"));
            //     }

            //     return ['idm' => $idm, 'bookerName' => $name, 'bookerEmail' => $email];
            // }


            if (class_exists('\RRZE\AccessControl\Permissions')) {
                try {
                    $permissionsInstance = new Permissions();
                    $checkSSOLoggedIn = $permissionsInstance->checkSSOLoggedIn();
                    $personAttributes = $permissionsInstance->personAttributes;

                    echo '<pre>';
                    var_dump($personAttributes);
                    exit;

                    // $this->idm = (!empty($personAttributes['uid'][0]) ? $personAttributes['uid'][0] : null);
                } catch (\Exception $e) {
                    // SSO not available — fall through
                }
            }

            // 2. Check SSO session passively (no requireAuth trigger)
            // if (class_exists('\RRZE\AccessControl\Permissions')) {
            //     try {
            //         $permissions = new \RRZE\AccessControl\Permissions();
            //         $auth = $permissions->simplesamlAuth();
            //         if ($auth && is_object($auth) && $auth->isAuthenticated()) {
            //             $attrs = $auth->getAttributes();

            //             echo '<pre>';
            //             var_dump($attrs);
            //             exit;

            //             $idm   = sanitize_text_field($attrs['uid'][0]       ?? '');
            //             $first = sanitize_text_field($attrs['givenName'][0] ?? $attrs['gn'][0] ?? '');
            //             $last  = sanitize_text_field($attrs['sn'][0]        ?? '');
            //             $email = sanitize_email($attrs['mail'][0]            ?? '');

            //             if ($idm) {
            //                 return ['idm' => $idm, 'bookerName' => trim("$first $last"), 'bookerEmail' => $email];
            //             }
            //         }
            //     } catch (\Exception $e) {
            //         // SSO not available — fall through
            //     }
            // }

            // 3. Not authenticated
            return ['idm' => '', 'bookerName' => '', 'bookerEmail' => ''];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
