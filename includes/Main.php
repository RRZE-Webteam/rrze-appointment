<?php

namespace RRZE\Appointment;

// use function RRZE\Appointment\plugin;

use RRZE\Appointment\Rights;
use RRZE\Appointment\Defaults;
use RRZE\Appointment\Settings;
use RRZE\Appointment\Reminder;
use RRZE\Appointment\Bookings;
use RRZE\Appointment\MailTemplatePost;
use RRZE\Appointment\TokenManager;
use RRZE\Appointment\Common\Settings\Settings as CommonSettings;
use RRZE\Appointment\Common\CustomException;


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
        add_action('enqueue_block_editor_assets', [$this, 'enqueueAdminAssets']);
        add_action('wp_ajax_rrze_appointment_book', [$this, 'handleBooking']);
        add_action('wp_ajax_nopriv_rrze_appointment_book', [$this, 'handleBooking']);
        add_action('wp_ajax_rrze_appointment_get_booker', [$this, 'handleGetBooker']);
        add_action('wp_ajax_nopriv_rrze_appointment_get_booker', [$this, 'handleGetBooker']);
        add_action('template_redirect', [$this, 'handleConfirm']);
        add_action('template_redirect', [$this, 'handleCancel']);
        add_action('rrze_appointment_expire_pending', ['RRZE\Appointment\TokenManager', 'expirePending']);
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

    private function getFAUdirPersons(): array
    {
        if (!post_type_exists('custom_person')) {
            return ['error' => true, 'message' => 'Post type custom_person does not exist.', 'data' => []];
        }

        $posts = get_posts([
            'post_type'      => 'custom_person',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        if (!class_exists('\RRZE\FAUdir\API') || !class_exists('\RRZE\FAUdir\Config')) {
            return ['error' => true, 'message' => 'FAUdir classes not available.', 'data' => []];
        }

        $config = new \RRZE\FAUdir\Config();
        $api    = new \RRZE\FAUdir\API($config);

        $result = [];
        foreach ($posts as $post_id) {
            $faudir_id = (string) get_post_meta($post_id, 'person_id', true);
            if ($faudir_id === '') continue;

            $person = $api->getPerson($faudir_id);
            if (!is_array($person) || empty($person)) continue;

            $given  = $person['givenName'] ?? '';
            $family = $person['familyName'] ?? '';
            $prefix = $person['honorificPrefix'] ?? '';
            $email  = $person['email'] ?? '';
            $label  = trim("$given $family") ?: get_the_title($post_id);

            $consultationHours = [];
            $hoursType         = null;
            $location          = '';
            $locationUrl       = '';

            foreach ($person['contacts'] ?? [] as $contact) {
                $contact_id     = $contact['identifier'] ?? '';
                if (!$contact_id) continue;
                $contact_detail = $api->getContact($contact_id);
                if (!is_array($contact_detail)) continue;

                foreach ($contact_detail['workplaces'] ?? [] as $wp) {
                    if (!empty($wp['consultationHours'])) {
                        $consultationHours = $wp['consultationHours'];
                        $hoursType         = 'consultation';
                    } elseif (!empty($wp['officeHours'])) {
                        $consultationHours = $wp['officeHours'];
                        $hoursType         = 'office';
                    }
                    if (!empty($consultationHours)) {
                        $location    = implode(', ', array_filter([$wp['room'] ?? '', $wp['street'] ?? '', $wp['city'] ?? '']));
                        $locationUrl = $wp['faumap'] ?? '';
                        break 2;
                    }
                }
            }

            $result[] = [
                'id'                => $post_id,
                'error'             => false,
                'message'           => '',
                'label'             => $label,
                'honorificPrefix'   => $prefix,
                'givenName'         => $given,
                'familyName'        => $family,
                'email'             => $email,
                'location'          => $location,
                'locationUrl'       => $locationUrl,
                'consultationHours' => $consultationHours,
                'hoursType'         => $hoursType,
            ];
        }

        return ['error' => false, 'message' => '', 'data' => $result];
    }
    /**
     * Enqueue der globale Skripte.
     */
    public function enqueueAssets()
    {
        try {
            $viewHandle = 'rrze-appointment-view-script';
            if (wp_script_is($viewHandle, 'registered')) {
                $booked  = (array) get_option('rrze_appointment_booked_slots', []);
                $pending = TokenManager::getPendingSlots();
                wp_localize_script($viewHandle, 'rrze_appointment', [
                    'ajaxUrl'     => admin_url('admin-ajax.php'),
                    'nonce'       => wp_create_nonce('rrze_appointment_book'),
                    'bookedSlots' => array_values(array_unique(array_merge($booked, $pending))),
                ]);
            }
        } catch (CustomException $e) {
            return;
        }
    }

    public function enqueueAdminAssets()
    {
        try {
            $persons = $this->getFAUdirPersons();
            echo '<script>window.rrze_appointment = ' . wp_json_encode(['persons' => $persons]) . ';</script>' . "\n";
        } catch (CustomException $e) {
            echo '<script>window.rrze_appointment = ' . wp_json_encode(['persons' => ['error' => true, 'message' => $e->getMessage(), 'data' => []]]) . ';</script>' . "\n";
        }
    }

    public function handleGetPersons(): void
    {
        check_ajax_referer('rrze_appointment_persons', 'nonce');
        wp_send_json($this->getFAUdirPersons());
    }

    public function handleGetBooker(): void
    {
        try {
            $debug = [
                'is_user_logged_in' => is_user_logged_in(),
                'AccessControl'     => class_exists('RRZE\\AccessControl\\Permissions'),
                'Rights_exists'     => class_exists('RRZE\\Appointment\\Rights'),
            ];

            $booker = Rights::get();
            $debug['idm']         = $booker['idm'];
            $debug['bookerName']  = $booker['bookerName'];
            $debug['bookerEmail'] = $booker['bookerEmail'];

            if (empty($booker['idm'])) {
                $currentUrl = sanitize_url($_SERVER['HTTP_REFERER'] ?? home_url('/'));
                wp_send_json_success([
                    'needsLogin' => true,
                    'loginUrl'   => wp_login_url($currentUrl),
                    'debug'      => $debug,
                ]);
                return;
            }
            wp_send_json_success(array_merge($booker, ['needsLogin' => false, 'debug' => $debug]));
        } catch (\Throwable $e) {
            wp_send_json_error(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        }
    }

    private function icsEscape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    public function handleBooking(): void
    {
        try {
            check_ajax_referer('rrze_appointment_book', 'nonce');

        $slot = sanitize_text_field($_POST['slot'] ?? '');
        $title = sanitize_text_field($_POST['title'] ?? 'Termin');
        $location = sanitize_text_field($_POST['location'] ?? '');
        $personId = (int) ($_POST['person_id'] ?? 0);
        $bookerEmail = sanitize_email($_POST['booker_email'] ?? '');
        $bookerName = sanitize_text_field($_POST['booker_name'] ?? '');
        $bookerMsg = sanitize_textarea_field($_POST['booker_message'] ?? '');
        $tplId = (int) ($_POST['tpl_id'] ?? 0);

        if (!$slot)
            wp_send_json_error('Kein Termin angegeben.');
        if (!$bookerEmail)
            wp_send_json_error('Bitte eine E-Mail-Adresse angeben.');

        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

        if (!$datePart || !$startTime || !$endTime)
            wp_send_json_error('Ungültiges Termin-Format.');

        // Slot bereits gebucht oder pending?
        $booked = (array) get_option('rrze_appointment_booked_slots', []);
        $pending = TokenManager::getPendingSlots();
        if (in_array($slot, $booked, true) || in_array($slot, $pending, true)) {
            wp_send_json_error('Dieser Termin ist nicht mehr verfügbar.');
        }

        $pName = '';
        if ($personId > 0) {
            $pTitle = (string) get_post_meta($personId, 'person_honorificPrefix', true);
            $pGiven = (string) get_post_meta($personId, 'person_givenName', true);
            $pFamily = (string) get_post_meta($personId, 'person_familyName', true);
            $pName = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
        }

        $meta = [
            'title' => $title,
            'location' => $location,
            'person_id' => $personId,
            'booker_email' => $bookerEmail,
            'booker_name' => $bookerName,
            'booker_message' => $bookerMsg,
            'tpl_id' => $tplId,
        ];

        $confirmToken = TokenManager::createPending($slot, $meta);
        $confirmUrl = TokenManager::confirmUrl($confirmToken);
        $imprintUrl = TokenManager::imprintUrl();

        $vars = [
            '[titel]' => $title,
            '[datum]' => date_i18n(get_option('date_format'), strtotime($datePart)),
            '[uhrzeit]' => $startTime . ' – ' . $endTime,
            '[ort]' => $location ?: '–',
            '[person_name]' => $pName ?: '–',
            '[name]' => $bookerName ?: '–',
            '[email]' => $bookerEmail ?: '–',
            '[nachricht]' => $bookerMsg ?: '',
            '[bestaetigungs_link]' => $confirmUrl,
            '[storno_link]' => TokenManager::cancelUrl(TokenManager::createPendingCancelToken($slot, $meta)),
            '[impressum_link]' => $imprintUrl,
        ];

        $tpl = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, 'booking_pending') ?? []) : [];
        $def = MailTemplatePost::getDefault('booking_pending');
        $subject = Settings::renderTemplate(!empty($tpl['subject']) ? $tpl['subject'] : $def['subject'], $vars);
        $bodyTpl = !empty($tpl['body']) ? $tpl['body'] : $def['body'];
        $bodyHtmlTpl = !empty($tpl['body_html']) ? $tpl['body_html'] : $def['body_html'];
        if (strpos($bodyTpl, '[bestaetigungs_link]') === false)
            $bodyTpl .= "\n\nBestätigung: [bestaetigungs_link]";
        if (strpos($bodyTpl, '[impressum_link]') === false)
            $bodyTpl .= "\nImpressum: [impressum_link]";
        if (strpos($bodyHtmlTpl, '[bestaetigungs_link]') === false)
            $bodyHtmlTpl .= '<p><a href="[bestaetigungs_link]">Termin jetzt bestätigen</a></p>';
        if (strpos($bodyHtmlTpl, '[impressum_link]') === false)
            $bodyHtmlTpl .= '<p><a href="[impressum_link]">Impressum</a></p>';
        $plain = Settings::renderTemplate($bodyTpl, $vars);
        $html = Settings::renderTemplate($bodyHtmlTpl, $vars);

        Settings::sendMail($bookerEmail, $subject, $plain, $html);

        wp_send_json_success(['message' => __('Bitte bestätigen Sie Ihren Termin per E-Mail.', 'rrze-appointment')]);
        } catch (CustomException $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Bestätigt eine Buchung via Token-Link.
     */
    public function handleConfirm(): void
    {
        try {
            $token = sanitize_text_field($_GET['rrze_appt_confirm'] ?? '');
            if (!$token) return;

        $entry = TokenManager::confirmPending($token);
        if (!$entry) {
            wp_die(__('Dieser Bestätigungslink ist abgelaufen oder ungültig.', 'rrze-appointment'), '', ['response' => 410]);
        }

        $slot = $entry['slot'];
        $meta = $entry['meta'];

        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

        $tz = wp_timezone();
        $dtStart = new \DateTime($datePart . 'T' . $startTime . ':00', $tz);
        $dtEnd = new \DateTime($datePart . 'T' . $endTime . ':00', $tz);
        $dtStart->setTimezone(new \DateTimeZone('UTC'));
        $dtEnd->setTimezone(new \DateTimeZone('UTC'));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        // ICS erstellen
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
            'DTEND:' . $dtEnd->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->icsEscape($meta['title'] ?? ''),
            'LOCATION:' . $this->icsEscape($meta['location'] ?? ''),
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        $ics = implode("\r\n", $lines) . "\r\n";

        // Slot als gebucht markieren
        $booked = (array) get_option('rrze_appointment_booked_slots', []);
        $booked[] = $slot;
        update_option('rrze_appointment_booked_slots', array_unique($booked), false);

        // Meta speichern (vor createCancelToken, damit cancel_token ins Meta geschrieben werden kann)
        Reminder::scheduleForSlot($slot, $meta);

        // Cancel-Token erstellen und URL holen
        $cancelUrl = TokenManager::getCancelUrlForSlot($slot);
        $imprintUrl = TokenManager::imprintUrl();
        $personId = (int) ($meta['person_id'] ?? 0);
        $bookerEmail = $meta['booker_email'] ?? '';
        $bookerName = $meta['booker_name'] ?? '';
        $tplId = (int) ($meta['tpl_id'] ?? 0);

        $pName = '';
        if ($personId > 0) {
            $pTitle = (string) get_post_meta($personId, 'person_honorificPrefix', true);
            $pGiven = (string) get_post_meta($personId, 'person_givenName', true);
            $pFamily = (string) get_post_meta($personId, 'person_familyName', true);
            $pName = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
        }

        $vars = [
            '[titel]' => $meta['title'] ?? '',
            '[datum]' => date_i18n(get_option('date_format'), strtotime($datePart)),
            '[uhrzeit]' => $startTime . ' – ' . $endTime,
            '[ort]' => ($meta['location'] ?? '') ?: '–',
            '[person_name]' => $pName ?: '–',
            '[name]' => $bookerName ?: '–',
            '[email]' => $bookerEmail ?: '–',
            '[nachricht]' => $meta['booker_message'] ?? '',
            '[bestaetigungs_link]' => '',
            '[storno_link]' => $cancelUrl,
            '[impressum_link]' => $imprintUrl,
        ];

        // Mail B an Buchenden
        $tplBooker = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, 'booking_booker') ?? []) : [];
        $defBooker = MailTemplatePost::getDefault('booking_booker');
        $subjectBooker = Settings::renderTemplate(!empty($tplBooker['subject']) ? $tplBooker['subject'] : $defBooker['subject'], $vars);
        $bodyBooker = !empty($tplBooker['body']) ? $tplBooker['body'] : $defBooker['body'];
        $bodyHtmlBooker = !empty($tplBooker['body_html']) ? $tplBooker['body_html'] : $defBooker['body_html'];
        if (strpos($bodyBooker, '[storno_link]') === false)
            $bodyBooker .= "\n\nStornieren: [storno_link]";
        if (strpos($bodyBooker, '[impressum_link]') === false)
            $bodyBooker .= "\nImpressum: [impressum_link]";
        if (strpos($bodyHtmlBooker, '[storno_link]') === false)
            $bodyHtmlBooker .= '<p><a href="[storno_link]">Termin stornieren</a></p>';
        if (strpos($bodyHtmlBooker, '[impressum_link]') === false)
            $bodyHtmlBooker .= '<p><a href="[impressum_link]">Impressum</a></p>';
        $plainBooker = Settings::renderTemplate($bodyBooker, $vars);
        $htmlBooker = Settings::renderTemplate($bodyHtmlBooker, $vars);

        // Mail B an Einladenden
        $tplHost = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, 'booking_host') ?? []) : [];
        $defHost = MailTemplatePost::getDefault('booking_host');
        $subjectHost = Settings::renderTemplate(!empty($tplHost['subject']) ? $tplHost['subject'] : $defHost['subject'], $vars);
        $bodyHost = !empty($tplHost['body']) ? $tplHost['body'] : $defHost['body'];
        $bodyHtmlHost = !empty($tplHost['body_html']) ? $tplHost['body_html'] : $defHost['body_html'];
        if (strpos($bodyHost, '[storno_link]') === false)
            $bodyHost .= "\n\nStornieren: [storno_link]";
        if (strpos($bodyHost, '[impressum_link]') === false)
            $bodyHost .= "\nImpressum: [impressum_link]";
        if (strpos($bodyHtmlHost, '[storno_link]') === false)
            $bodyHtmlHost .= '<p><a href="[storno_link]">Termin stornieren</a></p>';
        if (strpos($bodyHtmlHost, '[impressum_link]') === false)
            $bodyHtmlHost .= '<p><a href="[impressum_link]">Impressum</a></p>';
        $plainHost = Settings::renderTemplate($bodyHost, $vars);
        $htmlHost = Settings::renderTemplate($bodyHtmlHost, $vars);

        $tmpFile = tempnam(get_temp_dir(), 'rrze_appt_') . '.ics';
        file_put_contents($tmpFile, $ics);

        Settings::sendMail($bookerEmail, $subjectBooker, $plainBooker, $htmlBooker, [$tmpFile]);

        $adminEmail = get_option('admin_email');
        $toAdmin = '';
        if ($personId > 0)
            $toAdmin = (string) get_post_meta($personId, 'person_email', true);
        if (!$toAdmin)
            $toAdmin = $adminEmail;
        Settings::sendMail($toAdmin, $subjectHost, $plainHost, $htmlHost, [$tmpFile]);

        @unlink($tmpFile);

        wp_die(
            '<p>' . esc_html__('Ihr Termin wurde bestätigt. Eine Bestätigung wurde per E-Mail versendet.', 'rrze-appointment') . '</p>' .
            '<p><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Zurück zur Startseite', 'rrze-appointment') . '</a></p>',
            esc_html__('Termin bestätigt', 'rrze-appointment'),
            ['response' => 200]
        );
        } catch (CustomException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 500]);
        }
    }

    public function handleCancel(): void
    {
        try {
            $token = sanitize_text_field($_GET['rrze_appt_cancel'] ?? '');
            if (!$token) return;

        $entry = TokenManager::validateCancelToken($token);
        if (!$entry) {
            wp_die(__('Dieser Storno-Link ist ungültig oder wurde bereits verwendet.', 'rrze-appointment'), '', ['response' => 410]);
        }

        TokenManager::deleteCancelToken($token);

        if ($entry['type'] === 'pending') {
            // Anfrage noch nicht bestätigt — Pending-Eintrag entfernen
            $pending = (array) get_option(TokenManager::PENDING_OPTION, []);
            foreach ($pending as $pToken => $pEntry) {
                if ($pEntry['slot'] === $entry['slot']) {
                    unset($pending[$pToken]);
                }
            }
            update_option(TokenManager::PENDING_OPTION, $pending, false);
        } else {
            Bookings::cancel($entry['slot']);
        }

        wp_die(
            '<p>' . esc_html__('Ihr Termin wurde storniert.', 'rrze-appointment') . '</p>' .
            '<p><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Zurück zur Startseite', 'rrze-appointment') . '</a></p>',
            esc_html__('Termin storniert', 'rrze-appointment'),
            ['response' => 200]
        );
        } catch (CustomException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 500]);
        }
    }

}

