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
        return match ($destination) {
            'bookings' => admin_url('admin.php?page=' . BookingsPage::PAGE_SLUG),
            default => admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG . '&tab=access'),
        };
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
        if (!in_array($destination, ['settings', 'bookings', 'access'], true)
            || !in_array($operation, ['prepare', 'enable', 'disable', 'reset', 'login'], true)
            || ($operation !== 'login' && ($destination !== 'settings' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'))
            || ($operation !== 'login' && !preg_match('/^[a-f0-9]{64}$/D', $token))
            || !wp_verify_nonce(self::requestString($request, '_wpnonce'), self::nonceAction($operation, $destination, $token))
            || !match ($destination) {
                'access' => AccessPolicy::owner(),
                'bookings' => AppointmentPermissions::currentUserCanManage(),
                default => current_user_can('manage_options'),
            }) {
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
        $returnUrl = self::destination($destination);
        if ($operation !== 'login') {
            $returnUrl = add_query_arg('sso_updated', $operation, $returnUrl);
        }
        wp_safe_redirect($returnUrl);
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
                if (!BackendSsoAccess::canManageNetworkAccess()) {
                    return AppointmentPermissions::forbidden();
                }
            } else {
                $policy = AccessPolicy::read();
                if ($policy !== null && !AccessPolicy::owner() && !BackendSsoAccess::canManageNetworkAccess()) {
                    return AppointmentPermissions::forbidden();
                }
                if ($operation === 'prepare') {
                    return $this->activateAccessControl();
                }
                if (!in_array($operation, ['enable', 'disable'], true)) {
                    return AppointmentPermissions::forbidden();
                }
                if ($operation === 'enable') {
                    $ready = self::checkResponsiblePeople($policy);
                    if (is_wp_error($ready)) {
                        return $ready;
                    }
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

    /**
     * Requires a readable policy with a current, eligible responsible person before enabling SSO.
     *
     * @param array|\WP_Error|null $policy Site access policy returned by AccessPolicy::read().
     * @return true|\WP_Error Whether the site may enable the additional sign-in requirement.
     */
    private static function checkResponsiblePeople(array|\WP_Error|null $policy): true|\WP_Error
    {
        if (is_wp_error($policy)) {
            return $policy;
        }
        if (is_array($policy) && AccessPolicy::hasEligibleOwner($policy)) {
            return true;
        }
        return new \WP_Error('rrze_appointment_setup_required',
            __('Assign at least one responsible person and save access before enabling SSO.', 'rrze-appointment'), ['status' => 400]);
    }

    /** Activates only the installed, allowed site plugin; WordPress activation itself does not check capabilities. */
    private function activateAccessControl(): true|\WP_Error
    {
        if (BackendSsoAccess::pluginActive(BackendSsoAccess::AC_PLUGIN)) {
            return true;
        }
        if (!self::canActivateAccessControl()) {
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

    /** Checks the installed plugin and activation permissions before offering or performing the action. */
    private static function canActivateAccessControl(): bool
    {
        $plugins = apply_filters('all_plugins', get_plugins());
        return isset($plugins[BackendSsoAccess::AC_PLUGIN])
            && !is_network_only_plugin(BackendSsoAccess::AC_PLUGIN)
            && current_user_can('activate_plugins')
            && current_user_can('activate_plugin', BackendSsoAccess::AC_PLUGIN)
            && apply_filters('rrze_appointment_can_activate_access_control', true, get_current_blog_id());
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

    /** Explains the site-wide sign-in requirement and displays the next available action. */
    public static function render(): void
    {
        $required = BackendSsoAccess::required();
        $updated = self::requestString($_GET, 'sso_updated');
        $notice = match ($updated) {
            'prepare' => BackendSsoAccess::pluginActive(BackendSsoAccess::AC_PLUGIN)
                ? __('RRZE Access Control is active on this website.', 'rrze-appointment') : '',
            'enable' => $required ? __('SSO protection enabled.', 'rrze-appointment') : '',
            'disable', 'reset' => !$required ? __('SSO protection disabled.', 'rrze-appointment') : '',
            default => '',
        };
        ?>
        <section id="rrze-appt-access-sso" class="rrze-appt-settings__card" aria-labelledby="rrze-appointment-sso-heading">
            <h2 id="rrze-appointment-sso-heading"><?php esc_html_e('SSO protection for appointment management', 'rrze-appointment'); ?></h2>
            <?php if ($notice !== '') : ?>
                <div class="notice notice-success inline" role="status"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <p><strong><?php echo esc_html($required ? __('SSO is on', 'rrze-appointment') : __('SSO is off', 'rrze-appointment')); ?></strong></p>
            <p><?php esc_html_e('With single sign-on (SSO), everyone with access to appointment management must also sign in with the SSO account linked to their WordPress account. This setting applies to all authorized people on this website.', 'rrze-appointment'); ?></p>
            <p class="description"><?php esc_html_e('The booking form has its own sign-in setting in the appointment block.', 'rrze-appointment'); ?></p>
            <?php self::renderControls($required); ?>
        </section>
        <?php
    }

    /**
     * Displays the prerequisite or action that applies to the current administrator.
     *
     * @param bool $required Whether the additional sign-in is enabled on this site.
     */
    private static function renderControls(bool $required): void
    {
        $policy = AccessPolicy::read();
        if (!current_user_can('manage_options') || ($policy !== null && !AccessPolicy::owner() && !BackendSsoAccess::canManageNetworkAccess())) {
            echo '<p>' . esc_html__('A responsible administrator or a network administrator can change this setting.', 'rrze-appointment') . '</p>';
            return;
        }
        if (!BackendSsoAccess::pluginActive(BackendSsoAccess::SSO_PLUGIN)) {
            echo '<p>' . esc_html(BackendSsoAccess::canManageNetworkAccess()
                ? __('Check that RRZE SSO is installed and activate it for this website in the Plugins menu.', 'rrze-appointment')
                : __('Ask your network administrator to activate RRZE SSO for this website.', 'rrze-appointment')) . '</p>';
            return;
        }
        if (!BackendSsoAccess::pluginActive(BackendSsoAccess::AC_PLUGIN)) {
            if (self::canActivateAccessControl()) {
                echo '<p>' . esc_html__('Activate RRZE Access Control on this website to connect appointment management to SSO.', 'rrze-appointment') . '</p>';
                self::renderAction('prepare', __('Activate RRZE Access Control', 'rrze-appointment'));
            } else {
                echo '<p>' . esc_html(BackendSsoAccess::canManageNetworkAccess()
                    ? __('Check that RRZE Access Control is installed and can be activated for this website.', 'rrze-appointment')
                    : __('Ask your network administrator to activate RRZE Access Control for this website.', 'rrze-appointment')) . '</p>';
            }
            return;
        }
        $identity = BackendSsoAccess::verifyIdentity();
        if (is_wp_error($identity)) {
            if ($identity->get_error_code() === 'rrze_appointment_sso_required') {
                echo '<p>' . esc_html__('Sign in with your own SSO account to configure access for this website.', 'rrze-appointment') . '</p>';
                echo '<p><a class="button" href="' . esc_url(self::loginUrl('settings')) . '">' . esc_html__('Sign in with SSO', 'rrze-appointment') . '</a></p>';
            } else {
                echo '<p>' . esc_html($identity->get_error_message()) . '</p>';
            }
            return;
        }
        if (!$required) {
            $ready = self::checkResponsiblePeople($policy);
            if (is_wp_error($ready)) {
                echo '<p>' . esc_html($ready->get_error_message()) . '</p>';
                if (!is_wp_error($policy)) {
                    echo '<p><a href="#rrze-appt-access-people">' . esc_html__('Go to access permissions', 'rrze-appointment') . '</a></p>';
                }
                return;
            }
        }
        self::renderAction($required ? 'disable' : 'enable', $required
            ? __('Disable SSO protection', 'rrze-appointment') : __('Enable SSO protection', 'rrze-appointment'));
    }

    /** Renders the independent emergency SSO reset for network administrators in the recovery section. */
    public static function renderRecovery(): void
    {
        if (!BackendSsoAccess::required() || !BackendSsoAccess::canManageNetworkAccess()) {
            return;
        }
        echo '<h3>' . esc_html__('Reset SSO protection', 'rrze-appointment') . '</h3>';
        echo '<p>' . esc_html__('If SSO sign-in is unavailable, turn off the additional sign-in requirement here. Everyone keeps their assigned appointment permissions.', 'rrze-appointment') . '</p>';
        self::renderAction('reset', __('Reset SSO protection', 'rrze-appointment'));
    }

    /** Renders one POST action with a user- and site-bound nonce. */
    private static function renderAction(string $operation, string $label): void
    {
        ?>
        <form class="rrze-appt-settings__actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
            <?php $token = AccessPolicy::token(); ?>
            <input type="hidden" name="policy_token" value="<?php echo esc_attr($token); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(self::nonceAction($operation, 'settings', $token))); ?>">
            <?php submit_button($label, 'secondary', 'rrze-appt-sso-' . $operation); ?>
        </form>
        <?php
    }
}
