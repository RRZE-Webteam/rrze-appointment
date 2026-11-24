<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Configuration\PluginSettings;
use function RRZE\Appointment\plugin;

defined('ABSPATH') || exit;

/** Provides site-local forms for reviewing, assigning and recovering appointment permissions. */
final class AccessSettings
{
    public const ACTION = 'rrze_appointment_access';

    /** Registers authenticated form handling and the shared access stylesheet. */
    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
        add_action('load-index.php', [$this, 'registerDashboardConfirmation']);
    }

    /** Loads the access stylesheet on the settings and appointment screens. */
    public function enqueueStyles(string $hook): void
    {
        if (in_array($hook, [BookingsPage::screenHook(), SettingsPage::screenHook()], true)) {
            wp_enqueue_style('rrze-appointment-access', plugin()->getUrl() . 'assets/css/admin-access.css', [],
                (string) filemtime(plugin()->getPath() . 'assets/css/admin-access.css'));
        }
    }

    /** Returns the appointment management tab accessible to responsible editors. */
    public static function url(): string
    {
        return BackendSsoSettings::destination('access');
    }

    /** Binds each form nonce to its site, operation and observed policy revision. */
    public static function nonceAction(string $operation, string $token): string
    {
        return self::ACTION . ':' . get_current_blog_id() . ':' . $operation . ':' . $token;
    }

    /**
     * Checks the request before processing a preview or confirmed change.
     *
     * @param string $operation Action named in the form.
     * @param string $token Policy revision to which the nonce was bound.
     * @return bool Whether the POST came from a valid form for this site and session.
     */
    private static function validPost(string $operation, string $token): bool
    {
        $nonce = $_POST['_wpnonce'] ?? null;
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_string($nonce)
            && preg_match('/^[a-f0-9]{64}$/D', $token)
            && wp_verify_nonce(wp_unslash($nonce), self::nonceAction($operation, $token));
    }

    /**
     * Decodes the mutually exclusive role selectors or an already-issued owners/managers form.
     * The trailing marker detects truncated PHP requests before a missing row can revoke access.
     *
     * @return array{owners: array, managers: array}|null Null for incomplete or malformed submissions.
     */
    private static function postedGrants(): ?array
    {
        if (!isset($_POST['role_form'])) {
            $owners = $_POST['owners'] ?? [];
            $managers = $_POST['managers'] ?? [];
            return is_array($owners) && is_array($managers)
                ? ['owners' => wp_unslash($owners), 'managers' => wp_unslash($managers)] : null;
        }
        $roles = $_POST['roles'] ?? [];
        if (($_POST['roles_complete'] ?? '') !== '1' || !is_array($roles)) {
            return null;
        }
        $grants = ['owners' => [], 'managers' => []];
        foreach ($roles as $id => $role) {
            if (!is_int($id) || $id < 1 || !in_array($role, ['none', 'owners', 'managers'], true)) {
                return null;
            }
            if ($role !== 'none') {
                $grants[$role][] = $id;
            }
        }
        return $grants;
    }

    /** Applies a confirmed change and returns to a local screen the actor can still open. */
    public function handle(): void
    {
        $operation = $_POST['operation'] ?? null;
        $token = $_POST['policy_token'] ?? null;
        $destination = $_POST['destination'] ?? ($operation === 'save' ? 'bookings' : 'settings');
        $grants = self::postedGrants();
        if (!in_array($operation, ['setup', 'save', 'network_setup', 'network_save', 'recover'], true)
            || !in_array($destination, ['settings', 'bookings'], true)
            || !is_string($token) || !self::validPost($operation, $token) || $grants === null) {
            wp_die(esc_html__('This access change is not permitted. Reload the page and try again.', 'rrze-appointment'), '', ['response' => 403]);
            return;
        }
        $result = AccessPolicy::change($operation, $grants['owners'], $grants['managers'], $token);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => $result->get_error_data()['status'] ?? 503, 'back_link' => true]);
            return;
        }
        $url = BackendSsoSettings::destination('settings');
        if ($destination === 'bookings') {
            $url = AccessPolicy::owner() ? self::url() : (AppointmentPermissions::currentUserCanManage()
                ? BackendSsoSettings::destination('bookings') : admin_url('index.php'));
        }
        wp_safe_redirect(add_query_arg('rrze_appointment_access_saved', $operation, $url));
        exit;
    }

    /** Shows the access-save receipt on the dashboard after a former owner gives up all access. */
    public function registerDashboardConfirmation(): void
    {
        if (($_GET['rrze_appointment_access_saved'] ?? '') === 'save') {
            add_action('admin_notices', [self::class, 'renderConfirmation']);
        }
    }

    /** Lists responsible people to signed-in members of this site who may need to contact them. */
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
                $labels[] = self::personLabel($user);
            }
        }
        echo '<p><strong>' . esc_html__('Responsible people', 'rrze-appointment') . '</strong><br>'
            . esc_html($labels ? implode(', ', $labels) : __('Contact support to appoint a new responsible person.', 'rrze-appointment')) . '</p>';
    }

    /** Shows a translated confirmation after a successful access change. */
    public static function renderConfirmation(): void
    {
        $message = match ($_GET['rrze_appointment_access_saved'] ?? '') {
            'setup', 'network_setup' => __('Access is now restricted to the selected people.', 'rrze-appointment'),
            'save', 'network_save' => __('Access permissions saved.', 'rrze-appointment'),
            'recover' => __('Responsibility reassigned.', 'rrze-appointment'),
            default => '',
        };
        if ($message !== '') {
            echo '<div class="notice notice-success inline" role="status"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Renders the access card and the permitted setup or editing action on this website.
     *
     * @param callable|null $renderAdditionalPeople Optional renderer for the additional people form in standard mode.
     */
    public static function renderSettings(?callable $renderAdditionalPeople = null): void
    {
        $policy = AccessPolicy::read();
        $network = BackendSsoAccess::canManageNetworkAccess();
        echo '<section class="rrze-appt-settings__card" id="rrze-appt-access-people" aria-labelledby="rrze-appt-people-heading">';
        echo '<h2 id="rrze-appt-people-heading">' . esc_html__('Who can manage appointments?', 'rrze-appointment') . '</h2>';
        self::renderConfirmation();
        if (is_wp_error($policy)) {
            echo '<p>' . esc_html($policy->get_error_message()) . '</p></section>';
            return;
        }
        if ($policy === null) {
            if ($renderAdditionalPeople === null) {
                echo '<p>' . esc_html__('Administrators and additional people', 'rrze-appointment') . '</p>';
            } else {
                echo '<p>' . esc_html__('All administrators and any additional people listed here can view and cancel appointments.', 'rrze-appointment') . '</p>';
                echo '<details><summary>' . esc_html__('Additional people with access', 'rrze-appointment') . '</summary>';
                $renderAdditionalPeople();
                echo '</details>';
            }
        } else {
            echo '<p>' . esc_html__('Only the people assigned a role can open appointment management on this website.', 'rrze-appointment') . '</p>';
        }

        $local = $policy === null ? AccessPolicy::eligible(get_current_user_id()) : AccessPolicy::owner();
        if (!$network && !$local) {
            self::renderOwners();
        } else {
            $networkAccess = $network ? BackendSsoAccess::verifyIdentity() : null;
            $setupInProgress = $network && $policy === null && in_array($_POST['access_stage'] ?? '', ['edit', 'review'], true);
            $onBehalf = !$local || ($setupInProgress ? ($_POST['operation'] ?? '') === 'network_setup'
                : ($networkAccess === true && $policy === null));
            $access = $onBehalf ? $networkAccess : BackendSsoAccess::check();
            if (is_wp_error($access)) {
                echo '<p>' . esc_html($access->get_error_message()) . ' '
                    . '<a href="#rrze-appointment-sso-heading">' . esc_html__('Go to SSO settings', 'rrze-appointment') . '</a></p>';
            } else {
                $operation = $policy === null ? 'setup' : 'save';
                if ($policy === null) {
                    $open = isset($_POST['access_stage']) ? ' open' : '';
                    echo '<details' . $open . '><summary>' . esc_html__('Restrict access to selected people', 'rrze-appointment') . '</summary>';
                }
                self::renderForm(($onBehalf ? 'network_' : '') . $operation, 'settings', $policy);
                if ($policy === null) {
                    echo '</details>';
                }
            }
        }
        echo '</section>';
    }

    /** Displays the network-only recovery form for reassigning owners of an existing policy. */
    public static function renderRecovery(): void
    {
        if (!BackendSsoAccess::canManageNetworkAccess() || !AccessPolicy::restricted()) {
            return;
        }
        $policy = AccessPolicy::read();
        echo '<h3>' . esc_html__('Reassign responsible people', 'rrze-appointment') . '</h3>';
        echo '<p>' . esc_html(is_wp_error($policy)
            ? __('The access list cannot be read. Select new responsible people to rebuild it. Other people will need new grants.', 'rrze-appointment')
            : __('Use this if the responsible people are unavailable. Select their successors. Other grants and the SSO requirement are kept.', 'rrze-appointment')) . '</p>';
        self::renderForm('recover', 'settings', null);
    }

    /** Displays the same role editor in the appointment menu for responsible people. */
    public static function renderManagement(): void
    {
        $access = AppointmentPermissions::grantsAccess();
        if (is_wp_error($access)) {
            AccessNotice::render($access, 'access');
            return;
        }
        $policy = AccessPolicy::read();
        if (is_array($policy)) {
            self::renderConfirmation();
            self::renderForm('save', 'bookings', $policy);
        }
    }

    /**
     * Returns the account label used in both selection and review.
     *
     * @param object $user Site member with a display name and WordPress login.
     */
    public static function personLabel(object $user): string
    {
        return $user->display_name === $user->user_login
            ? $user->user_login : $user->display_name . ' (' . $user->user_login . ')';
    }

    /**
     * Renders a single native role control per eligible person and a review step before first setup.
     *
     * @param string $operation Permitted setup, update or recovery operation.
     * @param string $destination Local screen to return to after saving.
     * @param array|null $policy Valid stored grants, or null for setup and emergency recovery.
     */
    private static function renderForm(string $operation, string $destination, ?array $policy): void
    {
        $setup = in_array($operation, ['setup', 'network_setup'], true);
        $recover = $operation === 'recover';
        $token = AccessPolicy::token();
        $users = get_users(['blog_id' => get_current_blog_id(), 'orderby' => 'display_name', 'order' => 'ASC']);
        $legacy = array_map('absint', (array) PluginSettings::get('appointment_manager_user_ids'));
        $owners = $policy['owners'] ?? ($operation === 'setup' ? [get_current_user_id()] : []);
        $managers = $policy['managers'] ?? ($setup ? $legacy : []);
        $eligible = array_filter($users, static fn($user): bool => AccessPolicy::eligible((int) $user->ID));
        $stage = $setup && ($_POST['operation'] ?? '') === $operation ? ($_POST['access_stage'] ?? '') : '';
        if (in_array($stage, ['review', 'edit'], true)) {
            $grants = isset($_POST['role_form']) ? self::postedGrants() : null;
            $ids = array_map(static fn($user): int => (int) $user->ID, $eligible);
            if (!self::validPost($operation, $token) || $grants === null
                || array_diff(array_merge($grants['owners'], $grants['managers']), $ids)) {
                echo '<p role="alert">' . esc_html__('This selection could not be reviewed. Check the current permissions and try again.', 'rrze-appointment') . '</p>';
            } else {
                $owners = $grants['owners'];
                $managers = $grants['managers'];
                if ($operation === 'setup' && !in_array(get_current_user_id(), $owners, true)) {
                    $owners[] = get_current_user_id();
                }
                if (!$owners) {
                    echo '<p role="alert">' . esc_html__('Select at least one responsible person to continue.', 'rrze-appointment') . '</p>';
                } elseif ($stage === 'review') {
                    self::renderPreview($operation, $token, $users, $owners, $managers, $legacy);
                    return;
                }
            }
        }
        if ($setup) {
            echo '<p>' . esc_html__('Choose who will keep access. You can review the list before applying this restriction.', 'rrze-appointment') . '</p>';
            if ($operation === 'setup') {
                echo '<p>' . esc_html__('You will be a responsible person for this website.', 'rrze-appointment') . '</p>';
            }
        }
        if (!$recover) {
            echo '<p>' . esc_html__('Responsible people can view and cancel appointments and manage permissions. People with “View and cancel” can work with bookings. Keep at least one responsible person.', 'rrze-appointment') . '</p>';
        }
        ?>
        <form method="post" class="rrze-appointment-access-form" action="<?php echo esc_url($setup ? BackendSsoSettings::destination('settings') . '#rrze-appt-access-people' : admin_url('admin-post.php')); ?>">
            <?php self::formFields($operation, $token, $destination); ?>
            <?php if ($setup) : ?><input type="hidden" name="access_stage" value="review"><?php endif; ?>
            <fieldset data-access-directory>
                <legend><?php echo esc_html($recover ? __('New responsible people', 'rrze-appointment') : __('Roles on this website', 'rrze-appointment')); ?></legend>
                <p class="description"><?php esc_html_e('You can choose site members with editor or administrator permissions.', 'rrze-appointment'); ?></p>
                <div data-access-filter hidden class="rrze-appt-settings__filter">
                    <label><?php esc_html_e('Find a person', 'rrze-appointment'); ?><input type="search"></label>
                </div>
                <div class="rrze-appt-settings__roles">
                <?php foreach ($eligible as $user) :
                    $id = (int) $user->ID;
                    $role = in_array($id, $owners, true) ? 'owners' : (in_array($id, $managers, true) ? 'managers' : 'none');
                    $fixed = $operation === 'setup' && $id === get_current_user_id();
                    $field = 'rrze-appt-' . $operation . '-' . $id;
                    ?>
                    <div data-access-person class="rrze-appt-settings__role">
                        <?php if ($recover) : ?>
                            <label><input type="checkbox" name="roles[<?php echo esc_attr((string) $id); ?>]" value="owners"> <?php echo esc_html(self::personLabel($user)); ?></label>
                        <?php else : ?>
                            <label for="<?php echo esc_attr($field); ?>"><?php echo esc_html(self::personLabel($user)); ?></label>
                            <select id="<?php echo esc_attr($field); ?>" name="roles[<?php echo esc_attr((string) $id); ?>]" <?php disabled($fixed); ?>>
                                <?php if ($role === 'none') : ?>
                                    <?php // A selected disabled option omits untouched accounts from native form submissions. ?>
                                    <option value="" selected disabled hidden><?php esc_html_e('No access', 'rrze-appointment'); ?></option>
                                <?php endif; ?>
                                <option value="none"><?php esc_html_e('No access', 'rrze-appointment'); ?></option>
                                <option value="managers" <?php selected($role, 'managers'); ?>><?php esc_html_e('View and cancel', 'rrze-appointment'); ?></option>
                                <option value="owners" <?php selected($role, 'owners'); ?>><?php esc_html_e('Responsible', 'rrze-appointment'); ?></option>
                            </select>
                            <?php if ($fixed) : ?><input type="hidden" name="roles[<?php echo esc_attr((string) $id); ?>]" value="owners"><?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php if ($policy !== null && array_diff(array_merge($owners, $managers), array_map(static fn($user): int => (int) $user->ID, $eligible))) : ?>
                    <p class="description"><?php esc_html_e('Some previous selections are no longer eligible and have no access. Saving removes these obsolete selections.', 'rrze-appointment'); ?></p>
                <?php endif; ?>
                <?php if (!$eligible) : ?><p><?php esc_html_e('This website has no eligible members.', 'rrze-appointment'); ?></p><?php endif; ?>
                <p data-access-empty role="status" hidden><?php esc_html_e('No matching people.', 'rrze-appointment'); ?></p>
            </fieldset>
            <input type="hidden" name="roles_complete" value="1">
            <?php if ($setup) : ?>
                <button type="submit" class="button button-primary" <?php disabled(!$eligible); ?>><?php esc_html_e('Review access', 'rrze-appointment'); ?></button>
            <?php elseif ($eligible) : ?>
                <?php submit_button($recover ? __('Reassign responsibility', 'rrze-appointment') : __('Save permissions', 'rrze-appointment'), 'primary', 'rrze-appt-' . $operation); ?>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Outputs the site-bound fields shared by editing and confirmation forms.
     *
     * @param string $operation Access change to authorize.
     * @param string $token Revision of the reviewed grants.
     * @param string $destination Fixed return screen for this form.
     */
    private static function formFields(string $operation, string $token, string $destination): void
    {
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
        <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
        <input type="hidden" name="policy_token" value="<?php echo esc_attr($token); ?>">
        <input type="hidden" name="destination" value="<?php echo esc_attr($destination); ?>">
        <input type="hidden" name="role_form" value="1">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(self::nonceAction($operation, $token))); ?>">
        <?php
    }

    /**
     * Shows the proposed roles and everyone on this site who loses existing access.
     *
     * @param string $operation Local or network setup operation being confirmed.
     * @param string $token Revision of the reviewed grants.
     * @param object[] $users Current site members in display order.
     * @param int[] $owners Proposed responsible people.
     * @param int[] $managers Proposed people who may view and cancel appointments.
     * @param int[] $legacy Previously delegated users with appointment access.
     */
    private static function renderPreview(string $operation, string $token, array $users, array $owners, array $managers, array $legacy): void
    {
        $groups = [
            'owners' => ['label' => __('Responsible people', 'rrze-appointment'), 'names' => []],
            'managers' => ['label' => __('View and cancel', 'rrze-appointment'), 'names' => []],
            'removed' => ['label' => __('People who will lose access', 'rrze-appointment'), 'names' => []],
        ];
        foreach ($users as $user) {
            $id = (int) $user->ID;
            if (in_array($id, $owners, true)) {
                $groups['owners']['names'][] = self::personLabel($user);
            } elseif (in_array($id, $managers, true)) {
                $groups['managers']['names'][] = self::personLabel($user);
            } elseif (user_can($user, 'manage_options') || in_array($id, $legacy, true)) {
                $groups['removed']['names'][] = self::personLabel($user);
            }
        }
        ?>
        <div class="rrze-appt-settings__preview">
            <h3><?php esc_html_e('Review access before applying', 'rrze-appointment'); ?></h3>
            <p><?php esc_html_e('Only the people assigned a role below will have access, including administrators. These restrictions also apply when SSO is off.', 'rrze-appointment'); ?></p>
            <dl>
            <?php foreach ($groups as $group) : ?>
                <dt><strong><?php echo esc_html($group['label']); ?></strong></dt>
                <dd><?php echo esc_html($group['names'] ? implode(', ', $group['names']) : __('No one', 'rrze-appointment')); ?></dd>
            <?php endforeach; ?>
            </dl>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php self::formFields($operation, $token, 'settings'); ?>
                <?php foreach (['owners' => $owners, 'managers' => $managers] as $role => $ids) : ?>
                    <?php foreach ($ids as $id) : ?>
                        <input type="hidden" name="roles[<?php echo esc_attr((string) $id); ?>]" value="<?php echo esc_attr($role); ?>">
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <input type="hidden" name="roles_complete" value="1">
                <div class="rrze-appt-settings__actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Apply access restrictions', 'rrze-appointment'); ?></button>
                    <button type="submit" name="access_stage" value="edit" formaction="<?php echo esc_url(BackendSsoSettings::destination('settings') . '#rrze-appt-access-people'); ?>" class="button"><?php esc_html_e('Change selection', 'rrze-appointment'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }
}
