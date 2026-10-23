<?php

namespace RRZE\SSO;

/** Standalone test double when the neighboring RRZE-SSO checkout is unavailable. */
final class Helper
{
    /** Supplies synthetic mapped identities; the installed production helper is preferred by the fixture. */
    public static function getCurrentUserSamlAtts(): array|\WP_Error
    {
        if (!$GLOBALS['forceSso'] || empty($GLOBALS['attributes']['uid'][0])) {
            return new \WP_Error('unavailable', 'synthetic helper unavailable');
        }
        return ['wp_user_login' => $GLOBALS['attributes']['uid'][0]
            . ($GLOBALS['provider'] === 'idp-b' ? '@external' : '')];
    }
}
