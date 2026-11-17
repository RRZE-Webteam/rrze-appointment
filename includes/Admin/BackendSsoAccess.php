<?php

namespace RRZE\Appointment\Admin;

defined('ABSPATH') || exit;

/** Checks the current site's additional SSO requirement without starting a login. */
final class BackendSsoAccess
{
    public const OPTION_NAME = 'rrze_appointment_backend_sso_required';
    public const AC_PLUGIN = 'rrze-ac/rrze-ac.php';
    public const SSO_PLUGIN = 'rrze-sso/rrze-sso.php';

    /** Returns the opt-in policy for the current site without caching it across sites. */
    public static function required(): bool
    {
        return (bool) get_option(self::OPTION_NAME, false);
    }

    /** Checks the additional requirement; WordPress authorization remains mandatory separately. */
    public static function check(): true|\WP_Error
    {
        return self::required() ? self::verifyIdentity() : true;
    }

    /** Loads WordPress plugin functions and checks site or network activation. */
    public static function pluginActive(string $plugin): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active($plugin);
    }

    /** Returns a usable AC authentication client or a neutral integration error. */
    public static function authClient(): object
    {
        try {
            if (!self::pluginActive(self::AC_PLUGIN) || !self::pluginActive(self::SSO_PLUGIN)
                || !class_exists('RRZE\\AccessControl\\Permissions')
                || !is_callable(['RRZE\\SSO\\Helper', 'getCurrentUserSamlAtts'])
                || !function_exists('RRZE\\SSO\\simpleSAML')) {
                return self::unavailable();
            }
            $permissions = new \RRZE\AccessControl\Permissions();
            if (!$permissions->simplesamlAuth()) {
                return self::unavailable();
            }
            $auth = $permissions->simplesamlAuth;
            if (!is_object($auth) || !method_exists($auth, 'isAuthenticated')
                || !method_exists($auth, 'requireAuth') || !method_exists($auth, 'getAuthData')) {
                return self::unavailable();
            }
            return $auth;
        } catch (\Throwable $exception) {
            return self::unavailable();
        }
    }

    /** Verifies the active SSO identity against the current WordPress account, including its IdP. */
    public static function verifyIdentity(): true|\WP_Error
    {
        try {
            $auth = self::authClient();
            if (is_wp_error($auth)) {
                return $auth;
            }
            if (!$auth->isAuthenticated()) {
                return new \WP_Error('rrze_appointment_sso_required',
                    __('Sign in with SSO to manage appointments.', 'rrze-appointment'), ['status' => 401]);
            }
            $provider = $auth->getAuthData('saml:sp:IdP');
            $providers = \RRZE\SSO\simpleSAML()->getIdentityProviders();
            if (!is_string($provider) || $provider === '' || !is_array($providers)) {
                return self::unavailable();
            }
            $matchingProviders = 0;
            foreach (array_keys($providers) as $candidate) {
                if (is_string($candidate) && sanitize_title($candidate) === sanitize_title($provider)) {
                    $matchingProviders++;
                }
            }
            if ($matchingProviders !== 1 || !array_key_exists($provider, $providers)) {
                return self::unavailable();
            }
            $attributes = \RRZE\SSO\Helper::getCurrentUserSamlAtts();
            if (is_wp_error($attributes) || !is_array($attributes)
                || !is_string($attributes['wp_user_login'] ?? null) || $attributes['wp_user_login'] === '') {
                return self::unavailable();
            }
            $login = $attributes['wp_user_login'];
            $user = get_user_by('login', $login);
            if (!$user || (int) $user->ID !== get_current_user_id() || $user->user_login !== $login) {
                return new \WP_Error('rrze_appointment_sso_identity_mismatch',
                    __('The SSO account does not match your WordPress account. Sign in with the matching account.', 'rrze-appointment'),
                    ['status' => 403]);
            }
            return true;
        } catch (\Throwable $exception) {
            return self::unavailable();
        }
    }

    /** Returns a safe failure without dependency messages or authentication attributes. */
    public static function unavailable(): \WP_Error
    {
        return new \WP_Error('rrze_appointment_sso_unavailable',
            __('SSO protection is unavailable. Contact your network administrator.', 'rrze-appointment'), ['status' => 503]);
    }

    /** Restricts emergency policy resets to network administrators; it does not grant data access. */
    public static function canReset(): bool
    {
        return is_multisite() && is_super_admin() && current_user_can('manage_network_options');
    }
}
