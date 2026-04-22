<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class Rights
{
    private static function firstAttribute(array $attrs, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($attrs[$key])) {
                continue;
            }

            $value = $attrs[$key];
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }

            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

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
                        $attrs = (array) $auth->getAttributes();

                        $idm = sanitize_text_field(self::firstAttribute($attrs, [
                            'uid',
                            'idm',
                            'eduPersonPrincipalName',
                            'urn:oid:0.9.2342.19200300.100.1.1',
                        ]));

                        $first = sanitize_text_field(self::firstAttribute($attrs, [
                            'givenName',
                            'gn',
                            'displayName',
                            'cn',
                            'urn:oid:2.5.4.42',
                            'urn:oid:2.5.4.3',
                        ]));

                        $last = sanitize_text_field(self::firstAttribute($attrs, [
                            'sn',
                            'surname',
                            'urn:oid:2.5.4.4',
                        ]));

                        $email = sanitize_email(self::firstAttribute($attrs, [
                            'mail',
                            'email',
                            'mailPrimaryAddress',
                            'urn:oid:0.9.2342.19200300.100.1.3',
                        ]));

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
