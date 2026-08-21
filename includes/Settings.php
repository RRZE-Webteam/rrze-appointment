<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Manages appointment settings, admin screens, and template administration.
 */
final class Settings
{
    public const OPTION_NAME = 'rrze_appointment_settings';
    public const PAGE_SLUG = 'rrze-appointment-settings';

    private const ADMIN_MENU_ICON_PATH = 'assets/svg/approval_delegation_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg';
    private const ADMIN_CSS_PATH = 'assets/css/rrze-appointment-admin.css';
    private const BOOKINGS_PAGE_SLUG = 'rrze-appointment-bookings';
    private const SETTINGS_GROUP = 'rrze_appointment_settings_group';
    private const GENERAL_SECTION = 'rrze_appointment_general';
    private const MAX_REMINDER_DAYS = 7;
    private const MAX_RETENTION_DAYS = 3650;
    private const TEMPLATE_TYPES = [
        'booking_pending',
        'booking_pending_questions',
        'booking_opening_notification',
        'booking_booker',
        'booking_host',
        'reminder_admin',
        'reminder_booker',
        'cancellation',
        'waitlist_earlier_slot',
    ];

    /**
     * Returns placeholders supported by appointment email templates.
     *
     * @return array<string, string>
     */
    public static function getPlaceholders(): array
    {
        return [
            '[title]'             => __('Title of the appointment', 'rrze-appointment'),
            '[date]'              => __('Date of the appointment', 'rrze-appointment'),
            '[time]'              => __('Time (from – to)', 'rrze-appointment'),
            '[current_date]'      => __('Date of current appointment', 'rrze-appointment'),
            '[current_time]'      => __('Time of current appointment', 'rrze-appointment'),
            '[location]'          => __('Location', 'rrze-appointment'),
            '[person_name]'       => __('Inviting person', 'rrze-appointment'),
            '[name]'              => __('Booking person (name)', 'rrze-appointment'),
            '[email]'             => __('Booking person (email)', 'rrze-appointment'),
            '[questions]'         => __('Answers to additional questions', 'rrze-appointment'),
            '[confirmation_link]' => __('Link to booking confirmation', 'rrze-appointment'),
            '[booking_link]'      => __('Link to book an appointment when booking opens', 'rrze-appointment'),
            '[cancel_link]'       => __('Link to cancel', 'rrze-appointment'),
            '[waitlist_optout_link]' => __('Link to stop earlier appointment notifications', 'rrze-appointment'),
            '[imprint_link]'      => __('Link to imprint', 'rrze-appointment'),
            '[post_link]'         => __('Link to post or page', 'rrze-appointment'),
        ];
    }

    /**
     * Returns defaults for persisted plugin settings.
     *
     * @return array{reminder_days: int, recurrence_limit: int, retention_days: int}
     */
    public static function getDefaults(): array
    {
        return [
            'reminder_days'     => 0,
            'recurrence_limit'  => 52,
            'retention_days'    => 30,
        ];
    }

    /**
     * Returns a configured setting with fallback to its default.
     *
     * @return mixed|null
     */
    public static function get(string $key): mixed
    {
        $storedOptions = get_option(self::OPTION_NAME, []);
        $options = is_array($storedOptions) ? $storedOptions : [];
        $defaults = self::getDefaults();
        return (isset($options[$key]) && $options[$key] !== '') ? $options[$key] : ($defaults[$key] ?? null);
    }

    /**
     * Replaces placeholders in a plain-text or trusted HTML template.
     *
     * @param array<string, mixed> $vars Placeholder values.
     */
    public static function renderTemplate(string $template, array $vars): string
    {
        // Existing custom templates may still contain the removed placeholder.
        $vars['[message]'] = '';

        $replacements = [];
        foreach ($vars as $placeholder => $value) {
            $replacements[(string) $placeholder] = is_scalar($value) ? (string) $value : '';
        }

        return strtr($template, $replacements);
    }

    /**
     * Sends a multipart email and always removes the temporary PHPMailer hook.
     *
     * @param array<int, string> $attachments Absolute attachment paths.
     */
    public static function sendMail(
        string $to,
        string $subject,
        string $plain,
        string $html,
        array $attachments = [],
        string $status = MailTemplate::STATUS_NEUTRAL
    ): bool {
        $GLOBALS['rrze_appointment_html_body'] = MailTemplate::wrap($html, $subject, $status);
        add_action('phpmailer_init', [self::class, 'addHtmlPart']);
        try {
            return wp_mail($to, $subject, $plain, [], $attachments);
        } finally {
            remove_action('phpmailer_init', [self::class, 'addHtmlPart']);
            unset($GLOBALS['rrze_appointment_html_body']);
        }
    }

