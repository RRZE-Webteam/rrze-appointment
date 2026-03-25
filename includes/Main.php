<?php

namespace RRZE\Appointment;

use function RRZE\Appointment\plugin;

use RRZE\Appointment\Defaults;
use RRZE\Appointment\Settings;
use RRZE\Appointment\Reminder;
use RRZE\Appointment\Bookings;
use RRZE\Appointment\MailTemplatePost;
use RRZE\Appointment\Common\Settings\Settings as CommonSettings;


defined('ABSPATH') || exit;

/**
 * Main class
 * 
 * This class serves as the entry point for the plugin.
 * It can be extended to include additional functionality or components as needed.
 * 
 * @package RRZE\Appointment\Common
 * @since 1.0.0
 */
class Main
{
    public $defaults;
    public $settings;

    public function __construct()
    {
        add_action('init', [MailTemplatePost::class, 'register'], 5);
        add_action('init', [$this, 'onInit']);
        add_filter('wp_kses_allowed_html', [$this, 'my_custom_allowed_html'], 10, 2);
    }

    public function onInit()
    {
        $this->defaults = new Defaults();

        (new Settings())->register();
        (new Reminder())->register();

        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('enqueue_block_assets', [$this, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_ajax_rrze_appointment_book', [$this, 'handleBooking']);
        add_action('wp_ajax_nopriv_rrze_appointment_book', [$this, 'handleBooking']);
    }



    /**
     * Allow needed HTML on post content sanitized by wp_kses_post().
     *
     * @param array  $allowed_tags The current allowed tags/attributes for the given context.
     * @param string $context      KSES context; wp_kses_post() uses 'post'.
     * @return array               Modified allowed tags/attributes.
     */
    function my_custom_allowed_html($allowed_tags, $context)
    {
        // Only alter the 'post' context used by wp_kses_post()
        if ($context !== 'post') {
            return $allowed_tags;
        }

        // 1) Schema.org microdata attributes we want to allow on various elements
        $schema_attrs = [
            'itemscope' => true, // boolean attribute (no value needed)
            'itemtype' => true, // URL to schema type, e.g. https://schema.org/FAQPage
            'itemprop' => true, // property name within the item
            'itemid' => true, // global identifier
            'itemref' => true, // references other elements by ID
        ];

        // 2) HTML5 elements that may carry microdata in your templates/shortcodes
        $tags_to_extend = [
            'div',
            'span',
            'p',
            'a',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'ul',
            'ol',
            'li',
            'section',
            'article',
            'header',
            'footer',
            'main',
            'nav',
            'details',
            'summary'
        ];

        // Ensure details/summary exist with common attributes for accordion UI
        if (!isset($allowed_tags['details'])) {
            $allowed_tags['details'] = [];
        }
        $allowed_tags['details'] = array_merge($allowed_tags['details'], [
            'id' => true,
            'class' => true,
            'open' => true, // render expanded by default
        ]);

        if (!isset($allowed_tags['summary'])) {
            $allowed_tags['summary'] = [];
        }
        $allowed_tags['summary'] = array_merge($allowed_tags['summary'], [
            'id' => true,
            'class' => true,
        ]);

        // 3) Add Schema.org attributes to the listed tags without removing existing ones
        foreach ($tags_to_extend as $tag) {
            if (!isset($allowed_tags[$tag])) {
                $allowed_tags[$tag] = [];
            }
            $allowed_tags[$tag] = array_merge($allowed_tags[$tag], $schema_attrs);
        }

        // 4) (Optional) keep your form elements if you output any in content
        $allowed_tags['select'] = array_merge($allowed_tags['select'] ?? [], [
            'name' => true,
            'id' => true,
            'class' => true,
            'multiple' => true,
            'size' => true,
        ]);

        $allowed_tags['option'] = array_merge($allowed_tags['option'] ?? [], [
            'value' => true,
            'selected' => true,
        ]);

        $allowed_tags['input'] = array_merge($allowed_tags['input'] ?? [], [
            'type' => true,
            'name' => true,
            'id' => true,
            'class' => true,
            'value' => true,
            'placeholder' => true,
            'checked' => true,
            'disabled' => true,
            'readonly' => true,
            'maxlength' => true,
            'size' => true,
            'min' => true,
            'max' => true,
            'step' => true,
            'required' => true,
        ]);

        $allowed_tags['form'] = array_merge($allowed_tags['form'] ?? [], [
            'action' => true,
            'method' => true,
            'id' => true,
            'class' => true,
        ]);

        $allowed_tags['fieldset'] = array_merge($allowed_tags['fieldset'] ?? [], [
            'id' => true,
            'class' => true,
        ]);

        $allowed_tags['legend'] = array_merge($allowed_tags['legend'] ?? [], [
            'id' => true,
            'class' => true,
        ]);

        $allowed_tags['label'] = array_merge($allowed_tags['label'] ?? [], [
            'for' => true,
            'id' => true,
            'class' => true,
        ]);

        $allowed_tags['button'] = array_merge($allowed_tags['button'] ?? [], [
            'type' => true,
            'name' => true,
            'value' => true,
            'id' => true,
            'class' => true,
            'disabled' => true,
        ]);

        return $allowed_tags;
    }


    /**
     * Settings method
     * 
     * This method sets up the plugin settings using the Settings class.
     * It defines the settings sections and options that will be available in the WordPress admin area
     * and provides validation and sanitization for the settings.
     * 
     * @return void
     */


    public function settings()
    {
        $this->settings = new CommonSettings($this->defaults->get('settings')['page_title']);

        $this->settings->setCapability($this->defaults->get('settings')['capability'])
            ->setOptionName($this->defaults->get('settings')['option_name'])
            ->setMenuTitle($this->defaults->get('settings')['menu_title'])
            ->setMenuPosition(6)
            ->setMenuParentSlug('options-general.php');

        foreach ($this->defaults->get('sections') as $section) {
            $tab = $this->settings->addTab(__($section['title'], 'rrze-appointment'), $section['id']);
            $sec = $tab->addSection(__($section['title'], 'rrze-appointment'), $section['id']);

            foreach ($this->defaults->get('fields')[$section['id']] as $field) {
                $sec->addOption($field['type'], array_intersect_key(
                    $field,
                    array_flip(['name', 'label', 'description', 'options', 'default', 'sanitize', 'validate', 'placeholder'])
                ));
            }
        }

        $this->settings->build();
    }

    private function getFaudirPersons(): array
    {
        if (!post_type_exists('custom_person')) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => 'custom_person',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        $result = [];
        foreach ($posts as $post) {
            $given  = (string) get_post_meta($post->ID, 'person_givenName', true);
            $family = (string) get_post_meta($post->ID, 'person_familyName', true);
            $prefix = (string) get_post_meta($post->ID, 'person_honorificPrefix', true);
            $label  = trim("$given $family") ?: $post->post_title;
            $email  = (string) get_post_meta($post->ID, 'person_email', true);

            // Sprechstunden aus FAUdir-API-Transient lesen
            $personFaudirId      = (string) get_post_meta($post->ID, 'person_id', true);
            $consultationHours   = [];
            if ($personFaudirId !== '') {
                $transientKey = 'faudir_api_person_' . md5($personFaudirId);
                $cached       = get_transient($transientKey);
                if (is_array($cached)) {
                    $workplaces = $cached['workplaces'] ?? $cached['contacts'] ?? [];
                    foreach ((array) $workplaces as $wp) {
                        if (!empty($wp['consultationHours']) && is_array($wp['consultationHours'])) {
                            $consultationHours = array_merge($consultationHours, $wp['consultationHours']);
                        }
                    }
                }
            }

            $result[] = [
                'id'               => $post->ID,
                'label'            => $label,
                'honorificPrefix'  => $prefix,
                'givenName'        => $given,
                'familyName'       => $family,
                'email'            => $email,
                'consultationHours'=> $consultationHours,
            ];
        }

        return $result;
    }

    /**
     * Enqueue der globale Skripte.
     */
    public function enqueueAssets()
    {
        $viewHandle = 'rrze-appointment-view-script';
        if (wp_script_is($viewHandle, 'registered')) {
            $booked = get_option('rrze_appointment_booked_slots', []);
            wp_localize_script($viewHandle, 'rrze_appointment', [
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('rrze_appointment_book'),
                'bookedSlots' => array_values((array) $booked),
                'persons'     => $this->getFaudirPersons(),
            ]);
        }
    }

    public function enqueueAdminAssets()
    {
        $editorHandle = 'rrze-appointment-editor-script';
        if (wp_script_is($editorHandle, 'registered')) {
            wp_localize_script($editorHandle, 'rrze_appointment', [
                'persons' => $this->getFaudirPersons(),
            ]);
        }
    }

    private function icsEscape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    public function handleBooking(): void
    {
        check_ajax_referer('rrze_appointment_book', 'nonce');

        $slot        = sanitize_text_field($_POST['slot'] ?? '');
        $title       = sanitize_text_field($_POST['title'] ?? 'Termin');
        $location    = sanitize_text_field($_POST['location'] ?? '');
        $personId    = (int) ($_POST['person_id'] ?? 0);
        $bookerEmail = sanitize_email($_POST['booker_email'] ?? '');
        $bookerName  = sanitize_text_field($_POST['booker_name'] ?? '');
        $tplId               = (int) ($_POST['tpl_id'] ?? 0);

        if (!$slot) {
            wp_send_json_error('Kein Termin angegeben.');
        }

        // Slot-Format: "YYYY-MM-DD HH:MM-HH:MM"
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

        if (!$datePart || !$startTime || !$endTime) {
            wp_send_json_error('Ungültiges Termin-Format.');
        }

        $tz      = wp_timezone();
        $dtStart = new \DateTime($datePart . 'T' . $startTime . ':00', $tz);
        $dtEnd   = new \DateTime($datePart . 'T' . $endTime . ':00', $tz);

        // In UTC konvertieren — kein VTIMEZONE-Block nötig
        $dtStart->setTimezone(new \DateTimeZone('UTC'));
        $dtEnd->setTimezone(new \DateTimeZone('UTC'));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $uid = wp_generate_uuid4() . '@' . parse_url(home_url(), PHP_URL_HOST);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RRZE Appointment//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now->format('Ymd\THis\Z'),
            'DTSTART:' . $dtStart->format('Ymd\THis\Z'),
            'DTEND:'   . $dtEnd->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->icsEscape($title),
            'LOCATION:' . $this->icsEscape($location),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        $ics = implode("\r\n", $lines) . "\r\n";

        $pName = '';
        if ($personId > 0) {
            $pTitle  = (string) get_post_meta($personId, 'person_honorificPrefix', true);
            $pGiven  = (string) get_post_meta($personId, 'person_givenName', true);
            $pFamily = (string) get_post_meta($personId, 'person_familyName', true);
            $pName   = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
        }

        $adminEmail = get_option('admin_email');

        $vars = [
            '[titel]'       => $title,
            '[datum]'       => $dtStart->format('d.m.Y'),
            '[uhrzeit]'     => $startTime . ' – ' . $endTime,
            '[ort]'         => $location ?: '–',
            '[person_name]' => $pName ?: '–',
            '[name]'        => $bookerName ?: '–',
            '[email]'       => $bookerEmail ?: '–',
        ];

        $tpl = $tplId > 0 ? MailTemplatePost::getTemplateForType($tplId, 'booking') : null;

        $subject  = Settings::renderTemplate($tpl['subject']   ?: (string) Settings::get('booking_subject'),   $vars);
        $plain    = Settings::renderTemplate($tpl['body']      ?: (string) Settings::get('booking_body'),      $vars);
        $html     = Settings::renderTemplate($tpl['body_html'] ?: (string) Settings::get('booking_body_html'), $vars);

        $tmpDir  = get_temp_dir();
        $tmpFile = tempnam($tmpDir, 'rrze_appt_');
        rename($tmpFile, $tmpFile . '.ics');
        $tmpFile = $tmpFile . '.ics';
        file_put_contents($tmpFile, $ics);

        $sent = Settings::sendMail($adminEmail, $subject, $plain, $html, [$tmpFile]);
        @unlink($tmpFile);

        if ($sent) {
            $booked = get_option('rrze_appointment_booked_slots', []);
            $booked[] = $slot;
            update_option('rrze_appointment_booked_slots', array_unique($booked), false);

            Reminder::scheduleForSlot($slot, [
                'title'        => $title,
                'location'     => $location,
                'person_id'    => $personId,
                'booker_email' => $bookerEmail,
                'booker_name'  => $bookerName,
                'tpl_id'       => $tplId,
            ]);

            wp_send_json_success();
        } else {
            wp_send_json_error('E-Mail konnte nicht gesendet werden.');
        }
    }

}

