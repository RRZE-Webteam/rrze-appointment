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
    private const ADMIN_CSS_PATH = 'assets/css/rrze-appointment-admin.css';

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
     * Renders the tabbed plugin settings page.
     */
    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = Request::queryText('tab', true) ?: 'general';
        $tabs = [
            'general'   => __('General', 'rrze-appointment'),
            'templates' => __('Mail Templates', 'rrze-appointment'),
        ];
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
            do_settings_sections(self::PAGE_SLUG);
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
    }
}
