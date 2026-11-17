<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Configuration\PluginSettings;
use RRZE\Appointment\Storage\OptionDatabase;

defined('ABSPATH') || exit;

/** Handles explicit, nonce-protected setup, login and recovery on the current site. */
final class BackendSsoSettings
{
    public const ACTION = 'rrze_appointment_backend_sso';

    /** Registers authenticated actions and protects delegated-manager changes. */
    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_filter('pre_update_option_' . PluginSettings::OPTION_NAME, [$this, 'protectManagers'], 10, 2);
    }

    /** Returns a fixed local destination; request parameters never supply a return URL. */
    public static function destination(string $destination = 'settings'): string
    {
        return $destination === 'bookings'
            ? admin_url('admin.php?page=' . BookingsPage::PAGE_SLUG)
            : admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG);
    }

    /** Builds the explicit login link, bound to the current user, site and destination. */
    public static function loginUrl(string $destination = 'bookings'): string
    {
        return wp_nonce_url(add_query_arg([
            'action' => self::ACTION, 'operation' => 'login', 'destination' => $destination,
        ], admin_url('admin-post.php')), self::nonceAction('login', $destination));
    }

    /** Binds setup and login nonces to the current subsite. */
    public static function nonceAction(string $operation, string $destination = 'settings', string $token = ''): string
    {
        return self::ACTION . ':' . get_current_blog_id() . ':' . $operation . ':' . $destination . ($token !== '' ? ':' . $token : '');
    }

    /** Rejects malformed input instead of coercing nested request values. */
    private static function requestString(array $request, string $name): string
    {
        return is_string($request[$name] ?? null) ? wp_unslash($request[$name]) : '';
    }

    /** Dispatches explicit setup requests without exposing underlying integration failures. */
    public function handle(): void
    {
        $request = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
        $operation = self::requestString($request, 'operation');
        $destination = self::requestString($request, 'destination') ?: 'settings';
        $token = $operation === 'login' ? '' : self::requestString($request, 'policy_token');
        if (!in_array($destination, ['settings', 'bookings'], true)
            || !in_array($operation, ['prepare', 'enable', 'disable', 'reset', 'login'], true)
            || ($operation !== 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
            || ($operation !== 'login' && !preg_match('/^[a-f0-9]{64}$/D', $token))
            || !wp_verify_nonce(self::requestString($request, '_wpnonce'), self::nonceAction($operation, $destination, $token))
            || ($destination === 'bookings' && $operation === 'login'
                ? !AppointmentPermissions::currentUserCanManage() : !current_user_can('manage_options'))) {
            wp_die(esc_html__('This SSO action is not permitted. Reload the page and try again.', 'rrze-appointment'), '', ['response' => 403]);
            return;
        }
        try {
            if ($operation === 'login') {
                $auth = BackendSsoAccess::authClient();
                $result = $auth;
                if (!is_wp_error($auth)) {
                    $auth->requireAuth(['ReturnTo' => self::destination($destination), 'KeepPost' => false]);
                    $result = BackendSsoAccess::verifyIdentity();
                }
            } else {
                $result = $this->perform($operation, $token);
            }
        } catch (\Throwable $exception) {
            $result = BackendSsoAccess::unavailable();
        }
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => $result->get_error_data()['status'] ?? 503,
                'back_link' => true]);
            return;
        }
        wp_safe_redirect(self::destination($destination));
        exit;
    }

    /** Applies one authorized setup operation; every policy write verifies the current state again. */
    public function perform(string $operation, ?string $token = null): true|\WP_Error
    {
        if (!current_user_can('manage_options')) {
            return AppointmentPermissions::forbidden();
        }
        return AccessPolicy::locked(function (OptionDatabase $database) use ($operation, $token) {
            if ($token !== null && !hash_equals(AccessPolicy::token(), $token)) {
                return AccessPolicy::conflict();
            }
            if ($operation === 'reset') {
                if (!BackendSsoAccess::canReset()) {
                    return AppointmentPermissions::forbidden();
                }
            } else {
                if (AccessPolicy::restricted() && !AccessPolicy::owner()
                    && !($operation === 'prepare' && BackendSsoAccess::canReset())) {
                    return AppointmentPermissions::forbidden();
                }
                if ($operation === 'prepare') {
                    return $this->activateAccessControl();
                }
                if (!in_array($operation, ['enable', 'disable'], true)) {
                    return AppointmentPermissions::forbidden();
                }
                if ($operation === 'enable' && !AccessPolicy::restricted()) {
                    return new \WP_Error('rrze_appointment_setup_required',
                        __('Set up user restrictions and confirm the responsible people before enabling SSO protection.', 'rrze-appointment'), ['status' => 400]);
                }
                $identity = BackendSsoAccess::verifyIdentity();
                if (is_wp_error($identity)) {
                    return $identity;
                }
            }
            $required = $operation === 'enable';
            $database->assertOwnership();
            update_option(BackendSsoAccess::OPTION_NAME, $required ? '1' : '0', false);
            return BackendSsoAccess::required() === $required ? true : BackendSsoAccess::unavailable();
        });
    }

    /** Activates only the installed, allowed site plugin; WordPress activation itself does not check capabilities. */
    private function activateAccessControl(): true|\WP_Error
    {
        if (BackendSsoAccess::pluginActive(BackendSsoAccess::AC_PLUGIN)) {
            return true;
        }
        $plugins = apply_filters('all_plugins', get_plugins());
        if (!isset($plugins[BackendSsoAccess::AC_PLUGIN])
            || is_network_only_plugin(BackendSsoAccess::AC_PLUGIN)
            || !current_user_can('activate_plugins')
            || !current_user_can('activate_plugin', BackendSsoAccess::AC_PLUGIN)
            || !apply_filters('rrze_appointment_can_activate_access_control', true, get_current_blog_id())) {
            return new \WP_Error('rrze_appointment_sso_activation_forbidden',
                __('RRZE-AC is missing or cannot be activated on this website. Contact your network administrator.', 'rrze-appointment'), ['status' => 403]);
        }
        $result = activate_plugin(BackendSsoAccess::AC_PLUGIN, '', false, false);
        if (is_wp_error($result) || !BackendSsoAccess::pluginActive(BackendSsoAccess::AC_PLUGIN)) {
            return new \WP_Error('rrze_appointment_sso_activation_failed',
                __('RRZE-AC could not be activated. Contact your network administrator.', 'rrze-appointment'), ['status' => 503]);
        }
        return true;
    }

    /**
     * Preserves the manager list if a protected site receives an unauthorized settings update.
     *
     * @param mixed $next Proposed settings.
     * @param mixed $previous Persisted settings.
     * @return mixed Settings with the protected field preserved when necessary.
     */
    public function protectManagers($next, $previous)
    {
        if (AccessPolicy::restricted()) {
            if (!is_array($next)) {
                return $previous;
            }
            $next['appointment_manager_user_ids'] = is_array($previous) ? ($previous['appointment_manager_user_ids'] ?? []) : [];
            return $next;
        }
        if (!BackendSsoAccess::required()) {
            return $next;
        }
        if (!is_array($next)) {
            return $previous;
        }
        $previousManagers = is_array($previous) ? ($previous['appointment_manager_user_ids'] ?? []) : [];
        if (($next['appointment_manager_user_ids'] ?? []) !== $previousManagers
            && (!current_user_can('manage_options') || is_wp_error(BackendSsoAccess::verifyIdentity()))) {
            $next['appointment_manager_user_ids'] = $previousManagers;
            add_settings_error(PluginSettings::OPTION_NAME, 'rrze_appointment_sso_managers',
                __('The appointment managers were not changed. Sign in with the matching SSO account first.', 'rrze-appointment'));
        }
        return $next;
    }

    /** Renders the independent setup forms outside the ordinary settings form. */
    public static function render(): void
    {
        $required = BackendSsoAccess::required();
        $active = BackendSsoAccess::pluginActive(BackendSsoAccess::AC_PLUGIN);
        $identity = $active ? BackendSsoAccess::verifyIdentity() : BackendSsoAccess::unavailable();
        $canChange = !AccessPolicy::restricted() || AccessPolicy::owner();
        ?>
        <section aria-labelledby="rrze-appointment-sso-heading">
            <h2 id="rrze-appointment-sso-heading"><?php esc_html_e('SSO protection for appointment management', 'rrze-appointment'); ?></h2>
            <p><?php echo esc_html($required
                ? __('SSO protection is enabled. Appointment access requires the matching SSO account.', 'rrze-appointment')
                : __('SSO protection is disabled. Existing WordPress permissions apply.', 'rrze-appointment')); ?></p>
            <p><?php esc_html_e('Public bookings remain available without SSO. Setup activates an available RRZE-AC plugin on this website, then verifies your SSO account.', 'rrze-appointment'); ?></p>
            <?php if (!$canChange && !BackendSsoAccess::canReset()) : ?>
                <p><?php esc_html_e('Only responsible administrators can change SSO protection.', 'rrze-appointment'); ?></p>
            <?php elseif (!$active) : ?>
                <?php self::renderAction('prepare', __('Set up SSO protection / activate RRZE-AC', 'rrze-appointment')); ?>
            <?php elseif (is_wp_error($identity)) : ?>
                <p><?php echo esc_html($identity->get_error_message()); ?></p>
                <?php if ($identity->get_error_code() === 'rrze_appointment_sso_required') : ?>
                    <p><a class="button" href="<?php echo esc_url(self::loginUrl('settings')); ?>"><?php esc_html_e('Sign in with SSO', 'rrze-appointment'); ?></a></p>
                <?php endif; ?>
            <?php elseif (!$required && !AccessPolicy::restricted()) : ?>
                <p><?php esc_html_e('Set up user restrictions and confirm the responsible people before enabling SSO protection.', 'rrze-appointment'); ?></p>
            <?php elseif ($canChange) : ?>
                <?php self::renderAction($required ? 'disable' : 'enable', $required
                    ? __('Disable SSO protection', 'rrze-appointment') : __('Enable SSO protection', 'rrze-appointment')); ?>
            <?php endif; ?>
            <?php if ($required && BackendSsoAccess::canReset()) : ?>
                <p><?php esc_html_e('Emergency reset removes only the SSO requirement on this website. User restrictions and responsible people remain unchanged.', 'rrze-appointment'); ?></p>
                <?php self::renderAction('reset', __('Reset SSO protection as network administrator', 'rrze-appointment')); ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /** Renders one POST action with a user- and site-bound nonce. */
    private static function renderAction(string $operation, string $label): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
            <?php $token = AccessPolicy::token(); ?>
            <input type="hidden" name="policy_token" value="<?php echo esc_attr($token); ?>">
            <?php wp_nonce_field(self::nonceAction($operation, 'settings', $token)); ?>
            <?php submit_button($label, 'secondary'); ?>
        </form>
        <?php
    }
}
