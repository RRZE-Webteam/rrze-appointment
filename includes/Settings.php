<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class Settings
{
    const OPTION_NAME = 'rrze_appointment_settings';

    const PLACEHOLDERS = [
        '[title]'       => 'title des Termins',
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
            'reminder_days'        => 0,
            'booking_subject'      => 'Neue Buchung: [title] am [datum]',
            'booking_body'         => "Neue Terminbuchung:\n\nTermin: [title]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]\nPerson: [person_name]\nGebucht von: [name] ([email])",
            'reminder_subject'     => 'Erinnerung: [title] am [datum]',
            'reminder_body'        => "Dies ist eine Erinnerung an Ihren Termin:\n\nTermin: [title]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]",
            'reminder_body_booker' => "Liebe/r [name],\n\nDies ist eine Erinnerung an Ihren Termin:\n\nTermin: [title]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]",
        ];
    }

    public static function get(string $key): mixed
    {
        $options  = get_option(self::OPTION_NAME, []);
        $defaults = self::getDefaults();
        return $options[$key] ?? $defaults[$key] ?? null;
    }

    /**
     * Ersetzt Platzhalter in einem Template-String.
     */
    public static function renderTemplate(string $template, array $vars): string
    {
        $search  = array_keys($vars);
        $replace = array_values($vars);
        return str_replace($search, $replace, $template);
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

        // --- Allgemein ---
        add_settings_section('rrze_appointment_general', __('Allgemein', 'rrze-appointment'), '__return_false', 'rrze-appointment-settings');

        add_settings_field('reminder_days', __('Erinnerungsmail', 'rrze-appointment'), [$this, 'renderReminderDaysField'], 'rrze-appointment-settings', 'rrze_appointment_general');

        // --- Buchungsbestätigung ---
        add_settings_section('rrze_appointment_booking_mail', __('Buchungsbestätigung (an Admin / Person)', 'rrze-appointment'), [$this, 'renderSectionDivider'], 'rrze-appointment-settings');

        add_settings_field('booking_subject', __('Betreff', 'rrze-appointment'), fn() => $this->renderTextField('booking_subject'), 'rrze-appointment-settings', 'rrze_appointment_booking_mail');
        add_settings_field('booking_body',    __('Mailtext', 'rrze-appointment'), fn() => $this->renderTextarea('booking_body'),    'rrze-appointment-settings', 'rrze_appointment_booking_mail');

        // --- Erinnerungsmail ---
        add_settings_section('rrze_appointment_reminder_mail', __('Erinnerungsmail', 'rrze-appointment'), [$this, 'renderSectionDivider'], 'rrze-appointment-settings');

        add_settings_field('reminder_subject',     __('Betreff', 'rrze-appointment'),                    fn() => $this->renderTextField('reminder_subject'),     'rrze-appointment-settings', 'rrze_appointment_reminder_mail');
        add_settings_field('reminder_body',        __('Mailtext (an Person / Admin)', 'rrze-appointment'), fn() => $this->renderTextarea('reminder_body'),        'rrze-appointment-settings', 'rrze_appointment_reminder_mail');
        add_settings_field('reminder_body_booker', __('Mailtext (an Buchenden)', 'rrze-appointment'),      fn() => $this->renderTextarea('reminder_body_booker'), 'rrze-appointment-settings', 'rrze_appointment_reminder_mail');
    }

    public function sanitize(array $input): array
    {
        return [
            'reminder_days'        => (int) ($input['reminder_days'] ?? 0),
            'booking_subject'      => sanitize_text_field($input['booking_subject'] ?? ''),
            'booking_body'         => sanitize_textarea_field($input['booking_body'] ?? ''),
            'reminder_subject'     => sanitize_text_field($input['reminder_subject'] ?? ''),
            'reminder_body'        => sanitize_textarea_field($input['reminder_body'] ?? ''),
            'reminder_body_booker' => sanitize_textarea_field($input['reminder_body_booker'] ?? ''),
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

    private function renderInsertButton(string $targetId): void
    {
        echo '<div style="position:relative;display:inline-block;margin-top:0.4rem;">';
        printf(
            '<button type="button" class="button rrze-appt-insert-btn" data-target="%s">%s &#9660;</button>',
            esc_attr($targetId),
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

    private function renderTextarea(string $key): void
    {
        $id    = 'rrze_appt_' . $key;
        $value = (string) self::get($key);
        printf(
            '<textarea id="%s" name="%s[%s]" rows="6" class="large-text">%s</textarea>',
            esc_attr($id),
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_textarea($value)
        );
        $this->renderInsertButton($id);
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
            var lastField = null;
            var lastPos   = 0;

            // Cursorposition merken
            document.querySelectorAll('.rrze-appt-insert-btn').forEach(function(btn) {
                var field = document.getElementById(btn.dataset.target);
                if (!field) return;
                ['focus', 'click', 'keyup'].forEach(function(ev) {
                    field.addEventListener(ev, function() {
                        lastField = field;
                        lastPos   = field.selectionStart ?? field.value.length;
                    });
                });
            });

            // Dropdown öffnen/schließen
            document.querySelectorAll('.rrze-appt-insert-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var dropdown = btn.nextElementSibling;
                    var isOpen   = dropdown.style.display === 'block';
                    // Alle schließen
                    document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });
                    if (!isOpen) dropdown.style.display = 'block';
                });
            });

            // Hover-Effekt auf Dropdown-Items
            document.querySelectorAll('.rrze-appt-insert-tag').forEach(function(tagBtn) {
                tagBtn.addEventListener('mouseenter', function() { tagBtn.style.background = '#f0f6fc'; });
                tagBtn.addEventListener('mouseleave', function() { tagBtn.style.background = 'none'; });
            });

            // Platzhalter einfügen
            document.querySelectorAll('.rrze-appt-insert-tag').forEach(function(tagBtn) {
                tagBtn.addEventListener('click', function() {
                    var tag = tagBtn.dataset.tag;
                    // Dropdown schließen
                    document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });

                    // Zielfeld: entweder das gemerkte oder das nächste input/textarea im selben td
                    var field = lastField;
                    if (!field) {
                        var td = tagBtn.closest('td');
                        field  = td ? (td.querySelector('textarea') || td.querySelector('input[type=text]')) : null;
                    }
                    if (!field) return;

                    var pos   = (field === lastField) ? lastPos : (field.selectionStart ?? field.value.length);
                    var val   = field.value;
                    field.value = val.slice(0, pos) + tag + val.slice(pos);
                    var newPos  = pos + tag.length;
                    field.focus();
                    field.setSelectionRange(newPos, newPos);
                    lastField = field;
                    lastPos   = newPos;
                });
            });

            // Klick außerhalb schließt Dropdowns
            document.addEventListener('click', function() {
                document.querySelectorAll('.rrze-appt-insert-dropdown').forEach(function(d) { d.style.display = 'none'; });
            });
        }());
        </script>
        <?php
    }
}