    /**
     * Adds the prepared HTML alternative to WordPress' PHPMailer instance.
     */
    public static function addHtmlPart(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void
    {
        $html = $GLOBALS['rrze_appointment_html_body'] ?? '';
        if (!is_string($html) || $html === '') {
            return;
        }

        $phpmailer->CharSet  = 'UTF-8';
        $phpmailer->AltBody  = $phpmailer->Body;
        $phpmailer->Body     = $html;
        $phpmailer->isHTML(true);
    }

    /**
     * Registers the settings controller's WordPress hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'handleTemplatePost']);
        add_action('admin_init', [$this, 'handleCancelPost']);
        add_action('admin_init', [$this, 'handleTestMail']);
        add_action('admin_print_footer_scripts', [$this, 'renderAdminJs']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    /**
     * Adds the settings and bookings administration pages.
     */
    public function addMenuPage(): void
    {
        add_options_page(
            __('RRZE Appointment', 'rrze-appointment'),
            __('RRZE Appointment', 'rrze-appointment'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );

        add_menu_page(
            __('Appointments', 'rrze-appointment'),
            __('Appointments', 'rrze-appointment'),
            'manage_options',
            self::BOOKINGS_PAGE_SLUG,
            [$this, 'renderBookingsPage'],
            self::getAdminMenuIcon(),
            30
        );
    }

    /**
     * Returns the bundled SVG as a data URI accepted by add_menu_page().
     *
     * A Dashicon keeps the menu usable if the SVG asset cannot be read.
     */
    private static function getAdminMenuIcon(): string
    {
        $iconPath = plugin()->getPath() . self::ADMIN_MENU_ICON_PATH;
        if (!is_readable($iconPath)) {
            return 'dashicons-calendar-alt';
        }

        $svg = file_get_contents($iconPath);
        if (!is_string($svg) || $svg === '') {
            return 'dashicons-calendar-alt';
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Registers plugin settings and their fields with WordPress.
     */
    public function registerSettings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_NAME,
            ['sanitize_callback' => [$this, 'sanitize'], 'default' => self::getDefaults()]
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
    }

    /**
     * Sanitizes persisted settings while retaining the legacy recurrence cap.
     *
     * @param array<string, mixed> $input Submitted settings.
     * @return array{reminder_days: int, recurrence_limit: int, retention_days: int}
     */
    public function sanitize(array $input): array
    {
        $storedOptions = get_option(self::OPTION_NAME, []);
        $currentOptions = is_array($storedOptions) ? $storedOptions : [];
        $legacyRecurrenceLimit = max(1, (int) ($currentOptions['recurrence_limit'] ?? 52));

        return [
            'reminder_days' => min(
                self::MAX_REMINDER_DAYS,
                max(0, (int) ($input['reminder_days'] ?? 0))
            ),
            // Retained as an internal fallback for existing series that do
            // not yet have an explicit end date or occurrence count.
            'recurrence_limit' => $legacyRecurrenceLimit,
            'retention_days' => min(
                self::MAX_RETENTION_DAYS,
                max(0, (int) ($input['retention_days'] ?? 30))
            ),
        ];
    }

    /**
     * Reads and sanitizes a scalar form value.
     */
    private static function getPostText(string $key, bool $sanitizeAsKey = false): string
    {
        return self::getRequestText($_POST, $key, $sanitizeAsKey);
    }

    /**
     * Reads a scalar form value as an integer.
     */
    private static function getPostInt(string $key): int
    {
        $value = wp_unslash($_POST[$key] ?? 0);
        return is_scalar($value) ? (int) $value : 0;
    }

    /**
     * Reads and sanitizes a scalar query-string value.
     */
    private static function getQueryText(string $key, bool $sanitizeAsKey = false): string
    {
        return self::getRequestText($_GET, $key, $sanitizeAsKey);
    }

    /**
     * Reads a scalar query-string value as an integer.
     */
    private static function getQueryInt(string $key): int
    {
        $value = wp_unslash($_GET[$key] ?? 0);
        return is_scalar($value) ? (int) $value : 0;
    }

    /**
     * Determines whether a scalar query flag has been supplied.
     */
    private static function hasQueryFlag(string $key): bool
    {
        return isset($_GET[$key]) && is_scalar($_GET[$key]);
    }

    /**
     * Normalizes a scalar value from a request collection.
     *
     * @param array<string, mixed> $source Request collection.
     */
    private static function getRequestText(array $source, string $key, bool $sanitizeAsKey): string
    {
        $value = wp_unslash($source[$key] ?? '');
        if (!is_string($value)) {
            return '';
        }

        return $sanitizeAsKey ? sanitize_key($value) : sanitize_text_field($value);
    }

    /**
     * Processes template save and delete requests.
     */
    public function handleTemplatePost(): void
    {
        $action = self::getPostText('rrze_appt_tpl_action', true);
        if ($action === '' || !current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('rrze_appt_tpl_save', 'rrze_appt_tpl_nonce');

        if ($action === 'delete') {
            $id = self::getPostInt('tpl_id');
            $inUse = $id > 0 ? MailTemplatePost::isInUse($id) : [];
            if (!empty($inUse)) {
                $titles = implode(', ', array_column($inUse, 'title'));
                wp_redirect(add_query_arg([
                    'page' => self::PAGE_SLUG,
                    'tab' => 'templates',
                    'inuse' => $titles,
                ], admin_url('options-general.php')));
                exit;
            }
            if ($id > 0) {
                MailTemplatePost::delete($id);
            }
            wp_redirect(add_query_arg([
                'page' => self::PAGE_SLUG,
                'tab' => 'templates',
                'deleted' => '1',
            ], admin_url('options-general.php')));
            exit;
        }

        if (in_array($action, ['save', 'new'], true)) {
            // Empty content fields intentionally inherit the corresponding
            // default. Only the title is required to publish a template.
            $title = self::getPostText('title');
            $isDraft = $title === '';
            $requestedId = self::getPostInt('id');
            $result = MailTemplatePost::save($_POST, $isDraft, MailTemplatePost::canEditHtml());
            $id     = is_wp_error($result) ? $requestedId : $result;
            $params = ['page' => self::PAGE_SLUG, 'tab' => 'templates'];

            if ($id > 0) {
                $params['edit'] = $id;
            } else {
                $params['new'] = '1';
            }

            $params[is_wp_error($result) ? 'save_error' : ($isDraft ? 'draft' : 'saved')] = '1';
            wp_redirect(add_query_arg($params, admin_url('options-general.php')));
            exit;
        }
    }

    /**
     * Sends a complete template preview set to the current administrator.
     */
    public function handleTestMail(): void
    {
        if (
            self::getPostText('rrze_appt_action', true) !== 'test_mail'
            || !current_user_can('manage_options')
        ) {
            return;
        }

        check_admin_referer('rrze_appt_test_mail', 'rrze_appt_test_nonce');

        $templateId = self::getPostInt('tpl_id');
        $user = wp_get_current_user();
        $to = sanitize_email((string) $user->user_email);

        $vars = [
            '[title]'             => 'Test lecture on sample topics',
            '[date]'              => date_i18n(get_option('date_format'), strtotime('+3 days')),
            '[time]'              => '10:00 – 10:30',
            '[location]'          => 'Room 1.234, Sample Building',
            '[person_name]'       => 'Prof. Dr. Max Sample',
            '[name]'              => 'Jane Sample',
            '[email]'             => $to,
            '[questions]'         => '',
            '[confirmation_link]' => home_url('/'),
            '[booking_link]'      => home_url('/'),
            '[cancel_link]'       => home_url('/'),
            '[waitlist_optout_link]' => home_url('/'),
            '[imprint_link]'      => TokenManager::imprintUrl(),
            '[post_link]'         => home_url('/'),
            '[current_date]'      => date_i18n(get_option('date_format'), strtotime('+5 days')),
            '[current_time]'      => '12:00 – 12:30',
        ];

        $sent = 0;
        $plainVars = array_merge($vars, [
            '[questions]' => "\n\n" . __('Additional information:', 'rrze-appointment')
                . "\n" . __('Preferred format', 'rrze-appointment') . ': ' . __('Video call', 'rrze-appointment'),
        ]);
        $htmlVars = array_merge($vars, [
            '[questions]' => '<h2 style="margin:28px 0 8px;color:#1f2937;font-size:20px;line-height:28px;">'
                . esc_html__('Additional information', 'rrze-appointment')
                . '</h2>'
                . MailTemplate::detailsTable([
                    __('Preferred format', 'rrze-appointment') => esc_html__('Video call', 'rrze-appointment'),
                ]),
        ]);

        foreach (self::TEMPLATE_TYPES as $type) {
            $tpl = $templateId > 0
                ? (MailTemplatePost::getTemplateForType($templateId, $type) ?? [])
                : [];
            $def = MailTemplatePost::getDefault($type);

            $subject = Settings::renderTemplate(!empty($tpl['subject']) ? $tpl['subject'] : $def['subject'], $vars);
            $plain   = Settings::renderTemplate(!empty($tpl['body'])    ? $tpl['body']    : $def['body'],    $plainVars);
            $html    = Settings::renderTemplate(!empty($tpl['body_html']) ? $tpl['body_html'] : $def['body_html'], $htmlVars);

            if (Settings::sendMail(
                $to,
                '[TEST] ' . $subject,
                $plain,
                $html,
                [],
                MailTemplate::statusForType($type)
            )) {
                $sent++;
            }
        }

        $redirect = add_query_arg([
            'page'      => self::PAGE_SLUG,
            'tab'       => 'templates',
            'test_sent' => $sent,
        ], admin_url('options-general.php'));
        wp_redirect($redirect);
        exit;
    }

    /**
     * Processes an administrator's booking cancellation request.
     */
    public function handleCancelPost(): void
    {
        if (
            self::getPostText('rrze_appt_action', true) !== 'cancel'
            || !current_user_can('manage_options')
        ) {
            return;
        }

        check_admin_referer('rrze_appt_cancel', 'rrze_appt_cancel_nonce');

        $slot = self::getPostText('cancel_slot');
        if ($slot !== '') {
            Bookings::cancel($slot);
        }

        wp_redirect(add_query_arg([
            'page' => self::BOOKINGS_PAGE_SLUG,
            'cancelled' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Renders the bookings administration page.
     */
    public function renderBookingsPage(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap rrze-appointment-settings-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Appointments', 'rrze-appointment'); ?></h1>
            <hr class="wp-header-end">
            <?php $this->renderTabBookings(); ?>
        </div>
        <?php
    }

    /**
     * Renders the reminder-days settings field.
     */
    public function renderReminderDaysField(): void
    {
        $value   = (int) self::get('reminder_days');
        $options = [0 => __('Disabled', 'rrze-appointment')];
        for ($i = 1; $i <= self::MAX_REMINDER_DAYS; $i++) {
            $options[$i] = $i;
        }
        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[reminder_days]">';
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
        $value = (int) self::get('retention_days');
        printf(
            '<input type="number" name="%s[retention_days]" value="%d" min="0" max="%d" step="1" class="small-text"> %s',
            esc_attr(self::OPTION_NAME),
            $value,
            self::MAX_RETENTION_DAYS,
            esc_html__('Completed bookings are permanently deleted this many days after the appointment ends (default: 30).', 'rrze-appointment')
        );
    }


    /**
     * Renders the tabbed plugin settings page.
     */
    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = self::getQueryText('tab', true) ?: 'general';
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
                    <?php $this->renderTabTemplates(); ?>
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

    private function renderTabTemplates(): void
    {
        $editId = self::getQueryInt('edit');

        // Notices
        if (self::hasQueryFlag('saved')) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Template saved.', 'rrze-appointment')
                . '</p></div>';
        }
        if (self::hasQueryFlag('draft')) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html__(
                    'Template saved as a draft. Add a template name to make it available in the editor.',
                    'rrze-appointment'
                )
                . '</p></div>';
        }
        if (self::hasQueryFlag('save_error')) {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('The template could not be saved. Please try again.', 'rrze-appointment')
                . '</p></div>';
        }
        if (self::hasQueryFlag('deleted')) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Template deleted.', 'rrze-appointment')
                . '</p></div>';
        }
        $inUse = self::getQueryText('inuse');
        if ($inUse !== '') {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . sprintf(
                    esc_html__(
                        'The template cannot be deleted because it is still in use: %s',
                        'rrze-appointment'
                    ),
                    esc_html($inUse)
                )
                . '</p></div>';
        }
        if (self::hasQueryFlag('test_sent')) {
            $sent = self::getQueryInt('test_sent');
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('%d test email(s) sent to %s.', 'rrze-appointment'), $sent, esc_html(wp_get_current_user()->user_email)) . '</p></div>';
        }

        if ($editId > 0 || self::hasQueryFlag('new')) {
            $this->renderTemplateForm($editId);
        } else {
            $this->renderTemplateList();
        }
    }

    private function renderTemplateList(): void
    {
        $templates = MailTemplatePost::getAll();
        $newUrl    = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates', 'new' => '1'], admin_url('options-general.php'));
        ?>
        <a href="<?php echo esc_url($newUrl); ?>" class="button button-primary" style="margin-bottom:1rem;">
            <?php esc_html_e('New Template', 'rrze-appointment'); ?>
        </a>

        <table class="widefat striped" style="margin-top:0.5rem;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Title', 'rrze-appointment'); ?></th>
                    <th><?php esc_html_e('Actions', 'rrze-appointment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e('Default', 'rrze-appointment'); ?></strong> <em style="color:#50575e;"><?php esc_html_e('(not editable)', 'rrze-appointment'); ?></em></td>
                    <td>
                        <form method="post" action="" style="display:inline;">
                            <?php wp_nonce_field('rrze_appt_test_mail', 'rrze_appt_test_nonce'); ?>
                            <input type="hidden" name="rrze_appt_action" value="test_mail">
                            <input type="hidden" name="tpl_id" value="0">
                            <button type="submit" class="button button-small"><?php esc_html_e('Send Test Email', 'rrze-appointment'); ?></button>
                        </form>
                    </td>
                </tr>
                <?php foreach ($templates as $tpl) :
                    $editUrl = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates', 'edit' => $tpl['id']], admin_url('options-general.php'));
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($tpl['title'] ?: __('(no title)', 'rrze-appointment')); ?></strong>
                            <?php if (($tpl['status'] ?? '') === 'draft') : ?>
                                <em style="color:#50575e;"> &mdash; <?php esc_html_e('Draft', 'rrze-appointment'); ?></em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($editUrl); ?>" class="button button-small"><?php esc_html_e('Edit', 'rrze-appointment'); ?></a>
                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field('rrze_appt_test_mail', 'rrze_appt_test_nonce'); ?>
                                <input type="hidden" name="rrze_appt_action" value="test_mail">
                                <input type="hidden" name="tpl_id" value="<?php echo esc_attr($tpl['id']); ?>">
                                <button type="submit" class="button button-small"><?php esc_html_e('Send Test Email', 'rrze-appointment'); ?></button>
                            </form>
                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field('rrze_appt_tpl_save', 'rrze_appt_tpl_nonce'); ?>
                                <input type="hidden" name="rrze_appt_tpl_action" value="delete">
                                <input type="hidden" name="tpl_id" value="<?php echo esc_attr($tpl['id']); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('<?php esc_attr_e('Really delete this template?', 'rrze-appointment'); ?>')">
                                    <?php esc_html_e('Delete', 'rrze-appointment'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        <?php
    }

    private function renderTemplateForm(int $id): void
    {
        $backUrl  = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates'], admin_url('options-general.php'));
        $title    = '';
        $isNew    = $id <= 0;
        $sections = ['booking_pending' => [], 'booking_pending_questions' => [], 'booking_opening_notification' => [], 'booking_booker' => [], 'booking_host' => [], 'reminder_admin' => [], 'reminder_booker' => [], 'cancellation' => [], 'waitlist_earlier_slot' => []];

        if ($id > 0) {
            $post = get_post($id);
            if ($post instanceof \WP_Post && $post->post_type === MailTemplatePost::POST_TYPE) {
                $title = $post->post_title;
                foreach (array_keys($sections) as $key) {
                    $sections[$key] = [
                        'subject'   => (string) get_post_meta($id, "tpl_{$key}_subject", true),
                        'body'      => (string) get_post_meta($id, "tpl_{$key}_body", true),
                        'body_html' => (string) get_post_meta($id, "tpl_{$key}_body_html", true),
                    ];
                }
            }
        }

        $sectionLabels = [
            'booking_pending' => __('Appointment request', 'rrze-appointment'),
            'booking_pending_questions' => __('Appointment request with questions', 'rrze-appointment'),
            'booking_opening_notification' => __('Booking opening notification', 'rrze-appointment'),
            'booking_booker'  => __('Confirmation to booking person', 'rrze-appointment'),
            'booking_host'    => __('Confirmation to host', 'rrze-appointment'),
            'reminder_admin'  => __('Reminder to host', 'rrze-appointment'),
            'reminder_booker' => __('Reminder to booking person', 'rrze-appointment'),
            'cancellation'    => __('Cancellation', 'rrze-appointment'),
            'waitlist_earlier_slot' => __('Earlier appointment available', 'rrze-appointment'),
        ];
        $sectionDescriptions = [
            'booking_pending' => __('Sent to the person booking so they can confirm their appointment request.', 'rrze-appointment'),
            'booking_pending_questions' => __('Sent instead of the regular appointment request when the confirmation page contains additional questions.', 'rrze-appointment'),
            'booking_opening_notification' => __('Sent when a requested appointment opens for booking.', 'rrze-appointment'),
            'booking_booker'  => __('Sent to the person booking after the appointment has been confirmed.', 'rrze-appointment'),
            'booking_host'    => __('Sent to the host after the appointment has been confirmed.', 'rrze-appointment'),
            'reminder_admin'  => __('Sent to the host before the appointment when reminders are enabled.', 'rrze-appointment'),
            'reminder_booker' => __('Sent to the person booking before the appointment when reminders are enabled.', 'rrze-appointment'),
            'cancellation'    => __('Sent when a confirmed appointment is cancelled.', 'rrze-appointment'),
            'waitlist_earlier_slot' => __('Sent when an earlier appointment becomes available for someone on the waitlist.', 'rrze-appointment'),
        ];
        ?>
        <a href="<?php echo esc_url($backUrl); ?>" class="rrze-appt-template-back">
            <span aria-hidden="true">&larr;</span> <?php esc_html_e('Back to mail templates', 'rrze-appointment'); ?>
        </a>

        <div class="rrze-appt-template-heading">
            <h2><?php echo esc_html($isNew ? __('Create mail template', 'rrze-appointment') : __('Edit mail template', 'rrze-appointment')); ?></h2>
            <p>
                <?php esc_html_e('Customize only the content you want to change. Empty fields automatically use the standard content.', 'rrze-appointment'); ?>
            </p>
        </div>

        <form method="post" action="" class="rrze-appt-template-form">
            <?php wp_nonce_field('rrze_appt_tpl_save', 'rrze_appt_tpl_nonce'); ?>
            <input type="hidden" name="rrze_appt_tpl_action" value="save">
            <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">

            <div class="rrze-appt-template-name">
                <label for="tpl_title">
                    <?php esc_html_e('Template name', 'rrze-appointment'); ?>
                    <span class="rrze-appt-required" aria-hidden="true">*</span>
                </label>
                <input type="text" id="tpl_title" name="title" value="<?php echo esc_attr($title); ?>"
                       class="large-text" required aria-describedby="tpl_title_help"
                       placeholder="<?php esc_attr_e('For example: Consultation hours', 'rrze-appointment'); ?>">
                <p class="description" id="tpl_title_help">
                    <?php esc_html_e('Use a name that makes this template easy to recognize in the editor.', 'rrze-appointment'); ?>
                </p>
            </div>

            <div class="rrze-appt-template-sections">
            <?php $sectionIndex = 0; ?>
            <?php foreach ($sectionLabels as $key => $label) :
                $s       = $sections[$key];
                $plainId = "tpl_{$key}_body";
                $htmlId  = "tpl_{$key}_body_html";
                $defaults = MailTemplatePost::getDefault($key);
                $hasCustomContent = trim((string) ($s['subject'] ?? '')) !== ''
                    || trim((string) ($s['body'] ?? '')) !== ''
                    || trim(wp_strip_all_tags((string) ($s['body_html'] ?? ''))) !== '';
            ?>
                <details class="rrze-appt-template-section" data-template-section <?php echo $sectionIndex === 0 ? 'open' : ''; ?>>
                    <summary>
                        <span class="rrze-appt-template-section__summary">
                            <strong><?php echo esc_html($label); ?></strong>
                            <span class="rrze-appt-template-section__description"><?php echo esc_html($sectionDescriptions[$key]); ?></span>
                        </span>
                        <span class="rrze-appt-template-status <?php echo $hasCustomContent ? 'is-customized' : ''; ?>"
                              data-template-status
                              data-default-label="<?php esc_attr_e('Uses standard content', 'rrze-appointment'); ?>"
                              data-custom-label="<?php esc_attr_e('Customized', 'rrze-appointment'); ?>">
                            <?php echo esc_html($hasCustomContent ? __('Customized', 'rrze-appointment') : __('Uses standard content', 'rrze-appointment')); ?>
                        </span>
                    </summary>

                    <div class="rrze-appt-template-section__content">
                        <div class="rrze-appt-template-field">
                            <label for="tpl_<?php echo esc_attr($key); ?>_subject"><?php esc_html_e('Subject', 'rrze-appointment'); ?></label>
                            <input type="text" id="tpl_<?php echo esc_attr($key); ?>_subject"
                                   name="<?php echo esc_attr($key); ?>_subject"
                                   value="<?php echo esc_attr($s['subject'] ?? ''); ?>" class="large-text"
                                   placeholder="<?php echo esc_attr($defaults['subject']); ?>">
                            <div class="rrze-appt-template-field__actions">
                                <?php $this->renderInsertButton("tpl_{$key}_subject"); ?>
                                <span class="description"><?php esc_html_e('Leave blank to use the standard subject.', 'rrze-appointment'); ?></span>
                            </div>
                        </div>

                        <fieldset class="rrze-appt-template-field">
                            <legend><?php esc_html_e('Email content', 'rrze-appointment'); ?></legend>
                            <?php $this->renderMailTabs(
                                $plainId,
                                $htmlId,
                                $key,
                                $s['body'] ?? '',
                                $s['body_html'] ?? '',
                                $defaults
                            ); ?>
                        </fieldset>
                    </div>
                </details>
                <?php $sectionIndex++; ?>
            <?php endforeach; ?>
            </div>

            <div class="rrze-appt-template-actions">
                <a href="<?php echo esc_url($backUrl); ?>" class="button"><?php esc_html_e('Cancel', 'rrze-appointment'); ?></a>
                <?php submit_button($isNew ? __('Create template', 'rrze-appointment') : __('Save changes', 'rrze-appointment'), 'primary', 'submit', false); ?>
            </div>
        </form>
        <?php
    }

    private function renderMailTabs(string $plainId, string $htmlId, string $nameKey, string $plainValue, string $htmlValue, array $defaults): void
    {
        $canEditHtml = MailTemplatePost::canEditHtml();
        $plainTabId   = $plainId . '_tab';
        $htmlTabId    = $htmlId . '_tab';
        $plainPanelId = $plainId . '_panel';
        $htmlPanelId  = $htmlId . '_panel';
        ?>
        <?php if (!$canEditHtml) : ?>
            <div class="rrze-appt-plain-content">
                <label class="rrze-appt-template-format-label" for="<?php echo esc_attr($plainId); ?>">
                    <?php esc_html_e('Plain text', 'rrze-appointment'); ?>
                </label>
        <?php else : ?>
        <div class="rrze-appt-tabs">
            <div class="rrze-appt-tab-nav" role="tablist" aria-label="<?php esc_attr_e('Email format', 'rrze-appointment'); ?>">
                <button type="button" id="<?php echo esc_attr($plainTabId); ?>" class="rrze-appt-tab-btn"
                        role="tab" aria-selected="true" aria-controls="<?php echo esc_attr($plainPanelId); ?>"
                        data-tab="plain">
                    <?php esc_html_e('Plain text', 'rrze-appointment'); ?>
                </button>
                <button type="button" id="<?php echo esc_attr($htmlTabId); ?>" class="rrze-appt-tab-btn"
                        role="tab" aria-selected="false" aria-controls="<?php echo esc_attr($htmlPanelId); ?>"
                        data-tab="html" tabindex="-1">
                    <?php esc_html_e('HTML', 'rrze-appointment'); ?>
                </button>
            </div>
            <div class="rrze-appt-tab-content">
                <div id="<?php echo esc_attr($plainPanelId); ?>" class="rrze-appt-tab-panel"
                     role="tabpanel" aria-labelledby="<?php echo esc_attr($plainTabId); ?>" data-panel="plain">
        <?php endif; ?>
                    <textarea id="<?php echo esc_attr($plainId); ?>" name="<?php echo esc_attr($nameKey); ?>_body"
                              rows="6" class="large-text"><?php echo esc_textarea($plainValue); ?></textarea>
                    <div class="rrze-appt-template-field__actions">
                        <?php $this->renderInsertButton($plainId); ?>
                        <span class="description"><?php esc_html_e('Leave blank to use the standard plain-text content.', 'rrze-appointment'); ?></span>
                    </div>
                    <details class="rrze-appt-default-preview">
                        <summary class="rrze-appt-default-preview__toggle"><?php esc_html_e('View standard content', 'rrze-appointment'); ?></summary>
                        <pre><?php echo esc_html($defaults['body']); ?></pre>
                    </details>
        <?php if (!$canEditHtml) : ?>
            </div>
        <?php else : ?>
                </div>
                <div id="<?php echo esc_attr($htmlPanelId); ?>" class="rrze-appt-tab-panel"
                     role="tabpanel" aria-labelledby="<?php echo esc_attr($htmlTabId); ?>"
                     data-panel="html" hidden>
                    <?php
                    wp_editor($htmlValue, $htmlId, [
                        'textarea_name' => $nameKey . '_body_html',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny'         => false,
                        'tinymce'       => true,
                        'quicktags'     => true,
                    ]);
                    ?>
                    <div class="rrze-appt-template-field__actions">
                        <?php $this->renderInsertButton($htmlId, true); ?>
                        <span class="description"><?php esc_html_e('Leave blank to use the standard HTML content.', 'rrze-appointment'); ?></span>
                    </div>
                    <details class="rrze-appt-default-preview">
                        <summary class="rrze-appt-default-preview__toggle"><?php esc_html_e('View standard content', 'rrze-appointment'); ?></summary>
                        <div class="rrze-appt-default-preview__html"><?php echo wp_kses_post($defaults['body_html']); ?></div>
                    </details>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    private function renderInsertButton(string $targetId, bool $isTinymce = false): void
    {
        echo '<div class="rrze-appt-insert">';
        printf(
            '<button type="button" class="button rrze-appt-insert-btn" data-target="%s" data-tinymce="%s" aria-expanded="false">%s <span aria-hidden="true">&#9660;</span></button>',
            esc_attr($targetId),
            $isTinymce ? '1' : '0',
            esc_html__('Insert placeholder', 'rrze-appointment')
        );
        echo '<ul class="rrze-appt-insert-dropdown" hidden>';
        foreach (self::getPlaceholders() as $tag => $desc) {
            printf(
                '<li><button type="button" class="rrze-appt-insert-tag" data-tag="%s"><code>%s</code><span class="rrze-appt-insert-tag__description">%s</span></button></li>',
                esc_attr($tag),
                esc_html($tag),
                esc_html($desc)
            );
        }
        echo '</ul></div>';
    }

    private function renderTabBookings(): void
    {
        if (self::hasQueryFlag('cancelled')) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Booking cancelled and cancellation emails sent.', 'rrze-appointment') . '</p></div>';
        }

        // Filter
        $filterDate = self::getQueryText('filter_date');
        $filterDateTo = self::getQueryText('filter_date_to');
        $filterPerson = self::getQueryInt('filter_person');
        $filterArgs = array_filter([
            'date_from' => $filterDate,
            'date_to' => $filterDateTo,
            'person_id' => $filterPerson ?: null,
        ]);
        $bookings = Bookings::getAll($filterArgs);
        $persons = Bookings::getPersonsFromBookings();
        $baseUrl = add_query_arg(['page' => self::BOOKINGS_PAGE_SLUG], admin_url('admin.php'));
        ?>
        <form method="get" action="" style="margin-bottom:1rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::BOOKINGS_PAGE_SLUG); ?>">
            <label>
                <?php esc_html_e('From', 'rrze-appointment'); ?>
                <input type="date" name="filter_date" value="<?php echo esc_attr($filterDate); ?>">
            </label>
            <label>
                <?php esc_html_e('To', 'rrze-appointment'); ?>
                <input type="date" name="filter_date_to" value="<?php echo esc_attr($filterDateTo); ?>">
            </label>
            <?php if (!empty($persons)) : ?>
            <label>
                <?php esc_html_e('Person', 'rrze-appointment'); ?>
                <select name="filter_person">
                    <option value="0"><?php esc_html_e('All', 'rrze-appointment'); ?></option>
                    <?php foreach ($persons as $pid => $pname) : ?>
                        <option value="<?php echo esc_attr($pid); ?>"<?php selected($filterPerson, $pid); ?>><?php echo esc_html($pname); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'rrze-appointment'); ?></button>
            <a href="<?php echo esc_url($baseUrl); ?>" class="button"><?php esc_html_e('Reset', 'rrze-appointment'); ?></a>
        </form>

        <?php if (empty($bookings)) : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Time', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Title', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Person', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Booker', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Email', 'rrze-appointment'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="7"><?php esc_html_e('No appointments found.', 'rrze-appointment'); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Time', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Title', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Person', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Booker', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Email', 'rrze-appointment'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b) :
                        $dateFormatted = date_i18n(get_option('date_format'), strtotime($b['date']));
                    ?>
                    <tr>
                        <td><?php echo esc_html($dateFormatted); ?></td>
                        <td><?php echo esc_html(str_replace('-', ' – ', $b['time'])); ?></td>
                        <td><?php echo esc_html($b['title']); ?></td>
                        <td><?php echo esc_html($b['person_name']); ?></td>
                        <td><?php echo esc_html($b['booker_name']); ?></td>
                        <td><?php echo esc_html($b['booker_email']); ?></td>
                        <td>
                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field('rrze_appt_cancel', 'rrze_appt_cancel_nonce'); ?>
                                <input type="hidden" name="rrze_appt_action" value="cancel">
                                <input type="hidden" name="cancel_slot" value="<?php echo esc_attr($b['slot']); ?>">
                                <button type="submit" class="button button-small"
                                    onclick="return confirm('<?php esc_attr_e('Really cancel this booking?', 'rrze-appointment'); ?>')">
                                    <?php esc_html_e('Cancel booking', 'rrze-appointment'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /**
     * Renders inline interactions used by the plugin's admin pages.
     */
    public function renderAdminJs(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, self::getAdminPageHooks(), true)) {
            return;
        }
        ?>
        <script>
        (function() {
            var lastField     = null;
            var lastPos       = 0;
            var savedBookmark = null;
            var savedEditorId = null;

            function updateSectionStatus(section) {
                if (!section) return;
                var status = section.querySelector('[data-template-status]');
                if (!status) return;
                var hasCustomContent = Array.prototype.some.call(
                    section.querySelectorAll('input[type="text"], textarea'),
                    function(field) {
                        if (field.id && typeof tinyMCE !== 'undefined' && tinyMCE.get(field.id)) {
                            return tinyMCE.get(field.id).getContent({ format: 'text' }).trim() !== '';
                        }
                        return field.value.trim() !== '';
                    }
                );
                status.classList.toggle('is-customized', hasCustomContent);
                status.textContent = hasCustomContent ? status.dataset.customLabel : status.dataset.defaultLabel;
            }

            document.querySelectorAll('[data-template-section]').forEach(function(section) {
                section.addEventListener('input', function() {
                    updateSectionStatus(section);
                });
            });

            var templateForm = document.querySelector('.rrze-appt-template-form');
            if (templateForm) {
                templateForm.addEventListener('submit', function() {
                    if (typeof tinyMCE !== 'undefined') {
                        tinyMCE.triggerSave();
                    }
                });
            }

            // --- Plaintext/HTML Tab-Switching ---
            document.querySelectorAll('.rrze-appt-tabs').forEach(function(tabs) {
                var btns   = tabs.querySelectorAll('.rrze-appt-tab-btn');
                var panels = tabs.querySelectorAll('.rrze-appt-tab-panel');

                function activateTab(btn, focusTab) {
                    var target = btn.dataset.tab;
                    btns.forEach(function(b) {
                        var active = b === btn;
                        b.setAttribute('aria-selected', active ? 'true' : 'false');
                        b.tabIndex = active ? 0 : -1;
                    });
                    panels.forEach(function(panel) {
                        panel.hidden = panel.dataset.panel !== target;
                    });
                    if (focusTab) btn.focus();
                    if (target === 'html' && typeof tinyMCE !== 'undefined') {
                        setTimeout(function() {
                            tabs.querySelectorAll('.rrze-appt-tab-panel[data-panel="html"] textarea').forEach(function(ta) {
                                if (tinyMCE.get(ta.id)) tinyMCE.get(ta.id).show();
                            });
                        }, 50);
                    }
                }

                btns.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        activateTab(btn, false);
                    });
                    btn.addEventListener('keydown', function(event) {
                        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                        event.preventDefault();
                        var index = Array.prototype.indexOf.call(btns, btn);
                        if (event.key === 'Home') index = 0;
                        if (event.key === 'End') index = btns.length - 1;
                        if (event.key === 'ArrowLeft') index = (index - 1 + btns.length) % btns.length;
                        if (event.key === 'ArrowRight') index = (index + 1) % btns.length;
                        activateTab(btns[index], true);
                    });
                });
            });

            // --- Cursorposition merken (Textarea/Input) ---
            document.querySelectorAll('.rrze-appt-insert-btn').forEach(function(btn) {
                if (btn.dataset.tinymce === '1') return;
                var field = document.getElementById(btn.dataset.target);
                if (!field) return;
                ['focus', 'click', 'keyup'].forEach(function(ev) {
                    field.addEventListener(ev, function() {
                        lastField = field;
                        lastPos   = field.selectionStart ?? field.value.length;
                    });
                });
            });

            // --- TinyMCE Bookmark sichern ---
            document.addEventListener('focusin', function(e) {
                if (e.target && e.target.classList.contains('rrze-appt-insert-btn') && e.target.dataset.tinymce === '1') {
                    var ed = typeof tinyMCE !== 'undefined' ? tinyMCE.get(e.target.dataset.target) : null;
                    if (ed) { savedBookmark = ed.selection.getBookmark(2, true); savedEditorId = e.target.dataset.target; }
                }
            }, true);

            function closeDropdowns() {
                document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(dropdown) {
                    dropdown.hidden = true;
                    var trigger = dropdown.previousElementSibling;
                    if (trigger) trigger.setAttribute('aria-expanded', 'false');
                });
            }

            // --- Dropdown öffnen/schließen ---
            document.querySelectorAll('.rrze-appt-insert-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var dropdown = btn.nextElementSibling;
                    var isOpen   = !dropdown.hidden;
                    closeDropdowns();
                    if (!isOpen) {
                        if (btn.dataset.tinymce === '1') {
                            var ed = typeof tinyMCE !== 'undefined' ? tinyMCE.get(btn.dataset.target) : null;
                            if (ed && !savedBookmark) { savedBookmark = ed.selection.getBookmark(2, true); savedEditorId = btn.dataset.target; }
                        }
                        dropdown.hidden = false;
                        btn.setAttribute('aria-expanded', 'true');
                        dropdown.querySelectorAll('.rrze-appt-insert-tag').forEach(function(t) {
                            t.dataset.insertTarget  = btn.dataset.target;
                            t.dataset.insertTinymce = btn.dataset.tinymce;
                        });
                        var firstItem = dropdown.querySelector('.rrze-appt-insert-tag');
                        if (firstItem) firstItem.focus();
                    }
                });
            });

            // --- Platzhalter einfügen ---
            document.querySelectorAll('.rrze-appt-insert-tag').forEach(function(tagBtn) {
                tagBtn.addEventListener('click', function() {
                    var tag       = tagBtn.dataset.tag;
                    var targetId  = tagBtn.dataset.insertTarget;
                    var isTinymce = tagBtn.dataset.insertTinymce === '1';
                    closeDropdowns();

                    if (isTinymce && targetId && typeof tinyMCE !== 'undefined') {
                        var ed = tinyMCE.get(targetId);
                        if (ed) {
                            ed.focus();
                            if (savedBookmark && savedEditorId === targetId) ed.selection.moveToBookmark(savedBookmark);
                            ed.insertContent(tag);
                            savedBookmark = null; savedEditorId = null;
                            updateSectionStatus(ed.getElement().closest('[data-template-section]'));
                            return;
                        }
                    }

                    var field = targetId ? document.getElementById(targetId) : lastField;
                    if (!field) { var td = tagBtn.closest('td'); field = td ? (td.querySelector('textarea') || td.querySelector('input[type=text]')) : null; }
                    if (!field) return;
                    var pos = (field === lastField) ? lastPos : (field.selectionStart ?? field.value.length);
                    field.value = field.value.slice(0, pos) + tag + field.value.slice(pos);
                    var newPos = pos + tag.length;
                    field.focus();
                    field.setSelectionRange(newPos, newPos);
                    lastField = field; lastPos = newPos;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });

            // --- Klick außerhalb ---
            document.addEventListener('click', function() {
                closeDropdowns();
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') closeDropdowns();
            });
        }());
        </script>
        <?php
    }

    /**
     * Loads the settings UI stylesheet on the plugin's administration pages.
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if (!in_array($hook, self::getAdminPageHooks(), true)) {
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

    /**
     * Returns WordPress screen hooks belonging to this settings controller.
     *
     * @return array<int, string>
     */
    private static function getAdminPageHooks(): array
    {
        return [
            'settings_page_' . self::PAGE_SLUG,
            'toplevel_page_' . self::BOOKINGS_PAGE_SLUG,
        ];
    }
}
