<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class Settings
{
    const OPTION_NAME = 'rrze_appointment_settings';
    const PAGE_SLUG   = 'rrze-appointment-settings';

    public static function getPlaceholders(): array
    {
        return [
            '[title]'             => __('Title of the appointment', 'rrze-appointment'),
            '[date]'              => __('Date of the appointment', 'rrze-appointment'),
            '[time]'              => __('Time (from – to)', 'rrze-appointment'),
            '[location]'          => __('Location', 'rrze-appointment'),
            '[person_name]'       => __('Inviting person', 'rrze-appointment'),
            '[name]'              => __('Booking person (name)', 'rrze-appointment'),
            '[email]'             => __('Booking person (email)', 'rrze-appointment'),
            '[message]'           => __('Booking person (message)', 'rrze-appointment'),
            '[confirmation_link]' => __('Link to booking confirmation', 'rrze-appointment'),
            '[cancel_link]'       => __('Link to cancel', 'rrze-appointment'),
            '[imprint_link]'      => __('Link to imprint', 'rrze-appointment'),
        ];
    }

    public static function getDefaults(): array
    {
        return ['reminder_days' => 0];
    }

    public static function get(string $key): mixed
    {
        $options  = get_option(self::OPTION_NAME, []);
        $defaults = self::getDefaults();
        return (isset($options[$key]) && $options[$key] !== '') ? $options[$key] : ($defaults[$key] ?? null);
    }

    public static function renderTemplate(string $template, array $vars): string
    {
        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    public static function sendMail(string $to, string $subject, string $plain, string $html, array $attachments = []): bool
    {
        $GLOBALS['rrze_appointment_html_body'] = MailTemplate::wrap($html, $subject);
        add_action('phpmailer_init', [self::class, 'addHtmlPart']);
        $sent = wp_mail($to, $subject, $plain, [], $attachments);
        remove_action('phpmailer_init', [self::class, 'addHtmlPart']);
        unset($GLOBALS['rrze_appointment_html_body']);
        return $sent;
    }

    public static function addHtmlPart(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void
    {
        $html = $GLOBALS['rrze_appointment_html_body'] ?? '';
        if ($html === '') return;
        $phpmailer->CharSet  = 'UTF-8';
        $phpmailer->AltBody  = $phpmailer->Body;
        $phpmailer->Body     = $html;
        $phpmailer->isHTML(true);
        error_log('RRZE addHtmlPart: AltBody snippet=' . substr($phpmailer->AltBody, 0, 100));
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'handleTemplatePost']);
        add_action('admin_init', [$this, 'handleCancelPost']);
        add_action('admin_init', [$this, 'handleTestMail']);
        add_action('admin_print_footer_scripts', [$this, 'renderAdminJs']);
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

        add_menu_page(
            __('Appointments', 'rrze-appointment'),
            __('Appointments', 'rrze-appointment'),
            'manage_options',
            'rrze-appointment-bookings',
            [$this, 'renderBookingsPage'],
            'dashicons-calendar-alt',
            30
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            'rrze_appointment_settings_group',
            self::OPTION_NAME,
            ['sanitize_callback' => [$this, 'sanitize'], 'default' => self::getDefaults()]
        );
        add_settings_section('rrze_appointment_general', '', '__return_false', self::PAGE_SLUG);
        add_settings_field('reminder_days', __('Reminder Email', 'rrze-appointment'), [$this, 'renderReminderDaysField'], self::PAGE_SLUG, 'rrze_appointment_general');
    }

    public function sanitize(array $input): array
    {
        return ['reminder_days' => (int) ($input['reminder_days'] ?? 0)];
    }

    /**
     * Verarbeitet POST-Requests für Vorlagen (Speichern / Löschen).
     */
    public function handleTemplatePost(): void
    {
        if (!isset($_POST['rrze_appt_tpl_action'])) return;
        if (!current_user_can('manage_options')) return;
        check_admin_referer('rrze_appt_tpl_save', 'rrze_appt_tpl_nonce');

        $action = sanitize_key($_POST['rrze_appt_tpl_action']);

        if ($action === 'delete') {
            $id    = (int) ($_POST['tpl_id'] ?? 0);
            $inUse = $id > 0 ? MailTemplatePost::isInUse($id) : [];
            if (!empty($inUse)) {
                $titles = implode(', ', array_column($inUse, 'title'));
                wp_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates', 'inuse' => urlencode($titles)], admin_url('options-general.php')));
                exit;
            }
            if ($id > 0) MailTemplatePost::delete($id);
            wp_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates', 'deleted' => '1'], admin_url('options-general.php')));
            exit;
        }

        if (in_array($action, ['save', 'new'], true)) {
            // Check if all required fields are filled
            $isDraft = false;
            $title   = sanitize_text_field($_POST['title'] ?? '');
            if (empty($title)) {
                $isDraft = true;
            } else {
                foreach (['booking_pending', 'booking_booker', 'booking_host', 'reminder_admin', 'reminder_booker', 'cancellation'] as $key) {
                    $subject = sanitize_text_field($_POST["{$key}_subject"] ?? '');
                    $body    = sanitize_textarea_field($_POST["{$key}_body"] ?? '');
                    if (empty($subject) || empty($body)) {
                        $isDraft = true;
                        break;
                    }
                }
            }
            $result = MailTemplatePost::save($_POST, $isDraft);
            $id     = is_wp_error($result) ? 0 : $result;
            $params = ['page' => self::PAGE_SLUG, 'tab' => 'templates', 'edit' => $id];
            $params[$isDraft ? 'draft' : 'saved'] = '1';
            wp_redirect(add_query_arg($params, admin_url('options-general.php')));
            exit;
        }
    }

    public function handleTestMail(): void
    {
        if (($_POST['rrze_appt_action'] ?? '') !== 'test_mail') return;
        if (!current_user_can('manage_options')) return;
        check_admin_referer('rrze_appt_test_mail', 'rrze_appt_test_nonce');

        $tplId = (int) ($_POST['tpl_id'] ?? 0);
        $user  = wp_get_current_user();
        $to    = $user->user_email;

        $vars = [
            '[title]'             => 'Test lecture on sample topics',
            '[date]'              => date_i18n(get_option('date_format'), strtotime('+3 days')),
            '[time]'              => '10:00 – 10:30',
            '[location]'          => 'Room 1.234, Sample Building',
            '[person_name]'       => 'Prof. Dr. Max Sample',
            '[name]'              => 'Jane Sample',
            '[email]'             => $to,
            '[message]'           => 'I have a brief question about the topic.',
            '[confirmation_link]' => home_url('/'),
            '[cancel_link]'       => home_url('/'),
            '[imprint_link]'      => TokenManager::imprintUrl(),
        ];

        $types = ['booking_pending', 'booking_booker', 'booking_host', 'reminder_admin', 'reminder_booker', 'cancellation'];
        $sent  = 0;

        foreach ($types as $type) {
            $tpl = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, $type) ?? []) : [];
            $def = MailTemplatePost::getDefault($type);

            $subject = Settings::renderTemplate(!empty($tpl['subject']) ? $tpl['subject'] : $def['subject'], $vars);
            $plain   = Settings::renderTemplate(!empty($tpl['body'])    ? $tpl['body']    : $def['body'],    $vars);
            $html    = Settings::renderTemplate(!empty($tpl['body_html']) ? $tpl['body_html'] : $def['body_html'], $vars);

            if (Settings::sendMail($to, '[TEST] ' . $subject, $plain, $html)) $sent++;
        }

        $redirect = add_query_arg([
            'page'      => self::PAGE_SLUG,
            'tab'       => 'templates',
            'test_sent' => $sent,
        ], admin_url('options-general.php'));
        wp_redirect($redirect);
        exit;
    }

    public function handleCancelPost(): void
    {
        if (($_POST['rrze_appt_action'] ?? '') !== 'cancel') return;
        if (!current_user_can('manage_options')) return;
        check_admin_referer('rrze_appt_cancel', 'rrze_appt_cancel_nonce');

        $slot = sanitize_text_field($_POST['cancel_slot'] ?? '');
        if ($slot) Bookings::cancel($slot);

        wp_redirect(add_query_arg(['page' => 'rrze-appointment-bookings', 'cancelled' => '1'], admin_url('admin.php')));
        exit;
    }

    public function renderBookingsPage(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Appointments', 'rrze-appointment'); ?></h1>
            <?php $this->renderTabBookings(); ?>
        </div>
        <?php
    }

    public function renderReminderDaysField(): void
    {
        $value   = (int) self::get('reminder_days');
        $options = [0 => __('Disabled', 'rrze-appointment')];
        for ($i = 1; $i <= 7; $i++) {
            $options[$i] = $i;
        }
        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[reminder_days]">';
        foreach ($options as $val => $label) {
            printf('<option value="%d"%s>%s</option>', $val, selected($value, $val, false), esc_html($label));
        }
        echo '</select> ' . esc_html__('days before the appointment.', 'rrze-appointment');
    }


    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) return;

        $tab = sanitize_key($_GET['tab'] ?? 'general');
        $tabs = [
            'general'   => __('General', 'rrze-appointment'),
            'templates' => __('Mail Templates', 'rrze-appointment'),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

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
            settings_fields('rrze_appointment_settings_group');
            do_settings_sections(self::PAGE_SLUG);
            submit_button();
            ?>
        </form>
        <?php
    }

    private function renderTabTemplates(): void
    {
        $editId = (int) ($_GET['edit'] ?? 0);

        // Notices
        if (!empty($_GET['saved']))   echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Template saved.', 'rrze-appointment') . '</p></div>';
        if (!empty($_GET['draft']))    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Template saved as draft. Please fill in all fields (title, subject and body for all sections) to publish the template.', 'rrze-appointment') . '</p></div>';
        if (!empty($_GET['deleted']))  echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Template deleted.', 'rrze-appointment') . '</p></div>';
        if (!empty($_GET['inuse']))    echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(esc_html__('The template cannot be deleted because it is still in use: %s', 'rrze-appointment'), esc_html(urldecode($_GET['inuse']))) . '</p></div>';
        if (isset($_GET['test_sent'])) {
            $sent = (int) $_GET['test_sent'];
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('%d test email(s) sent to %s.', 'rrze-appointment'), $sent, esc_html(wp_get_current_user()->user_email)) . '</p></div>';
        }

        if ($editId > 0 || !empty($_GET['new'])) {
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
        $sections = ['booking_pending' => [], 'booking_booker' => [], 'booking_host' => [], 'reminder_admin' => [], 'reminder_booker' => [], 'cancellation' => []];

        if ($id > 0) {
            $post = get_post($id);
            if ($post) {
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
            'booking_booker'  => __('Confirmation to booking person', 'rrze-appointment'),
            'booking_host'    => __('Confirmation to host', 'rrze-appointment'),
            'reminder_admin'  => __('Reminder to host', 'rrze-appointment'),
            'reminder_booker' => __('Reminder to booking person', 'rrze-appointment'),
            'cancellation'    => __('Cancellation', 'rrze-appointment'),
        ];
        ?>
        <a href="<?php echo esc_url($backUrl); ?>" class="button" style="margin-bottom:1rem;">
            &larr; <?php esc_html_e('Back to list', 'rrze-appointment'); ?>
        </a>

        <form method="post" action="">
            <?php wp_nonce_field('rrze_appt_tpl_save', 'rrze_appt_tpl_nonce'); ?>
            <input type="hidden" name="rrze_appt_tpl_action" value="save">
            <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">

            <table class="form-table">
                <tr>
                    <th><label for="tpl_title"><?php esc_html_e('Template title', 'rrze-appointment'); ?></label></th>
                    <td><input type="text" id="tpl_title" name="title" value="<?php echo esc_attr($title); ?>" class="large-text" required></td>
                </tr>
            </table>

            <?php foreach ($sectionLabels as $key => $label) :
                $s       = $sections[$key];
                $plainId = "tpl_{$key}_body";
                $htmlId  = "tpl_{$key}_body_html";
            ?>
                <h2 style="border-top:1px solid #dcdcde;padding-top:1rem;margin-top:2rem;"><?php echo esc_html($label); ?></h2>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th><label for="tpl_<?php echo esc_attr($key); ?>_subject"><?php esc_html_e('Subject', 'rrze-appointment'); ?></label></th>
                        <td>
                            <input type="text" id="tpl_<?php echo esc_attr($key); ?>_subject"
                                   name="<?php echo esc_attr($key); ?>_subject"
                                   value="<?php echo esc_attr($s['subject'] ?? ''); ?>" class="large-text">
                            <?php $this->renderInsertButton("tpl_{$key}_subject"); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Mail body', 'rrze-appointment'); ?></th>
                        <td><?php $this->renderMailTabs($plainId, $htmlId, $key, $s['body'] ?? '', $s['body_html'] ?? ''); ?></td>
                    </tr>
                </table>
            <?php endforeach; ?>

            <?php submit_button(__('Save template', 'rrze-appointment')); ?>
        </form>
        <?php
    }

    private function renderMailTabs(string $plainId, string $htmlId, string $nameKey, string $plainValue, string $htmlValue): void
    {
        ?>
        <div class="rrze-appt-tabs">
            <div class="rrze-appt-tab-nav" style="display:flex;gap:0;margin-bottom:-1px;">
                <button type="button" class="rrze-appt-tab-btn button" data-tab="plain" style="border-bottom-color:#fff;z-index:1;">
                    <?php esc_html_e('Plain text', 'rrze-appointment'); ?>
                </button>
                <button type="button" class="rrze-appt-tab-btn button" data-tab="html" style="background:#f6f7f7;border-bottom-color:#dcdcde;">
                    <?php esc_html_e('HTML', 'rrze-appointment'); ?>
                </button>
            </div>
            <div style="border:1px solid #dcdcde;padding:0.75rem;">
                <div class="rrze-appt-tab-panel" data-panel="plain">
                    <textarea id="<?php echo esc_attr($plainId); ?>" name="<?php echo esc_attr($nameKey); ?>_body"
                              rows="6" class="large-text"><?php echo esc_textarea($plainValue); ?></textarea>
                    <?php $this->renderInsertButton($plainId); ?>
                </div>
                <div class="rrze-appt-tab-panel" data-panel="html" style="display:none;">
                    <?php
                    wp_editor($htmlValue, $htmlId, [
                        'textarea_name' => $nameKey . '_body_html',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny'         => false,
                        'tinymce'       => true,
                        'quicktags'     => true,
                    ]);
                    $this->renderInsertButton($htmlId, true);
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderInsertButton(string $targetId, bool $isTinymce = false): void
    {
        echo '<div style="position:relative;display:inline-block;margin-top:0.4rem;">';
        printf(
            '<button type="button" class="button rrze-appt-insert-btn" data-target="%s" data-tinymce="%s">%s &#9660;</button>',
            esc_attr($targetId),
            $isTinymce ? '1' : '0',
            esc_html__('Insert placeholder', 'rrze-appointment')
        );
        echo '<ul class="rrze-appt-insert-dropdown" style="display:none;position:absolute;z-index:100;background:#fff;border:1px solid #dcdcde;box-shadow:0 2px 6px rgba(0,0,0,.15);margin:0;padding:0;list-style:none;min-width:220px;">';
        foreach (self::getPlaceholders() as $tag => $desc) {
            printf(
                '<li><button type="button" class="rrze-appt-insert-tag" data-tag="%s" style="display:block;width:100%%;text-align:left;padding:6px 12px;background:none;border:none;cursor:pointer;font-size:13px;"><code style="white-space: nowrap;">%s</code></button></li>',
                esc_attr($tag),
                esc_html($desc)
            );
        }
        echo '</ul></div>';
    }

    private function renderTabBookings(): void
    {
        if (!empty($_GET['cancelled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Booking cancelled and cancellation emails sent.', 'rrze-appointment') . '</p></div>';
        }

        // Filter
        $filterDate     = sanitize_text_field($_GET['filter_date'] ?? '');
        $filterDateTo   = sanitize_text_field($_GET['filter_date_to'] ?? '');
        $filterPerson   = (int) ($_GET['filter_person'] ?? 0);
        $filterArgs     = array_filter(['date_from' => $filterDate, 'date_to' => $filterDateTo, 'person_id' => $filterPerson ?: null]);
        $bookings       = Bookings::getAll($filterArgs);
        $persons        = Bookings::getPersonsFromBookings();
        $baseUrl        = add_query_arg(['page' => 'rrze-appointment-bookings'], admin_url('admin.php'));
        ?>
        <form method="get" action="" style="margin-bottom:1rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="page" value="rrze-appointment-bookings">
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
            <p><?php esc_html_e('No appointments found.', 'rrze-appointment'); ?></p>
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

    public function renderAdminJs(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['settings_page_' . self::PAGE_SLUG, 'toplevel_page_rrze-appointment-bookings'], true)) return;
        ?>
        <script>
        (function() {
            var lastField     = null;
            var lastPos       = 0;
            var savedBookmark = null;
            var savedEditorId = null;

            // --- Plaintext/HTML Tab-Switching ---
            document.querySelectorAll('.rrze-appt-tabs').forEach(function(tabs) {
                var btns   = tabs.querySelectorAll('.rrze-appt-tab-btn');
                var panels = tabs.querySelectorAll('.rrze-appt-tab-panel');
                btns.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var target = btn.dataset.tab;
                        btns.forEach(function(b) {
                            var active = b.dataset.tab === target;
                            b.style.background        = active ? '#fff' : '#f6f7f7';
                            b.style.borderBottomColor = active ? '#fff' : '#dcdcde';
                            b.style.zIndex            = active ? '1' : '0';
                        });
                        panels.forEach(function(p) {
                            p.style.display = p.dataset.panel === target ? '' : 'none';
                        });
                        if (target === 'html' && typeof tinyMCE !== 'undefined') {
                            setTimeout(function() {
                                tabs.querySelectorAll('.rrze-appt-tab-panel[data-panel="html"] textarea').forEach(function(ta) {
                                    if (tinyMCE.get(ta.id)) tinyMCE.get(ta.id).show();
                                });
                            }, 50);
                        }
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

            // --- Dropdown öffnen/schließen ---
            document.querySelectorAll('.rrze-appt-insert-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var dropdown = btn.nextElementSibling;
                    var isOpen   = dropdown.style.display === 'block';
                    document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });
                    if (!isOpen) {
                        if (btn.dataset.tinymce === '1') {
                            var ed = typeof tinyMCE !== 'undefined' ? tinyMCE.get(btn.dataset.target) : null;
                            if (ed && !savedBookmark) { savedBookmark = ed.selection.getBookmark(2, true); savedEditorId = btn.dataset.target; }
                        }
                        dropdown.style.display = 'block';
                        dropdown.querySelectorAll('.rrze-appt-insert-tag').forEach(function(t) {
                            t.dataset.insertTarget  = btn.dataset.target;
                            t.dataset.insertTinymce = btn.dataset.tinymce;
                        });
                    }
                });
            });

            // --- Hover ---
            document.querySelectorAll('.rrze-appt-insert-tag').forEach(function(t) {
                t.addEventListener('mouseenter', function() { t.style.background = '#f0f6fc'; });
                t.addEventListener('mouseleave', function() { t.style.background = 'none'; });
            });

            // --- Platzhalter einfügen ---
            document.querySelectorAll('.rrze-appt-insert-tag').forEach(function(tagBtn) {
                tagBtn.addEventListener('click', function() {
                    var tag       = tagBtn.dataset.tag;
                    var targetId  = tagBtn.dataset.insertTarget;
                    var isTinymce = tagBtn.dataset.insertTinymce === '1';
                    document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });

                    if (isTinymce && targetId && typeof tinyMCE !== 'undefined') {
                        var ed = tinyMCE.get(targetId);
                        if (ed) {
                            ed.focus();
                            if (savedBookmark && savedEditorId === targetId) ed.selection.moveToBookmark(savedBookmark);
                            ed.insertContent(tag);
                            savedBookmark = null; savedEditorId = null;
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
                });
            });

            // --- Klick außerhalb ---
            document.addEventListener('click', function() {
                document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });
            });
        }());
        </script>
        <?php
    }
}
