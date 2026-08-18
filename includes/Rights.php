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
     * Returns only the internal authentication marker, name and email.
     * Does NOT trigger SSO login — only reads existing sessions.
     * The IdM identifier itself never leaves this method.
     */
    public static function get(): array
    {
        try {
            // Check SSO session passively (no requireAuth trigger)
            if (class_exists('\RRZE\AccessControl\Permissions')) {
                try {
                    $permissions = new \RRZE\AccessControl\Permissions();
                    $loaded = $permissions->simplesamlAuth();
                    $auth = $permissions->simplesamlAuth ?? null;
                    if ($loaded && is_object($auth) && $auth->isAuthenticated()) {
                        $attrs = (array) $auth->getAttributes();

                        $idm = sanitize_text_field(self::firstAttribute($attrs, [
                            'uid',
                            'idm',
                            'eduPersonPrincipalName',
                        ]));

                        $first = sanitize_text_field(self::firstAttribute($attrs, [
                            'givenName',
                            'gn',
                            'displayName',
                            'cn',
                        ]));

                        $last = sanitize_text_field(self::firstAttribute($attrs, [
                            'sn',
                            'surname',
                        ]));

                        $email = sanitize_email(self::firstAttribute($attrs, [
                            'mail',
                            'email',
                            'mailPrimaryAddress',
                        ]));

                        if ($idm) {
                            return [
                                'authenticated' => true,
                                'bookerName' => trim("$first $last"),
                                'bookerEmail' => $email,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // SSO not available — fall through
                }
            }

            // Not authenticated
            return ['authenticated' => false, 'bookerName' => '', 'bookerEmail' => ''];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
