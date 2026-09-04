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
    private const GENERAL_SECTION = 'rrze_appointment_general';
    private const ILLUSTRATIONS_SECTION = 'rrze_appointment_illustrations';
    private const ILLUSTRATIONS_PAGE = self::PAGE_SLUG . '-illustrations';
    private const ADMIN_CSS_PATH = 'assets/css/rrze-appointment-admin.css';
    private const ADMIN_JS_PATH = 'assets/js/rrze-appointment-admin.js';

    public function __construct(private readonly MailTemplatesPage $mailTemplates)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        $this->mailTemplates->register();
    }

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

    public static function screenHook(): string
    {
        return 'settings_page_' . self::PAGE_SLUG;
    }

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
        add_settings_section(self::GENERAL_SECTION, '', '__return_false', self::PAGE_SLUG);
        add_settings_field(
            'reminder_days',
            __('Reminder Email', 'rrze-appointment'),
            [$this, 'renderReminderDaysField'],
            self::PAGE_SLUG,
            self::GENERAL_SECTION
        );
        add_settings_field(
            'retention_days',
            __('Booking data retention', 'rrze-appointment'),
            [$this, 'renderRetentionDaysField'],
            self::PAGE_SLUG,
            self::GENERAL_SECTION
        );
        add_settings_field(
            'cancellation_reason_enabled',
            __('Cancellation reason', 'rrze-appointment'),
            [$this, 'renderCancellationReasonField'],
            self::PAGE_SLUG,
            self::GENERAL_SECTION
        );
        add_settings_section(
            self::ILLUSTRATIONS_SECTION,
            '',
            '__return_false',
            self::ILLUSTRATIONS_PAGE
        );
        add_settings_field(
            'illustrations',
            __('Illustrations', 'rrze-appointment'),
            [$this, 'renderIllustrationsField'],
            self::ILLUSTRATIONS_PAGE,
            self::ILLUSTRATIONS_SECTION
        );
    }

    /**
     * Renders the reminder-days settings field.
     */
    public function renderReminderDaysField(): void
    {
        $value   = (int) PluginSettings::get('reminder_days');
        $options = [0 => __('Disabled', 'rrze-appointment')];
        for ($i = 1; $i <= PluginSettings::MAX_REMINDER_DAYS; $i++) {
            $options[$i] = $i;
        }
        echo '<select name="' . esc_attr(PluginSettings::OPTION_NAME) . '[reminder_days]">';
        foreach ($options as $val => $label) {
            printf('<option value="%d"%s>%s</option>', $val, selected($value, $val, false), esc_html($label));
        }
        echo '</select> ' . esc_html__('days before the appointment.', 'rrze-appointment');
    }

    /**
     * Renders the booking-retention settings field.
     */
    public function renderRetentionDaysField(): void
    {
        $value = (int) PluginSettings::get('retention_days');
        printf(
            '<input type="number" name="%s[retention_days]" value="%d" min="0" max="%d" step="1" class="small-text"> %s',
            esc_attr(PluginSettings::OPTION_NAME),
            $value,
            PluginSettings::MAX_RETENTION_DAYS,
            esc_html__('Completed bookings are permanently deleted this many days after the appointment ends (default: 30).', 'rrze-appointment')
        );
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
                value="1"
                <?php checked($enabled); ?>
            >
            <?php esc_html_e('Allow a cancellation reason to be entered.', 'rrze-appointment'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Applies to cancellations from email links and the appointment dashboard. Disable this option if the field is being abused.', 'rrze-appointment'); ?>
        </p>
        <?php
    }

    /**
     * Renders media-library selectors for public-page illustrations.
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
            'illustrations' => __('Illustrations', 'rrze-appointment'),
            'templates' => __('Mail Templates', 'rrze-appointment'),
        ];
        if (!isset($tabs[$tab])) {
            $tab = 'general';
        }
        ?>
        <div class="wrap rrze-appointment-settings-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $label) :
                    $url    = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $key], admin_url('options-general.php'));
                    $active = $tab === $key ? ' nav-tab-active' : '';
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo $active; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="tab-content" style="margin-top:1.5rem;">
                <?php if ($tab === 'general') : ?>
                    <?php $this->renderTabGeneral(); ?>
                <?php elseif ($tab === 'illustrations') : ?>
                    <?php $this->renderTabIllustrations(); ?>
                <?php elseif ($tab === 'templates') : ?>
                    <?php $this->mailTemplates->render(); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderTabGeneral(): void
    {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields(self::SETTINGS_GROUP);
            ?>
            <input
                type="hidden"
                name="<?php echo esc_attr(PluginSettings::OPTION_NAME); ?>[_settings_scope]"
                value="general"
            >
            <?php
            do_settings_sections(self::PAGE_SLUG);
            submit_button();
            ?>
        </form>
        <?php
    }

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
     * Loads the settings UI stylesheet on the plugin's administration pages.
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

        if ($hook !== self::screenHook()) {
            return;
        }

        $tab = Request::queryText('tab', true) ?: 'general';
        if ($tab !== 'illustrations') {
            return;
        }

        wp_enqueue_media();
        $adminJs = plugin()->getPath() . self::ADMIN_JS_PATH;
        if (is_readable($adminJs)) {
            wp_enqueue_script(
                'rrze-appointment-admin-js',
                plugin()->getUrl() . self::ADMIN_JS_PATH,
                ['media-editor'],
                (string) filemtime($adminJs),
                true
            );
        }
    }
}
