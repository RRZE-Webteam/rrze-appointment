<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Configuration\PluginSettings;
use function RRZE\Appointment\plugin;

defined('ABSPATH') || exit;

/** Provides site-local forms for reviewing, assigning and recovering appointment permissions. */
final class AccessSettings
{
    public const ACTION = 'rrze_appointment_access';

    /** @var array{operation: string, grants: array}|null Selection to redisplay for this request after a missing-owner error. */
    private static ?array $invalidSelection = null;

    /** Registers authenticated form handling and the shared access stylesheet. */
    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_action('load-' . SettingsPage::screenHook(), [$this, 'handleSettingsPost']);
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

    /** Returns the site settings tab where administrators manage appointment roles. */
    public static function url(): string
    {
        return BackendSsoSettings::destination('settings');
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
     * @return array{owners: array, access_owners: array, managers: array}|null Null for incomplete or malformed submissions.
     */
    private static function postedGrants(): ?array
    {
        if (!isset($_POST['role_form'])) {
            $owners = $_POST['owners'] ?? [];
            $managers = $_POST['managers'] ?? [];
            return is_array($owners) && is_array($managers)
                ? ['owners' => wp_unslash($owners), 'access_owners' => [], 'managers' => wp_unslash($managers)] : null;
        }
        $roles = $_POST['roles'] ?? [];
        if (!in_array($_POST['role_form'], ['1', '2'], true) || ($_POST['roles_complete'] ?? '') !== '1' || !is_array($roles)) {
            return null;
        }
        $allowed = $_POST['role_form'] === '2' ? ['none', 'owners', 'access_owners', 'managers'] : ['none', 'owners', 'managers'];
        $grants = ['owners' => [], 'access_owners' => [], 'managers' => []];
        foreach ($roles as $id => $role) {
            if (!is_int($id) || $id < 1 || !in_array($role, $allowed, true)) {
                return null;
            }
            if ($role !== 'none') {
                $grants[$role][] = $id;
            }
        }
        return $grants;
    }

    /** Handles confirmed writes before WordPress outputs the settings page; review/edit stages only render. */
    public function handleSettingsPost(): void
    {
        if (($_GET['tab'] ?? '') === 'access' && ($_POST['action'] ?? '') === self::ACTION
            && !isset($_POST['access_stage'])) {
            $this->submit(true);
        }
    }

    /** Accepts already-open forms that still post to the original admin-post endpoint. */
    public function handle(): void
    {
        $this->submit(false);
    }

    /**
     * Saves a validated selection or returns it to the current settings page to correct a missing owner.
     *
     * @param bool $onSettings Whether WordPress will render the access settings after this callback.
     */
    private function submit(bool $onSettings): void
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
        $accessOwners = ($_POST['role_form'] ?? '') === '2' ? $grants['access_owners'] : null;
        $result = AccessPolicy::change($operation, $grants['owners'], $grants['managers'], $token, $accessOwners);
        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'rrze_appointment_owner_required') {
                if (!$onSettings) {
                    wp_safe_redirect(self::url(), 307);
                    exit;
                }
                self::$invalidSelection = ['operation' => $operation, 'grants' => $grants];
                return;
            }
            wp_die(esc_html($result->get_error_message()), '', ['response' => $result->get_error_data()['status'] ?? 503, 'back_link' => true]);
            return;
        }
        $url = current_user_can('manage_options') ? self::url()
            : (AppointmentPermissions::currentUserCanManage() ? BackendSsoSettings::destination('bookings') : admin_url('index.php'));
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
        foreach (is_array($policy) ? array_merge($policy['owners'], $policy['access_owners']) : [] as $id) {
            $user = get_userdata($id);
            if ($user && AccessPolicy::eligibleOwner($id)) {
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
        echo '<h2 id="rrze-appt-people-heading">' . esc_html__('Roles for bookings and permissions', 'rrze-appointment') . '</h2>';
        self::renderConfirmation();
        if (is_wp_error($policy)) {
            echo '<p>' . esc_html($policy->get_error_message()) . '</p></section>';
            return;
        }
        $orphaned = is_array($policy) && !AccessPolicy::hasEligibleOwner($policy);
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
            echo '<p>' . esc_html__('Booking access and responsibility for permissions are assigned separately on this website.', 'rrze-appointment') . '</p>';
            if ($orphaned) {
                echo '<p>' . esc_html__('No website administrator is currently responsible.', 'rrze-appointment');
                if (AccessPolicy::canRecover()) {
                    echo ' <a href="#rrze-appt-settings-recovery">' . esc_html__('Reassign responsibility', 'rrze-appointment') . '</a>';
                } elseif (is_multisite()) {
                    echo ' ' . esc_html__('Ask your network administrator to reassign responsibility.', 'rrze-appointment');
                }
                echo '</p>';
            }
        }

        $local = $policy === null ? AccessPolicy::eligibleOwner(get_current_user_id()) : AccessPolicy::owner();
        if (!$network && !$local) {
            if (!$orphaned) {
                self::renderOwners();
            }
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
                    $open = isset($_POST['access_stage']) || self::$invalidSelection !== null ? ' open' : '';
                    echo '<details' . $open . '><summary>' . esc_html__('Restrict access to selected people', 'rrze-appointment') . '</summary>';
                }
                self::renderForm(($onBehalf ? 'network_' : '') . $operation, $policy);
                if ($policy === null) {
                    echo '</details>';
                }
            }
        }
        echo '</section>';
    }

    /** Returns whether the recovery panel contains a submitted selection that needs correction. */
    public static function recoveryOpen(): bool
    {
        return (self::$invalidSelection['operation'] ?? '') === 'recover';
    }

    /** Displays the recovery form to an authorized administrator of this network or orphaned single site. */
    public static function renderRecovery(): void
    {
        if (!AccessPolicy::canRecover()) {
            return;
        }
        $policy = AccessPolicy::read();
        echo '<h3>' . esc_html__('Reassign responsible people', 'rrze-appointment') . '</h3>';
        if (!is_multisite()) {
            $access = BackendSsoAccess::check();
            if (is_wp_error($access)) {
                AccessNotice::render($access, 'settings');
                return;
            }
        }
        echo '<p>' . esc_html(is_wp_error($policy)
            ? __('The access list cannot be read. Select new responsible people to rebuild it. Other people will need new grants.', 'rrze-appointment')
            : __('Use this if the responsible people are unavailable. Select their successors. Other grants and the SSO requirement are kept.', 'rrze-appointment')) . '</p>';
        self::renderForm('recover', is_array($policy) ? $policy : null);
    }

    /** Points callers of the former management tab to the administrator's role settings. */
    public static function renderManagement(): void
    {
        $access = AppointmentPermissions::grantsAccess();
        if (is_wp_error($access)) {
            AccessNotice::render($access, 'access');
            return;
        }
        echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Manage access & SSO', 'rrze-appointment') . '</a></p>';
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
     * Labels for the mutually exclusive roles, shared by the person directory and setup review.
     *
     * @return array<string, string> Labels keyed by the submitted role value.
     */
    private static function roleLabels(): array
    {
        return [
            'owners' => __('Responsible with booking access', 'rrze-appointment'),
            'access_owners' => __('Responsible without booking access', 'rrze-appointment'),
            'managers' => __('View and cancel', 'rrze-appointment'),
        ];
    }

    /**
     * Renders one role per eligible account and previews the initial restriction before saving.
     *
     * @param string $operation Permitted setup, update or recovery operation.
     * @param array|null $policy Readable site policy or null for initial setup and damaged-policy recovery.
     */
    private static function renderForm(string $operation, ?array $policy): void
    {
        $setup = in_array($operation, ['setup', 'network_setup'], true);
        $recover = $operation === 'recover';
        $actor = get_current_user_id();
        $token = AccessPolicy::token();
        $users = get_users(['blog_id' => get_current_blog_id(), 'orderby' => 'display_name', 'order' => 'ASC']);
        $eligible = array_filter($users, static fn($user): bool => AccessPolicy::eligible((int) $user->ID));
        $administrators = array_filter($eligible, static fn($user): bool => AccessPolicy::eligibleOwner((int) $user->ID));
        $adminIds = array_map('intval', array_column($administrators, 'ID'));
        $people = $recover ? $administrators : $eligible;
        $eligibleIds = array_map('intval', array_column($people, 'ID'));
        $legacy = array_map('absint', (array) PluginSettings::get('appointment_manager_user_ids'));
        $grants = [
            'owners' => $recover ? [] : ($policy['owners'] ?? ($operation === 'setup' ? [$actor] : [])),
            'access_owners' => $recover ? [] : ($policy['access_owners'] ?? []),
            'managers' => $recover ? [] : ($policy['managers'] ?? $legacy),
        ];
        $stage = $setup && ($_POST['operation'] ?? '') === $operation ? ($_POST['access_stage'] ?? '') : '';
        if (in_array($stage, ['review', 'edit'], true)) {
            $submitted = isset($_POST['role_form']) ? self::postedGrants() : null;
            if (!self::validPost($operation, $token) || $submitted === null
                || array_diff(array_merge($submitted['owners'], $submitted['access_owners']), $adminIds)
                || array_diff($submitted['managers'], $eligibleIds)) {
                echo '<p role="alert">' . esc_html__('This selection could not be reviewed. Check the current permissions and try again.', 'rrze-appointment') . '</p>';
            } else {
                $grants = $submitted;
                if ($operation === 'setup' && !in_array($actor, array_merge($grants['owners'], $grants['access_owners']), true)) {
                    // The local administrator stays responsible during initial setup.
                    $grants['owners'][] = $actor;
                }
                if ($stage === 'review' && ($grants['owners'] || $grants['access_owners'])) {
                    self::renderPreview($operation, $token, $users, $grants, $legacy);
                    return;
                }
            }
        } elseif ((self::$invalidSelection['operation'] ?? '') === $operation) {
            $grants = self::$invalidSelection['grants'];
        }

        $ownerMessage = __('Select at least one responsible person.', 'rrze-appointment');
        $hasOwner = array_intersect($adminIds, array_merge($grants['owners'], $grants['access_owners']));
        $canSelectOwnData = $setup || (is_array($policy) && AccessPolicy::hasBookingGrant($policy, $actor));
        $roles = self::roleLabels();
        $noRole = $recover ? __('Do not assign responsibility', 'rrze-appointment') : __('No access', 'rrze-appointment');
        $byPerson = [];
        foreach (['managers', 'access_owners', 'owners'] as $role) {
            foreach ($grants[$role] as $id) {
                $byPerson[$id] = $role;
            }
        }
        $obsolete = array_diff(array_merge($grants['owners'], $grants['access_owners']), $adminIds)
            || array_diff($grants['managers'], $eligibleIds);
        if ($setup) {
            echo '<p>' . esc_html__('Choose who will keep access. You can review the list before applying this restriction.', 'rrze-appointment') . '</p>';
            if ($operation === 'setup') {
                echo '<p>' . esc_html__('You will be a responsible person for this website.', 'rrze-appointment') . '</p>';
            }
        }
        ?>
        <form method="post" class="rrze-appointment-access-form" action="<?php echo esc_url(self::url() . ($recover ? '#rrze-appt-settings-recovery' : '#rrze-appt-access-people')); ?>">
            <?php self::formFields($operation, $token); ?>
            <?php if ($setup) : ?><input type="hidden" name="access_stage" value="review"><?php endif; ?>
            <fieldset data-access-directory>
                <legend><?php echo esc_html($recover ? __('New responsible people', 'rrze-appointment') : __('Roles on this website', 'rrze-appointment')); ?></legend>
                <p class="description"><?php esc_html_e('Only website administrators can manage access. Booking access lets a person view and cancel appointments.', 'rrze-appointment'); ?></p>
                <?php if (!$canSelectOwnData && in_array($actor, $adminIds, true)) : ?>
                    <p class="description"><?php esc_html_e('Ask another responsible administrator to grant you access to bookings.', 'rrze-appointment'); ?></p>
                <?php endif; ?>
                <div data-access-filter hidden class="rrze-appt-settings__filter">
                    <label><?php esc_html_e('Find a person', 'rrze-appointment'); ?><input type="search"></label>
                </div>
                <div class="rrze-appt-settings__roles">
                <?php foreach ($people as $key => $user) :
                    $id = (int) $user->ID;
                    $role = $byPerson[$id] ?? 'none';
                    $field = 'rrze-appt-' . $operation . '-' . $id;
                    $pinned = $operation === 'setup' && $id === $actor;
                    $canHaveData = $id !== $actor || $canSelectOwnData;
                    ?>
                    <div data-access-person class="rrze-appt-settings__role">
                        <label for="<?php echo esc_attr($field); ?>"><?php echo esc_html(self::personLabel($user)); ?></label>
                        <select id="<?php echo esc_attr($field); ?>" name="roles[<?php echo esc_attr((string) $id); ?>]">
                            <?php if (!$pinned) : ?>
                                <?php if ($role === 'none') : ?>
                                    <?php // Omit untouched accounts from native form submissions on large sites. ?>
                                    <option value="" selected disabled hidden><?php echo esc_html($noRole); ?></option>
                                <?php endif; ?>
                                <option value="none"><?php echo esc_html($noRole); ?></option>
                                <?php if (!$recover && $canHaveData) : ?>
                                    <option value="managers" <?php selected($role, 'managers'); ?>><?php echo esc_html($roles['managers']); ?></option>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (isset($administrators[$key])) : ?>
                                <?php if ($canHaveData) : ?>
                                    <option value="owners" <?php selected($role, 'owners'); ?>><?php echo esc_html($roles['owners']); ?></option>
                                <?php endif; ?>
                                <option value="access_owners" <?php selected($role, 'access_owners'); ?>><?php echo esc_html($roles['access_owners']); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                </div>
                <p data-access-owner-feedback data-message="<?php echo esc_attr($ownerMessage); ?>" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"><?php echo $hasOwner ? '' : esc_html($ownerMessage); ?></p>
                <?php if (!$recover && $policy !== null && $obsolete) : ?>
                    <p class="description"><?php esc_html_e('Some previous selections are no longer eligible and have no access. Saving removes these obsolete selections.', 'rrze-appointment'); ?></p>
                <?php endif; ?>
                <?php if (!$adminIds) : ?><p><?php esc_html_e('Add an administrator to this website before assigning responsibility.', 'rrze-appointment'); ?></p><?php endif; ?>
                <p data-access-empty role="status" hidden><?php esc_html_e('No matching people.', 'rrze-appointment'); ?></p>
            </fieldset>
            <input type="hidden" name="roles_complete" value="1">
            <?php if ($setup) : ?>
                <button type="submit" class="button button-primary" <?php disabled(!$adminIds); ?>><?php esc_html_e('Review access', 'rrze-appointment'); ?></button>
            <?php elseif ($adminIds) : ?>
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
     */
    private static function formFields(string $operation, string $token): void
    {
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
        <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
        <input type="hidden" name="policy_token" value="<?php echo esc_attr($token); ?>">
        <input type="hidden" name="destination" value="settings">
        <input type="hidden" name="role_form" value="2">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(self::nonceAction($operation, $token))); ?>">
        <?php
    }

    /**
     * Shows the proposed roles and everyone on this site who loses existing access.
     *
     * @param string $operation Local or network setup operation being confirmed.
     * @param string $token Revision of the reviewed grants.
     * @param object[] $users Current site members in display order.
     * @param array{owners: int[], access_owners: int[], managers: int[]} $grants Proposed roles.
     * @param int[] $legacy Previously delegated users with appointment access.
     */
    private static function renderPreview(string $operation, string $token, array $users, array $grants, array $legacy): void
    {
        $labels = self::roleLabels();
        $labels['removed'] = __('People who will lose booking access', 'rrze-appointment');
        $names = array_fill_keys(array_keys($labels), []);
        foreach ($users as $user) {
            $id = (int) $user->ID;
            foreach ($grants as $role => $ids) {
                if (in_array($id, $ids, true)) {
                    $names[$role][] = self::personLabel($user);
                }
            }
            if (!in_array($id, $grants['owners'], true) && !in_array($id, $grants['managers'], true)
                && (user_can($user, 'manage_options') || in_array($id, $legacy, true))) {
                $names['removed'][] = self::personLabel($user);
            }
        }
        ?>
        <div class="rrze-appt-settings__preview">
            <h3><?php esc_html_e('Review access before applying', 'rrze-appointment'); ?></h3>
            <p><?php esc_html_e('These roles apply to everyone on this website, including administrators, even when SSO is off.', 'rrze-appointment'); ?></p>
            <dl>
            <?php foreach ($labels as $role => $label) : ?>
                <dt><strong><?php echo esc_html($label); ?></strong></dt>
                <dd><?php echo esc_html($names[$role] ? implode(', ', $names[$role]) : __('No one', 'rrze-appointment')); ?></dd>
            <?php endforeach; ?>
            </dl>
            <form method="post" action="<?php echo esc_url(self::url() . '#rrze-appt-access-people'); ?>">
                <?php self::formFields($operation, $token); ?>
                <?php foreach ($grants as $role => $ids) : ?>
                    <?php foreach ($ids as $id) : ?>
                        <input type="hidden" name="roles[<?php echo esc_attr((string) $id); ?>]" value="<?php echo esc_attr($role); ?>">
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <input type="hidden" name="roles_complete" value="1">
                <div class="rrze-appt-settings__actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Apply access restrictions', 'rrze-appointment'); ?></button>
                    <button type="submit" name="access_stage" value="edit" class="button"><?php esc_html_e('Change selection', 'rrze-appointment'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }
}
