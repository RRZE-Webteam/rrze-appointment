<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class Settings
{
    const OPTION_NAME = 'rrze_appointment_settings';
    const PAGE_SLUG   = 'rrze-appointment-settings';

    const PLACEHOLDERS = [
        '[titel]'              => 'Titel des Termins',
        '[datum]'              => 'Datum des Termins',
        '[uhrzeit]'            => 'Uhrzeit (von – bis)',
        '[ort]'                => 'Ort / Location',
        '[person_name]'        => 'Name der zuständigen Person',
        '[name]'               => 'Name des Buchenden',
        '[email]'              => 'E-Mail des Buchenden',
        '[bestaetigungs_link]' => 'Link zur Buchungsbestätigung',
        '[storno_link]'        => 'Link zum Stornieren',
        '[impressum_link]'     => 'Link zum Impressum',
    ];

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
        $sent = wp_mail($to, $subject, $plain, ['Content-Type: text/plain; charset=UTF-8'], $attachments);
        remove_action('phpmailer_init', [self::class, 'addHtmlPart']);
        unset($GLOBALS['rrze_appointment_html_body']);
        return $sent;
    }

    public static function addHtmlPart(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void
    {
        $html = $GLOBALS['rrze_appointment_html_body'] ?? '';
        if ($html === '') return;
        $phpmailer->AltBody = $phpmailer->Body;
        $phpmailer->isHTML(true);
        $phpmailer->Body = $html;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'handleTemplatePost']);
        add_action('admin_init', [$this, 'handleCancelPost']);
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
            __('Buchungen', 'rrze-appointment'),
            __('Buchungen', 'rrze-appointment'),
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
        add_settings_field('reminder_days', __('Erinnerungsmail', 'rrze-appointment'), [$this, 'renderReminderDaysField'], self::PAGE_SLUG, 'rrze_appointment_general');
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
            $result = MailTemplatePost::save($_POST);
            $id = is_wp_error($result) ? 0 : $result;
            wp_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates', 'saved' => '1', 'edit' => $id], admin_url('options-general.php')));
            exit;
        }
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
            <h1><?php esc_html_e('Buchungen', 'rrze-appointment'); ?></h1>
            <?php $this->renderTabBookings(); ?>
        </div>
        <?php
    }

    public function renderReminderDaysField(): void
    {
        $value   = (int) self::get('reminder_days');
        $options = [0 => __('Deaktiviert', 'rrze-appointment')];
        for ($i = 1; $i <= 7; $i++) {
            $options[$i] = $i;
        }
        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[reminder_days]">';
        foreach ($options as $val => $label) {
            printf('<option value="%d"%s>%s</option>', $val, selected($value, $val, false), esc_html($label));
        }
        echo '</select> ' . esc_html__('Tage vor dem Termin versenden.', 'rrze-appointment');
    }


    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) return;

        $tab = sanitize_key($_GET['tab'] ?? 'general');
        $tabs = [
            'general'   => __('Allgemein', 'rrze-appointment'),
            'templates' => __('Mail-Vorlagen', 'rrze-appointment'),
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
        if (!empty($_GET['saved']))   echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Vorlage gespeichert.', 'rrze-appointment') . '</p></div>';
        if (!empty($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Vorlage gelöscht.', 'rrze-appointment') . '</p></div>';
        if (!empty($_GET['inuse']))   echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Die Vorlage kann nicht gelöscht werden, da sie noch verwendet wird.', 'rrze-appointment') . '</p></div>';

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
            <?php esc_html_e('Neue Vorlage', 'rrze-appointment'); ?>
        </a>

        <?php if (empty($templates)) : ?>
            <p><?php esc_html_e('Noch keine Vorlagen vorhanden.', 'rrze-appointment'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="margin-top:0.5rem;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Titel', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Aktionen', 'rrze-appointment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $tpl) :
                        $editUrl   = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates', 'edit' => $tpl['id']], admin_url('options-general.php'));
                        $deleteUrl = wp_nonce_url(
                            add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates'], admin_url('options-general.php')),
                            'rrze_appt_tpl_save', 'rrze_appt_tpl_nonce'
                        );
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($tpl['title'] ?: __('(kein Titel)', 'rrze-appointment')); ?></strong></td>
                            <td>
                                <a href="<?php echo esc_url($editUrl); ?>" class="button button-small"><?php esc_html_e('Bearbeiten', 'rrze-appointment'); ?></a>
                                <form method="post" action="" style="display:inline;">
                                    <?php wp_nonce_field('rrze_appt_tpl_save', 'rrze_appt_tpl_nonce'); ?>
                                    <input type="hidden" name="rrze_appt_tpl_action" value="delete">
                                    <input type="hidden" name="tpl_id" value="<?php echo esc_attr($tpl['id']); ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('<?php esc_attr_e('Vorlage wirklich löschen?', 'rrze-appointment'); ?>')">
                                        <?php esc_html_e('Löschen', 'rrze-appointment'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    private function renderTemplateForm(int $id): void
    {
        $backUrl  = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'templates'], admin_url('options-general.php'));
        $title    = '';
        $sections = ['booking_pending' => [], 'booking' => [], 'reminder_admin' => [], 'reminder_booker' => [], 'cancellation' => []];

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
            'booking_pending' => __('Mail A: Buchungsanfrage (an Buchenden) — verfügbar: [bestaetigungs_link], [impressum_link]', 'rrze-appointment'),
            'booking'         => __('Mail B: Buchungsbestätigung (an Buchenden + Person/Admin) — verfügbar: [storno_link], [impressum_link]', 'rrze-appointment'),
            'reminder_admin'  => __('Erinnerungsmail (an Person / Admin) — verfügbar: [impressum_link]', 'rrze-appointment'),
            'reminder_booker' => __('Erinnerungsmail (an Buchenden) — verfügbar: [impressum_link]', 'rrze-appointment'),
            'cancellation'    => __('Stornierung (an alle) — verfügbar: [impressum_link]', 'rrze-appointment'),
        ];
        ?>
        <a href="<?php echo esc_url($backUrl); ?>" class="button" style="margin-bottom:1rem;">
            &larr; <?php esc_html_e('Zurück zur Liste', 'rrze-appointment'); ?>
        </a>

        <form method="post" action="">
            <?php wp_nonce_field('rrze_appt_tpl_save', 'rrze_appt_tpl_nonce'); ?>
            <input type="hidden" name="rrze_appt_tpl_action" value="save">
            <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">

            <table class="form-table">
                <tr>
                    <th><label for="tpl_title"><?php esc_html_e('Titel der Vorlage', 'rrze-appointment'); ?></label></th>
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
                        <th><label for="tpl_<?php echo esc_attr($key); ?>_subject"><?php esc_html_e('Betreff', 'rrze-appointment'); ?></label></th>
                        <td>
                            <input type="text" id="tpl_<?php echo esc_attr($key); ?>_subject"
                                   name="<?php echo esc_attr($key); ?>_subject"
                                   value="<?php echo esc_attr($s['subject'] ?? ''); ?>" class="large-text">
                            <?php $this->renderInsertButton("tpl_{$key}_subject"); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Mailtext', 'rrze-appointment'); ?></th>
                        <td><?php $this->renderMailTabs($plainId, $htmlId, $key, $s['body'] ?? '', $s['body_html'] ?? ''); ?></td>
                    </tr>
                </table>
            <?php endforeach; ?>

            <?php submit_button(__('Vorlage speichern', 'rrze-appointment')); ?>
        </form>
        <?php
    }

    private function renderMailTabs(string $plainId, string $htmlId, string $nameKey, string $plainValue, string $htmlValue): void
    {
        ?>
        <div class="rrze-appt-tabs">
            <div class="rrze-appt-tab-nav" style="display:flex;gap:0;margin-bottom:-1px;">
                <button type="button" class="rrze-appt-tab-btn button" data-tab="plain" style="border-bottom-color:#fff;z-index:1;">
                    <?php esc_html_e('Plaintext', 'rrze-appointment'); ?>
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
            esc_html__('Platzhalter einfügen', 'rrze-appointment')
        );
        echo '<ul class="rrze-appt-insert-dropdown" style="display:none;position:absolute;z-index:100;background:#fff;border:1px solid #dcdcde;box-shadow:0 2px 6px rgba(0,0,0,.15);margin:0;padding:0;list-style:none;min-width:220px;">';
        foreach (self::PLACEHOLDERS as $tag => $desc) {
            printf(
                '<li><button type="button" class="rrze-appt-insert-tag" data-tag="%s" style="display:block;width:100%%;text-align:left;padding:6px 12px;background:none;border:none;cursor:pointer;font-size:13px;"><code>%s</code> <span style="color:#50575e;">%s</span></button></li>',
                esc_attr($tag),
                esc_html($tag),
                esc_html($desc)
            );
        }
        echo '</ul></div>';
    }

    private function renderTabBookings(): void
    {
        if (!empty($_GET['cancelled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Buchung storniert und Storno-Mails versendet.', 'rrze-appointment') . '</p></div>';
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
                <?php esc_html_e('Von', 'rrze-appointment'); ?>
                <input type="date" name="filter_date" value="<?php echo esc_attr($filterDate); ?>">
            </label>
            <label>
                <?php esc_html_e('Bis', 'rrze-appointment'); ?>
                <input type="date" name="filter_date_to" value="<?php echo esc_attr($filterDateTo); ?>">
            </label>
            <?php if (!empty($persons)) : ?>
            <label>
                <?php esc_html_e('Person', 'rrze-appointment'); ?>
                <select name="filter_person">
                    <option value="0"><?php esc_html_e('Alle', 'rrze-appointment'); ?></option>
                    <?php foreach ($persons as $pid => $pname) : ?>
                        <option value="<?php echo esc_attr($pid); ?>"<?php selected($filterPerson, $pid); ?>><?php echo esc_html($pname); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <button type="submit" class="button"><?php esc_html_e('Filtern', 'rrze-appointment'); ?></button>
            <a href="<?php echo esc_url($baseUrl); ?>" class="button"><?php esc_html_e('Zurücksetzen', 'rrze-appointment'); ?></a>
        </form>

        <?php if (empty($bookings)) : ?>
            <p><?php esc_html_e('Keine Buchungen gefunden.', 'rrze-appointment'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Datum', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Zeit', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Titel', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Person', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('Buchender', 'rrze-appointment'); ?></th>
                        <th><?php esc_html_e('E-Mail', 'rrze-appointment'); ?></th>
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
                                    onclick="return confirm('<?php esc_attr_e('Buchung wirklich stornieren?', 'rrze-appointment'); ?>')">
                                    <?php esc_html_e('Stornieren', 'rrze-appointment'); ?>
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
