<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Configuration\PluginSettings;
use function RRZE\Appointment\plugin;

defined('ABSPATH') || exit;

/**
 * Registers and renders the plugin configuration page.
 */
final class SettingsPage
{
    public const PAGE_SLUG = 'rrze-appointment-settings';
    private const SETTINGS_GROUP = 'rrze_appointment_settings_group';
    private const REMINDERS_SECTION = 'rrze_appointment_reminders';
    private const BOOKINGS_SECTION = 'rrze_appointment_booking_data';
    private const ILLUSTRATIONS_SECTION = 'rrze_appointment_illustrations';
    private const ILLUSTRATIONS_PAGE = self::PAGE_SLUG . '-illustrations';
    private const ADMIN_CSS_PATH = 'assets/css/rrze-appointment-admin.css';
    private const ADMIN_JS_PATH = 'assets/js/rrze-appointment-admin.js';

    /** Uses the supplied mail templates page to register its actions and render the templates tab. */
    public function __construct(private readonly MailTemplatesPage $mailTemplates)
    {
    }

    /** Registers the settings page, its fields and assets, and the mail template hooks. */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('check_admin_referer', [$this, 'rejectOutdatedSettingsForm'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        $this->mailTemplates->register();
    }

    /** Adds the Appointment page to the Settings menu for users with the manage_options capability. */
    public function addMenuPage(): void
    {
        add_options_page(
            __('RRZE Appointment', 'rrze-appointment'),
            __('RRZE Appointment', 'rrze-appointment'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    /** Returns the WordPress screen ID for the Appointment settings page. */
    public static function screenHook(): string
    {
        return 'settings_page_' . self::PAGE_SLUG;
    }

    /**
     * Stops older settings forms before options.php writes fields that have moved to another tab.
     *
     * @param int|string $action WordPress nonce action.
     * @param int|false $result Nonce age (1 or 2) on success, false on failure.
     */
    public function rejectOutdatedSettingsForm(int|string $action, int|false $result): void
    {
        global $pagenow;
        if ($action !== self::SETTINGS_GROUP . '-options' || !$result || $pagenow !== 'options.php'
            || ($_POST['action'] ?? '') !== 'update') {
            return;
        }

        $scope = $_POST[PluginSettings::OPTION_NAME]['_settings_scope'] ?? '';
        if (in_array($scope, [PluginSettings::GENERAL_SCOPE, 'illustrations', 'additional_people'], true)) {
            return;
        }

        wp_die(
            esc_html__('These settings have been updated. Reload the page and make your change again.', 'rrze-appointment'),
            '',
            [
                'response' => 409,
                'link_url' => add_query_arg('page', self::PAGE_SLUG, admin_url('options-general.php')),
                'link_text' => esc_html__('Reload page', 'rrze-appointment'),
            ]
        );
    }

    /** Registers the shared site option and the fields shown on the general and illustrations tabs. */
    public function registerSettings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            PluginSettings::OPTION_NAME,
            [
                'sanitize_callback' => [PluginSettings::class, 'sanitize'],
                'default' => PluginSettings::defaults(),
            ]
        );
        $card = [
            'before_section' => '<section class="rrze-appt-settings__card">',
            'after_section' => '</section>',
        ];
        add_settings_section(self::REMINDERS_SECTION, __('Reminders', 'rrze-appointment'), null, self::PAGE_SLUG, $card);
        add_settings_section(self::BOOKINGS_SECTION, __('Booking data & cancellations', 'rrze-appointment'), null, self::PAGE_SLUG, $card);
        add_settings_field(
            'reminder_booker_days',
            __('Email to the person booking', 'rrze-appointment'),
            [$this, 'renderReminderDaysField'],
            self::PAGE_SLUG,
            self::REMINDERS_SECTION,
            ['label_for' => 'reminder_booker_days']
        );
        add_settings_field(
            'reminder_host_days',
            __('Email to the appointment contact', 'rrze-appointment'),
            [$this, 'renderReminderDaysField'],
            self::PAGE_SLUG,
            self::REMINDERS_SECTION,
            ['label_for' => 'reminder_host_days']
        );
        add_settings_field(
            'retention_days',
            __('Booking data retention', 'rrze-appointment'),
            [$this, 'renderRetentionDaysField'],
            self::PAGE_SLUG,
            self::BOOKINGS_SECTION,
            ['label_for' => 'retention_days']
        );
        add_settings_field(
            'cancellation_reason_enabled',
            __('Cancellation reason', 'rrze-appointment'),
            [$this, 'renderCancellationReasonField'],
            self::PAGE_SLUG,
            self::BOOKINGS_SECTION
        );
        add_settings_field(
            'sensitive_mode_enabled',
            __('Sensitive appointment mode', 'rrze-appointment'),
            [$this, 'renderSensitiveModeField'],
            self::PAGE_SLUG,
            self::BOOKINGS_SECTION
        );
        add_settings_section(
            self::ILLUSTRATIONS_SECTION,
            __('Illustrations', 'rrze-appointment'),
            [$this, 'renderIllustrationsField'],
            self::ILLUSTRATIONS_PAGE
        );
    }

    /**
     * Renders the reminder-days settings field.
     *
     * @param array{label_for: string} $args The booker or host reminder option key doubles as the select ID.
     */
    public function renderReminderDaysField(array $args): void
    {
        $key = $args['label_for'];
        $value = (int) PluginSettings::get($key);
        $options = [0 => __('No reminder', 'rrze-appointment')];
        for ($i = 1; $i <= PluginSettings::MAX_REMINDER_DAYS; $i++) {
            /* translators: %d is the number of days before an appointment. */
            $options[$i] = sprintf(_n('%d day before', '%d days before', $i, 'rrze-appointment'), $i);
        }
        printf(
            '<select id="%s" name="%s[%s]">',
            esc_attr($key),
            esc_attr(PluginSettings::OPTION_NAME),
            esc_attr($key)
        );
        foreach ($options as $val => $label) {
            printf('<option value="%d"%s>%s</option>', $val, selected($value, $val, false), esc_html($label));
        }
        echo '</select>';
    }

    /**
     * Renders the booking-retention settings field.
     */
    public function renderRetentionDaysField(): void
    {
        $value = (int) PluginSettings::get('retention_days');
        printf(
            '<input type="number" id="retention_days" name="%s[retention_days]" value="%d" min="0" max="%d" step="1" class="small-text" aria-describedby="rrze-appt-retention-help"> %s',
            esc_attr(PluginSettings::OPTION_NAME),
            $value,
            PluginSettings::MAX_RETENTION_DAYS,
            esc_html__('days', 'rrze-appointment')
        );
        echo '<p class="description" id="rrze-appt-retention-help">'
            . esc_html__('How long to keep bookings after the appointment ends. After this period the plugin permanently deletes them.', 'rrze-appointment') . '</p>';
    }

    /**
     * Renders the cancellation-reason opt-in setting.
     */
    public function renderCancellationReasonField(): void
    {
        $enabled = (bool) PluginSettings::get('cancellation_reason_enabled');
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr(PluginSettings::OPTION_NAME); ?>[cancellation_reason_enabled]"
                aria-describedby="rrze-appt-cancellation-help"
                value="1"
                <?php checked($enabled); ?>
            >
            <?php esc_html_e('Allow an optional reason for cancelling.', 'rrze-appointment'); ?>
        </label>
        <p class="description" id="rrze-appt-cancellation-help">
            <?php esc_html_e('Applies to cancellations from an email link or from appointment management.', 'rrze-appointment'); ?>
        </p>
        <?php
    }

    /**
     * Renders the privacy mode for the appointment administration screen.
     */
    public function renderSensitiveModeField(): void
    {
        $enabled = (bool) PluginSettings::get('sensitive_mode_enabled');
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr(PluginSettings::OPTION_NAME); ?>[sensitive_mode_enabled]"
                aria-describedby="rrze-appt-sensitive-help"
                value="1"
                <?php checked($enabled); ?>
            >
            <?php esc_html_e('Show only the date, time and title in appointment management.', 'rrze-appointment'); ?>
        </label>
        <p class="description" id="rrze-appt-sensitive-help">
            <?php esc_html_e('Bookings confirmed while this setting is on stay anonymized in the overview permanently. Details of earlier bookings reappear when you turn it off. Emails use the usual booking details.', 'rrze-appointment'); ?>
        </p>
        <?php
    }

    /** Renders the independent form for additional grants on a site using standard access. */
    public function renderAppointmentManagersField(): void
    {
        if (AccessPolicy::restricted()) {
            return;
        }
        if (is_wp_error(BackendSsoAccess::check())) {
            echo '<p><a href="#rrze-appointment-sso-heading">'
                . esc_html__('Confirm your SSO account to edit this list.', 'rrze-appointment') . '</a></p>';
            return;
        }
        $stored = array_map('absint', (array) PluginSettings::get('appointment_manager_user_ids'));
        $users = get_users(['blog_id' => get_current_blog_id(), 'orderby' => 'display_name', 'order' => 'ASC']);
        $additional = array_filter($users, static fn($user): bool => !user_can($user, 'manage_options'));
        if (!$additional) {
            echo '<p>' . esc_html__('This website has no additional users to select.', 'rrze-appointment') . '</p>';
            return;
        }
        $option = PluginSettings::OPTION_NAME;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" class="rrze-appointment-access-form">
            <?php settings_fields(self::SETTINGS_GROUP); ?>
            <input type="hidden" name="<?php echo esc_attr($option); ?>[_settings_scope]" value="additional_people">
            <fieldset data-access-directory>
                <legend><?php esc_html_e('Who else may view and cancel appointments?', 'rrze-appointment'); ?></legend>
                <div hidden data-access-filter class="rrze-appt-settings__filter">
                    <label><?php esc_html_e('Find a person', 'rrze-appointment'); ?> <input type="search"></label>
                </div>
                <div class="rrze-appt-settings__roles">
                <?php foreach ($additional as $user) : ?>
                    <div data-access-person class="rrze-appt-settings__role">
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($option); ?>[appointment_manager_user_ids][]"
                                value="<?php echo esc_attr((string) $user->ID); ?>" <?php checked(in_array((int) $user->ID, $stored, true)); ?>>
                            <?php echo esc_html(AccessSettings::personLabel($user)); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                </div>
                <p data-access-empty hidden role="status"><?php esc_html_e('No matching people.', 'rrze-appointment'); ?></p>
            </fieldset>
            <input type="hidden" name="<?php echo esc_attr($option); ?>[_access_list_complete]" value="1">
            <?php submit_button(__('Save additional people', 'rrze-appointment'), 'secondary', 'save-additional-people'); ?>
        </form>
        <?php
    }

    /**
     * Renders the illustration cards directly in their settings section so the grid uses the form width.
     */
    public function renderIllustrationsField(): void
    {
        $storedIllustrations = PluginSettings::get('illustrations');
        $storedIllustrations = is_array($storedIllustrations) ? $storedIllustrations : [];
        $labels = [
            'confirmation_success' => __('Appointment confirmed', 'rrze-appointment'),
            'confirmation_questions' => __('Additional questions', 'rrze-appointment'),
            'cancellation_confirmation' => __('Cancellation confirmation', 'rrze-appointment'),
            'cancellation_success' => __('Cancellation completed', 'rrze-appointment'),
            'waitlist_preference' => __('Waitlist preference', 'rrze-appointment'),
            'opening_notification' => __('Booking opening notification', 'rrze-appointment'),
            'error' => __('Error page', 'rrze-appointment'),
        ];
        ?>
        <p class="description rrze-appt-illustrations__intro">
            <?php esc_html_e('Replace the bundled illustrations on public confirmation and information screens with images from the media library.', 'rrze-appointment'); ?>
        </p>
        <div class="rrze-appt-illustrations">
            <?php foreach (PluginSettings::ILLUSTRATION_DEFAULTS as $key => $defaultFilename) :
                $attachmentId = absint($storedIllustrations[$key] ?? 0);
                $customUrl = $attachmentId > 0
                    ? wp_get_attachment_image_url($attachmentId, 'medium')
                    : false;
                if (!is_string($customUrl) || $customUrl === '') {
                    $attachmentId = 0;
                    $customUrl = '';
                }
                $defaultUrl = plugin()->getUrl('assets/images') . $defaultFilename;
                $previewUrl = $customUrl !== '' ? $customUrl : $defaultUrl;
                $fieldId = 'rrze-appt-illustration-' . $key;
                ?>
                <section class="rrze-appt-illustration" data-illustration-field>
                    <h3><?php echo esc_html($labels[$key] ?? $key); ?></h3>
                    <div class="rrze-appt-illustration__preview">
                        <img
                            src="<?php echo esc_url($previewUrl); ?>"
                            data-illustration-preview
                            data-default-src="<?php echo esc_url($defaultUrl); ?>"
                            alt=""
                        >
                    </div>
                    <input
                        type="hidden"
                        id="<?php echo esc_attr($fieldId); ?>"
                        name="<?php echo esc_attr(PluginSettings::OPTION_NAME); ?>[illustrations][<?php echo esc_attr($key); ?>]"
                        value="<?php echo esc_attr($attachmentId); ?>"
                        data-illustration-input
                    >
                    <p class="rrze-appt-illustration__status" data-illustration-status>
                        <?php echo esc_html($attachmentId > 0
                            ? __('Custom illustration', 'rrze-appointment')
                            : __('Default illustration', 'rrze-appointment')); ?>
                    </p>
                    <div class="rrze-appt-illustration__actions">
                        <button
                            type="button"
                            class="button"
                            data-illustration-select
                            data-dialog-title="<?php esc_attr_e('Select illustration', 'rrze-appointment'); ?>"
                            data-dialog-button="<?php esc_attr_e('Use this image', 'rrze-appointment'); ?>"
                            data-custom-label="<?php esc_attr_e('Custom illustration', 'rrze-appointment'); ?>"
                        >
                            <?php esc_html_e('Select image', 'rrze-appointment'); ?>
                        </button>
                        <button
                            type="button"
                            class="button-link-delete"
                            data-illustration-remove
                            data-default-label="<?php esc_attr_e('Default illustration', 'rrze-appointment'); ?>"
                            <?php echo $attachmentId === 0 ? 'hidden' : ''; ?>
                        >
                            <?php esc_html_e('Use default illustration', 'rrze-appointment'); ?>
                        </button>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Renders the tabbed plugin settings page.
     */
    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = Request::queryText('tab', true) ?: 'general';
        $tabs = [
            'general' => __('General', 'rrze-appointment'),
            'access' => __('Access & SSO', 'rrze-appointment'),
            'illustrations' => __('Illustrations', 'rrze-appointment'),
            'templates' => __('Mail Templates', 'rrze-appointment'),
        ];
        if (!isset($tabs[$tab])) {
            $tab = 'general';
        }
        ?>
        <div class="wrap rrze-appointment-settings-wrap rrze-appt-settings">
            <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Appointment settings', 'rrze-appointment'); ?>">
                <?php foreach ($tabs as $key => $label) :
                    $url    = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $key], admin_url('options-general.php'));
                    $active = $tab === $key ? ' nav-tab-active' : '';
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo $active; ?>"
                        <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="rrze-appt-settings__content<?php echo $tab === 'access' ? ' rrze-appt-settings__content--access' : ''; ?>">
                <?php if ($tab === 'general') : ?>
                    <?php $this->renderTabGeneral(); ?>
                <?php elseif ($tab === 'access') : ?>
                    <?php $this->renderTabAccess(); ?>
                <?php elseif ($tab === 'illustrations') : ?>
                    <?php $this->renderTabIllustrations(); ?>
                <?php elseif ($tab === 'templates') : ?>
                    <?php $this->mailTemplates->render(); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** Shows the site's saved access policy above the reminder and booking settings. */
    private function renderTabGeneral(): void
    {
        $policy = AccessPolicy::read();
        $audience = match (true) {
            is_wp_error($policy) => __('Access settings unavailable', 'rrze-appointment'),
            is_array($policy) => __('Only selected people', 'rrze-appointment'),
            empty(PluginSettings::get('appointment_manager_user_ids')) => __('Administrators', 'rrze-appointment'),
            default => __('Administrators and additional people', 'rrze-appointment'),
        };
        ?>
        <section class="rrze-appt-settings__summary" aria-labelledby="rrze-appt-summary-title">
            <h2 id="rrze-appt-summary-title"><?php esc_html_e('Access to appointment management', 'rrze-appointment'); ?></h2>
            <dl>
                <div>
                    <dt><?php esc_html_e('Who can view and cancel appointments?', 'rrze-appointment'); ?></dt>
                    <dd><?php echo esc_html($audience); ?></dd>
                </div>
                <div>
                    <dt><?php esc_html_e('SSO sign-in', 'rrze-appointment'); ?></dt>
                    <dd><?php echo esc_html(BackendSsoAccess::required()
                        ? _x('Required', 'SSO sign-in status', 'rrze-appointment')
                        : _x('Not required', 'SSO sign-in status', 'rrze-appointment')); ?></dd>
                </div>
            </dl>
            <a class="button" href="<?php echo esc_url(BackendSsoSettings::destination('settings')); ?>">
                <?php esc_html_e('Manage access & SSO', 'rrze-appointment'); ?>
            </a>
        </section>
        <form method="post" action="options.php">
            <?php
            settings_fields(self::SETTINGS_GROUP);
            ?>
            <input
                type="hidden"
                name="<?php echo esc_attr(PluginSettings::OPTION_NAME); ?>[_settings_scope]"
                value="<?php echo esc_attr(PluginSettings::GENERAL_SCOPE); ?>"
            >
            <?php
            do_settings_sections(self::PAGE_SLUG);
            submit_button(__('Save general settings', 'rrze-appointment'));
            ?>
        </form>
        <?php
    }

    /** Groups access grants, SSO and network recovery under separate, site-local actions. */
    private function renderTabAccess(): void
    {
        AccessSettings::renderSettings([$this, 'renderAppointmentManagersField']);
        BackendSsoSettings::render();
        if (BackendSsoAccess::canReset() && (AccessPolicy::restricted() || BackendSsoAccess::required())) {
            ?>
            <details class="rrze-appt-settings__recovery">
                <summary><?php esc_html_e('Recovery', 'rrze-appointment'); ?></summary>
                <?php AccessSettings::renderRecovery(); ?>
                <?php BackendSsoSettings::renderRecovery(); ?>
            </details>
            <?php
        }
    }

    /** Renders the illustrations form with the scope used to preserve the general settings. */
    private function renderTabIllustrations(): void
    {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields(self::SETTINGS_GROUP);
            ?>
            <input
                type="hidden"
                name="<?php echo esc_attr(PluginSettings::OPTION_NAME); ?>[_settings_scope]"
                value="illustrations"
            >
            <?php
            do_settings_sections(self::ILLUSTRATIONS_PAGE);
            submit_button();
            ?>
        </form>
        <?php
    }

    /**
     * Loads shared admin styles, the settings controls for the active tab, and the illustration media picker.
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if (!in_array($hook, [self::screenHook(), BookingsPage::screenHook()], true)) {
            return;
        }

        $adminCss = plugin()->getPath() . self::ADMIN_CSS_PATH;
        if (is_readable($adminCss)) {
            wp_enqueue_style(
                'rrze-appointment-admin-css',
                plugin()->getUrl() . self::ADMIN_CSS_PATH,
                [],
                (string) filemtime($adminCss)
            );
        }

        $tab = Request::queryText('tab', true) ?: 'general';
        if ($tab !== 'access' && !($hook === self::screenHook() && $tab === 'illustrations')) {
            return;
        }

        $dependencies = [];
        if ($tab === 'illustrations') {
            wp_enqueue_media();
            $dependencies[] = 'media-editor';
        }
        $adminJs = plugin()->getPath() . self::ADMIN_JS_PATH;
        if (is_readable($adminJs)) {
            wp_enqueue_script(
                'rrze-appointment-admin-js',
                plugin()->getUrl() . self::ADMIN_JS_PATH,
                $dependencies,
                (string) filemtime($adminJs),
                true
            );
        }
    }
}
