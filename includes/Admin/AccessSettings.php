<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Configuration\PluginSettings;
use function RRZE\Appointment\plugin;

defined('ABSPATH') || exit;

/** Provides independent, nonce-protected forms for setup, delegation and support recovery. */
final class AccessSettings
{
    public const ACTION = 'rrze_appointment_access';

    /** Registers authenticated form handling and small presentation styles. */
    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
    }

    /** Loads only the small shared stylesheet on settings and management pages. */
    public function enqueueStyles(string $hook): void
    {
        if (in_array($hook, [BookingsPage::screenHook(), 'settings_page_' . SettingsPage::PAGE_SLUG], true)) {
            wp_enqueue_style('rrze-appointment-access', plugin()->getUrl() . 'assets/css/admin-access.css', [],
                (string) filemtime(plugin()->getPath() . 'assets/css/admin-access.css'));
        }
    }

    /** Builds the fixed local management destination. */
    public static function url(): string
    {
        return add_query_arg('tab', 'access', BackendSsoSettings::destination('bookings'));
    }

    /** Binds a WordPress user/session nonce to the site, operation and observed revision. */
    public static function nonceAction(string $operation, string $token): string
    {
        return self::ACTION . ':' . get_current_blog_id() . ':' . $operation . ':' . $token;
    }

    /** Validates request shape before invoking the common, locked policy mutation. */
    public function handle(): void
    {
        $operation = is_string($_POST['operation'] ?? null) ? wp_unslash($_POST['operation']) : '';
        $token = is_string($_POST['policy_token'] ?? null) ? wp_unslash($_POST['policy_token']) : '';
        $nonce = is_string($_POST['_wpnonce'] ?? null) ? wp_unslash($_POST['_wpnonce']) : '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !in_array($operation, ['setup', 'save', 'recover'], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $token)
            || !wp_verify_nonce($nonce, self::nonceAction($operation, $token))
            || !is_array($_POST['owners'] ?? []) || !is_array($_POST['managers'] ?? [])) {
            wp_die(esc_html__('This access change is not permitted. Reload the page and try again.', 'rrze-appointment'), '', ['response' => 403]);
            return;
        }
        $result = AccessPolicy::change($operation, wp_unslash($_POST['owners'] ?? []), wp_unslash($_POST['managers'] ?? []), $token);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => $result->get_error_data()['status'] ?? 503, 'back_link' => true]);
            return;
        }
        $destination = $operation === 'recover' ? BackendSsoSettings::destination() : self::url();
        wp_safe_redirect(add_query_arg('access_saved', '1', $destination));
        exit;
    }

    /** Lists owners only for authenticated members of the current website. */
    public static function renderOwners(): void
    {
        if (!get_current_user_id() || !is_user_member_of_blog(get_current_user_id(), get_current_blog_id())) {
            return;
        }
        $policy = AccessPolicy::read();
        if ($policy === null) {
            return;
        }
        $labels = [];
        foreach (is_array($policy) ? $policy['owners'] : [] as $id) {
            $user = get_userdata($id);
            if ($user && AccessPolicy::eligible($id)) {
                $labels[] = $user->display_name . ' (' . $user->user_login . ')';
            }
        }
        echo '<p><strong>' . esc_html__('Responsible for access management:', 'rrze-appointment') . '</strong> '
            . esc_html($labels ? implode(', ', $labels) : __('No eligible responsible person is available.', 'rrze-appointment')) . '</p>';
        echo '<p>' . esc_html__('These people manage appointment permissions. If no responsible person is available, contact support to reassign responsibility.', 'rrze-appointment') . '</p>';
    }

    /** Renders status and setup/recovery outside the ordinary WordPress settings form. */
    public static function renderSettings(): void
    {
        echo '<section><h2>' . esc_html__('Access to appointment management', 'rrze-appointment') . '</h2>';
        if (AccessPolicy::restricted()) {
            echo '<p>' . esc_html__('Access is restricted to explicitly authorized users. Other administrators do not have automatic access, even when SSO is disabled.', 'rrze-appointment') . '</p>';
            self::renderOwners();
            if (AccessPolicy::owner()) {
                echo '<p><a class="button" href="' . esc_url(self::url()) . '">' . esc_html__('Manage access', 'rrze-appointment') . '</a></p>';
            }
            if (BackendSsoAccess::canReset()) {
                echo '<details><summary>' . esc_html__('Reassign responsible people as network administrator', 'rrze-appointment') . '</summary>';
                echo '<p>' . esc_html__('Explicitly select the new responsible people. This replaces responsibility but preserves ordinary permissions and the SSO requirement.', 'rrze-appointment') . '</p>';
                self::renderForm('recover');
                echo '</details>';
            }
        } else {
            echo '<p>' . esc_html__('User restrictions have not been set up. All administrators and previously selected users still have access.', 'rrze-appointment') . '</p>';
            $access = BackendSsoAccess::check();
            if (is_wp_error($access)) {
                AccessNotice::render($access, 'settings');
            } elseif (AccessPolicy::eligible(get_current_user_id())) {
                echo '<details><summary>' . esc_html__('Set up user restrictions', 'rrze-appointment') . '</summary>';
                echo '<p>' . esc_html__('Review the future permissions before saving. You become responsible. Other administrators lose automatic access and must be selected explicitly. Restrictions remain in place when SSO is disabled.', 'rrze-appointment') . '</p>';
                self::renderForm('setup');
                echo '</details>';
            }
        }
        echo '</section>';
    }

    /** Renders the owner-only management screen, including a renewed SSO check. */
    public static function renderManagement(): void
    {
        $access = AppointmentPermissions::grantsAccess();
        if (is_wp_error($access)) {
            AccessNotice::render($access);
            return;
        }
        self::renderOwners();
        echo '<p>' . esc_html__('Authorized users can view and cancel appointments. Responsible people can also grant access and appoint other responsible people.', 'rrze-appointment') . '</p>';
        self::renderForm('save');
    }

    /** Renders explicit role selectors for eligible site users, with no network-wide lookup. */
    private static function renderForm(string $operation): void
    {
        $policy = AccessPolicy::read();
        $owners = $operation === 'recover' ? [] : (is_array($policy) ? $policy['owners'] : [get_current_user_id()]);
        $managers = is_array($policy) ? $policy['managers'] : (array) PluginSettings::get('appointment_manager_user_ids');
        $token = AccessPolicy::token();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rrze-appointment-access-form">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
            <input type="hidden" name="policy_token" value="<?php echo esc_attr($token); ?>">
            <?php wp_nonce_field(self::nonceAction($operation, $token)); ?>
            <fieldset><legend><?php esc_html_e('Responsible people', 'rrze-appointment'); ?></legend>
            <?php self::renderChoices('owners', $owners, $operation); ?>
            </fieldset>
            <?php if ($operation !== 'recover') : ?>
                <fieldset><legend><?php esc_html_e('Users allowed to view and cancel appointments', 'rrze-appointment'); ?></legend>
                <?php self::renderChoices('managers', $managers, $operation); ?>
                </fieldset>
                <p class="description"><?php esc_html_e('Responsible people automatically have access and do not need a second selection. At least one eligible responsible person must remain.', 'rrze-appointment'); ?></p>
            <?php endif; ?>
            <?php submit_button($operation === 'setup' ? __('Set up user restrictions', 'rrze-appointment')
                : ($operation === 'recover' ? __('Reassign responsibility', 'rrze-appointment') : __('Save permissions', 'rrze-appointment'))); ?>
        </form>
        <?php
    }

    /** Renders accessible checkboxes and identifies grants whose accounts are no longer eligible. */
    private static function renderChoices(string $field, array $selected, string $operation): void
    {
        foreach (get_users(['blog_id' => get_current_blog_id(), 'orderby' => 'display_name', 'order' => 'ASC']) as $user) {
            $id = (int) $user->ID;
            if (!AccessPolicy::eligible($id)) {
                continue;
            }
            $fixed = $operation === 'setup' && $field === 'owners' && $id === get_current_user_id();
            echo '<label><input type="checkbox" name="' . esc_attr($field) . '[]" value="' . esc_attr((string) $id) . '" '
                . checked($fixed || in_array($id, $selected, true), true, false) . ' ' . disabled($fixed, true, false) . '> '
                . esc_html($user->display_name . ' (' . $user->user_login . ')') . '</label>';
        }
        $ineligible = array_filter($selected, static fn($id) => !AccessPolicy::eligible((int) $id));
        if ($ineligible) {
            echo '<p class="description">' . esc_html__('Some previous selections are no longer eligible and have no access. Saving removes these obsolete selections.', 'rrze-appointment') . '</p>';
        }
    }
}
