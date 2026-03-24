<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class Settings
{
    const OPTION_NAME = 'rrze_appointment_settings';

    const PLACEHOLDERS = [
        '[titel]'       => 'Titel des Termins',
        '[datum]'       => 'Datum des Termins',
        '[uhrzeit]'     => 'Uhrzeit (von – bis)',
        '[ort]'         => 'Ort / Location',
        '[person_name]' => 'Name der zuständigen Person',
        '[name]'        => 'Name des Buchenden',
        '[email]'       => 'E-Mail des Buchenden',
    ];

    public static function getDefaults(): array
    {
        return [
            'reminder_days'             => 0,
            'booking_subject'           => 'Neue Buchung: [titel] am [datum]',
            'booking_body'              => "Neue Terminbuchung:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]\nPerson: [person_name]\nGebucht von: [name] ([email])",
            'booking_body_html'         => "<p>Neue Terminbuchung:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr><tr><th>Person</th><td>[person_name]</td></tr><tr><th>Gebucht von</th><td>[name] ([email])</td></tr></table>",
            'reminder_subject'          => 'Erinnerung: [titel] am [datum]',
            'reminder_body'             => "Dies ist eine Erinnerung an Ihren Termin:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]",
            'reminder_body_html'        => "<p>Dies ist eine Erinnerung an folgenden Termin:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr></table>",
            'reminder_body_booker'      => "Liebe/r [name],\n\nDies ist eine Erinnerung an Ihren Termin:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]",
            'reminder_body_booker_html' => "<p>Liebe/r [name],</p><p>Dies ist eine Erinnerung an Ihren Termin:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr></table>",
        ];
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

    /**
     * Sendet eine Multipart-Mail (text/plain + text/html).
     * Wird via phpmailer_init-Hook zusammengebaut.
     */
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
        if ($html === '') {
            return;
        }
        $phpmailer->AltBody = $phpmailer->Body; // plain als AltBody
        $phpmailer->isHTML(true);
        $phpmailer->Body    = $html;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            __('RRZE Appointment', 'rrze-appointment'),
            __('RRZE Appointment', 'rrze-appointment'),
            'manage_options',
            'rrze-appointment-settings',
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            'rrze_appointment_settings_group',
            self::OPTION_NAME,
            [
                'sanitize_callback' => [$this, 'sanitize'],
                'default'           => self::getDefaults(),
            ]
        );

        add_settings_section('rrze_appointment_general', __('Allgemein', 'rrze-appointment'), '__return_false', 'rrze-appointment-settings');
        add_settings_field('reminder_days', __('Erinnerungsmail', 'rrze-appointment'), [$this, 'renderReminderDaysField'], 'rrze-appointment-settings', 'rrze_appointment_general');

        add_settings_section('rrze_appointment_booking_mail', __('Buchungsbestätigung (an Admin / Person)', 'rrze-appointment'), [$this, 'renderSectionDivider'], 'rrze-appointment-settings');
        add_settings_field('booking_subject', __('Betreff', 'rrze-appointment'), fn() => $this->renderTextField('booking_subject'), 'rrze-appointment-settings', 'rrze_appointment_booking_mail');
        add_settings_field('booking_body',    __('Mailtext', 'rrze-appointment'),  fn() => $this->renderMailTabs('booking_body', 'booking_body_html'), 'rrze-appointment-settings', 'rrze_appointment_booking_mail');

        add_settings_section('rrze_appointment_reminder_mail', __('Erinnerungsmail', 'rrze-appointment'), [$this, 'renderSectionDivider'], 'rrze-appointment-settings');
        add_settings_field('reminder_subject',     __('Betreff', 'rrze-appointment'),                     fn() => $this->renderTextField('reminder_subject'),     'rrze-appointment-settings', 'rrze_appointment_reminder_mail');
        add_settings_field('reminder_body',        __('Mailtext (an Person / Admin)', 'rrze-appointment'), fn() => $this->renderMailTabs('reminder_body', 'reminder_body_html'),               'rrze-appointment-settings', 'rrze_appointment_reminder_mail');
        add_settings_field('reminder_body_booker', __('Mailtext (an Buchenden)', 'rrze-appointment'),      fn() => $this->renderMailTabs('reminder_body_booker', 'reminder_body_booker_html'),  'rrze-appointment-settings', 'rrze_appointment_reminder_mail');
    }

    public function sanitize(array $input): array
    {
        return [
            'reminder_days'             => (int) ($input['reminder_days'] ?? 0),
            'booking_subject'           => sanitize_text_field($input['booking_subject'] ?? ''),
            'booking_body'              => sanitize_textarea_field($input['booking_body'] ?? ''),
            'booking_body_html'         => wp_kses_post($input['booking_body_html'] ?? ''),
            'reminder_subject'          => sanitize_text_field($input['reminder_subject'] ?? ''),
            'reminder_body'             => sanitize_textarea_field($input['reminder_body'] ?? ''),
            'reminder_body_html'        => wp_kses_post($input['reminder_body_html'] ?? ''),
            'reminder_body_booker'      => sanitize_textarea_field($input['reminder_body_booker'] ?? ''),
            'reminder_body_booker_html' => wp_kses_post($input['reminder_body_booker_html'] ?? ''),
        ];
    }

    // --- Render-Hilfsmethoden ---

    public function renderReminderDaysField(): void
    {
        $value   = (int) self::get('reminder_days');
        $options = [0 => __('Deaktiviert', 'rrze-appointment')];
        for ($i = 1; $i <= 7; $i++) {
            $options[$i] = sprintf(_n('%d Tag vorher', '%d Tage vorher', $i, 'rrze-appointment'), $i);
        }
        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[reminder_days]">';
        foreach ($options as $val => $label) {
            printf('<option value="%d"%s>%s</option>', $val, selected($value, $val, false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Erinnerungsmail an Person und Buchenden X Tage vor dem Termin versenden.', 'rrze-appointment') . '</p>';
    }

    public function renderSectionDivider(): void
    {
        echo '<hr style="margin: 0 0 1rem; border: none; border-top: 1px solid #dcdcde;">';
    }

    private function renderTextField(string $key): void
    {
        $id    = 'rrze_appt_' . $key;
        $value = (string) self::get($key);
        printf(
            '<input type="text" id="%s" name="%s[%s]" value="%s" class="large-text" />',
            esc_attr($id),
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_attr($value)
        );
        $this->renderInsertButton($id);
    }

    private function renderMailTabs(string $plainKey, string $htmlKey): void
    {
        $plainId = 'rrze_appt_' . $plainKey;
        $htmlId  = 'rrze_appt_' . $htmlKey;
        $uid     = esc_attr($plainKey); // eindeutig pro Feld
        ?>
        <div class="rrze-appt-tabs" data-uid="<?php echo $uid; ?>">
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
                    <?php
                    $plainValue = (string) self::get($plainKey);
                    printf(
                        '<textarea id="%s" name="%s[%s]" rows="6" class="large-text">%s</textarea>',
                        esc_attr($plainId),
                        esc_attr(self::OPTION_NAME),
                        esc_attr($plainKey),
                        esc_textarea($plainValue)
                    );
                    $this->renderInsertButton($plainId);
                    ?>
                </div>
                <div class="rrze-appt-tab-panel" data-panel="html" style="display:none;">
                    <?php
                    $htmlValue = (string) self::get($htmlKey);
                    wp_editor($htmlValue, $htmlId, [
                        'textarea_name' => self::OPTION_NAME . '[' . $htmlKey . ']',
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
                esc_html__($desc, 'rrze-appointment')
            );
        }
        echo '</ul></div>';
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('rrze_appointment_settings_group');
                do_settings_sections('rrze-appointment-settings');
                submit_button();
                ?>
            </form>
        </div>
        <script>
        (function() {
            var lastField    = null;
            var lastPos      = 0;
            var savedBookmark = null; // TinyMCE bookmark
            var savedEditorId = null;

            // --- Tab-Switching ---
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

            // TinyMCE: Bookmark sichern wenn Editor Fokus verliert
            if (typeof tinyMCE !== 'undefined') {
                // onSetup wird zu spät ausgeführt – wir nutzen das tinymce-Event nach Init
                document.addEventListener('focusin', function(e) {
                    // Wenn Fokus auf einen Insert-Button geht: Bookmark vorher sichern
                    if (e.target && e.target.classList && e.target.classList.contains('rrze-appt-insert-btn') && e.target.dataset.tinymce === '1') {
                        var edId = e.target.dataset.target;
                        var ed   = typeof tinyMCE !== 'undefined' ? tinyMCE.get(edId) : null;
                        if (ed) {
                            savedBookmark = ed.selection.getBookmark(2, true);
                            savedEditorId = edId;
                        }
                    }
                }, true); // capture phase
            }

            // --- Dropdown öffnen/schließen ---
            document.querySelectorAll('.rrze-appt-insert-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var dropdown = btn.nextElementSibling;
                    var isOpen   = dropdown.style.display === 'block';
                    document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });
                    if (!isOpen) {
                        // Bookmark für TinyMCE sichern (falls noch nicht via focusin geschehen)
                        if (btn.dataset.tinymce === '1') {
                            var edId = btn.dataset.target;
                            var ed   = typeof tinyMCE !== 'undefined' ? tinyMCE.get(edId) : null;
                            if (ed && !savedBookmark) {
                                savedBookmark = ed.selection.getBookmark(2, true);
                                savedEditorId = edId;
                            }
                        }
                        dropdown.style.display = 'block';
                        dropdown.querySelectorAll('.rrze-appt-insert-tag').forEach(function(tagBtn) {
                            tagBtn.dataset.insertTarget  = btn.dataset.target;
                            tagBtn.dataset.insertTinymce = btn.dataset.tinymce;
                        });
                    }
                });
            });

            // --- Hover-Effekt ---
            document.querySelectorAll('.rrze-appt-insert-tag').forEach(function(tagBtn) {
                tagBtn.addEventListener('mouseenter', function() { tagBtn.style.background = '#f0f6fc'; });
                tagBtn.addEventListener('mouseleave', function() { tagBtn.style.background = 'none'; });
            });

            // --- Platzhalter einfügen ---
            document.querySelectorAll('.rrze-appt-insert-tag').forEach(function(tagBtn) {
                tagBtn.addEventListener('click', function() {
                    var tag       = tagBtn.dataset.tag;
                    var targetId  = tagBtn.dataset.insertTarget;
                    var isTinymce = tagBtn.dataset.insertTinymce === '1';
                    document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });

                    // TinyMCE: Bookmark wiederherstellen, dann einfügen
                    if (isTinymce && targetId && typeof tinyMCE !== 'undefined') {
                        var ed = tinyMCE.get(targetId);
                        if (ed) {
                            ed.focus();
                            if (savedBookmark && savedEditorId === targetId) {
                                ed.selection.moveToBookmark(savedBookmark);
                            }
                            ed.insertContent(tag);
                            savedBookmark = null;
                            savedEditorId = null;
                            return;
                        }
                    }

                    // Textarea / Input
                    var field = targetId ? document.getElementById(targetId) : lastField;
                    if (!field) {
                        var td = tagBtn.closest('td');
                        field  = td ? (td.querySelector('textarea') || td.querySelector('input[type=text]')) : null;
                    }
                    if (!field) return;

                    var pos     = (field === lastField) ? lastPos : (field.selectionStart ?? field.value.length);
                    var val     = field.value;
                    field.value = val.slice(0, pos) + tag + val.slice(pos);
                    var newPos  = pos + tag.length;
                    field.focus();
                    field.setSelectionRange(newPos, newPos);
                    lastField = field;
                    lastPos   = newPos;
                });
            });

            // --- Klick außerhalb schließt Dropdowns ---
            document.addEventListener('click', function() {
                document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });
            });
        }());
        </script>
        <?php
    }
}
