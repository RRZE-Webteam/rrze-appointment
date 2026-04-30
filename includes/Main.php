<?php

namespace RRZE\Appointment;

// use function RRZE\Appointment\plugin;

use WP_REST_Request;
use WP_REST_Response;
use RRZE\Appointment\Rights;
use RRZE\Appointment\Defaults;
use RRZE\Appointment\Settings;
use RRZE\Appointment\Reminder;
use RRZE\Appointment\Bookings;
use RRZE\Appointment\SlotGenerator;
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

    private function extractFirstEmailFromValue($value): string
    {
        if (is_string($value)) {
            $email = sanitize_email($value);
            return $email ?: '';
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $email = sanitize_email($entry);
                if ($email) {
                    return $email;
                }
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            foreach (['email', 'value', 'mail'] as $key) {
                if (!empty($entry[$key])) {
                    $email = sanitize_email((string) $entry[$key]);
                    if ($email) {
                        return $email;
                    }
                }
            }
        }

        return '';
    }

    private function extractFirstEmailRecursive($value): string
    {
        if (is_string($value)) {
            $email = sanitize_email($value);
            return $email ?: '';
        }

        if (!is_array($value)) {
            return '';
        }

        // Prioritize known email-like keys first.
        foreach (['email', 'emails', 'emailAddress', 'emailAddresses', 'mail', 'mails', 'value'] as $key) {
            if (!array_key_exists($key, $value) || empty($value[$key])) {
                continue;
            }

            $email = $this->extractFirstEmailFromValue($value[$key]);
            if ($email) {
                return $email;
            }
        }

        // Fallback: recursively inspect nested arrays/objects.
        foreach ($value as $nested) {
            if (!is_array($nested) && !is_string($nested)) {
                continue;
            }
            $email = $this->extractFirstEmailRecursive($nested);
            if ($email) {
                return $email;
            }
        }

        return '';
    }

    private function extractFirstWorkplaceEmail(array $workplace): string
    {
        foreach (['email', 'emails', 'emailAddress', 'emailAddresses', 'mail'] as $key) {
            if (empty($workplace[$key])) {
                continue;
            }

            $email = $this->extractFirstEmailFromValue($workplace[$key]);
            if ($email) {
                return $email;
            }
        }

        // Some FAUdir workplace payloads keep email addresses deeply nested.
        return $this->extractFirstEmailRecursive($workplace);
    }

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
        add_action('template_redirect', [$this, 'handleSsoLogin']);
        add_action('template_redirect', [$this, 'handleConfirm']);
        add_action('template_redirect', [$this, 'handleCancel']);
        add_action('rrze_appointment_expire_pending', ['RRZE\Appointment\TokenManager', 'expirePending']);
        add_action('save_post', [$this, 'handleSavePost'], 10, 2);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }


    public function registerRestRoutes()
    {
        register_rest_route('rrze/v2/appointment', '/booker', [
            'methods' => 'POST',
            'callback' => [$this, 'handleGetBooker'],
            'permission_callback' => [$this, 'allowBookerRequest'],
        ]);
    }

    public function allowBookerRequest($request)
    {
        return true;
    }

    /**
     * Allow needed HTML on post content sanitized by wp_kses_post().
     *
     * @param array  $allowed_tags The current allowed tags/attributes for the given context.
     * @param string $context      KSES context; wp_kses_post() uses 'post'.
     * @return array               Modified allowed tags/attributes.
     */
    public function my_custom_allowed_html($allowed_tags, $context)
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
            return ['error' => true, 'message' => __('Tip: Activate the RRZE FAUdir plugin to conveniently import person data.', 'rrze-appointment'), 'data' => []];
        }

        $posts = get_posts([
            'post_type' => 'custom_person',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);

        if (!class_exists('\RRZE\FAUdir\API') || !class_exists('\RRZE\FAUdir\Config')) {
            return ['error' => true, 'message' => __('FAUdir classes not available.', 'rrze-appointment'), 'data' => []];
        }

        $config = new \RRZE\FAUdir\Config();
        $api = new \RRZE\FAUdir\API($config);

        $result = [];
        foreach ($posts as $post_id) {
            $faudir_id = (string) get_post_meta($post_id, 'person_id', true);
            if ($faudir_id === '')
                continue;

            $person = $api->getPerson($faudir_id);
            if (!is_array($person) || empty($person))
                continue;

            $given = $person['givenName'] ?? '';
            $family = $person['familyName'] ?? '';
            $prefix = $person['honorificPrefix'] ?? '';
            $email = sanitize_email($person['email'] ?? '');
            $label = trim("$given $family") ?: get_the_title($post_id);

            $consultationHours = [];
            $hoursType = null;
            $location = '';
            $locationUrl = '';

            foreach ($person['contacts'] ?? [] as $contact) {
                $contact_detail = [];
                $contact_id = $contact['identifier'] ?? '';
                if ($contact_id) {
                    $contact_detail = $api->getContact($contact_id);
                }

                if (!is_array($contact_detail) || empty($contact_detail)) {
                    // Some datasets already include workplace/contact data inline.
                    $contact_detail = is_array($contact) ? $contact : [];
                }

                foreach ($contact_detail['workplaces'] ?? [] as $wp) {
                    if (!$email) {
                        $email = $this->extractFirstWorkplaceEmail((array) $wp);
                    }

                    if (!empty($wp['consultationHours'])) {
                        $consultationHours = $wp['consultationHours'];
                        $hoursType = 'consultation';
                    } elseif (!empty($wp['officeHours'])) {
                        $consultationHours = $wp['officeHours'];
                        $hoursType = 'office';
                    }
                    if (!empty($consultationHours)) {
                        $location = implode(', ', array_filter([$wp['room'] ?? '', $wp['street'] ?? '', $wp['city'] ?? '']));
                        $locationUrl = $wp['faumap'] ?? '';
                        break 2;
                    }
                }
            }

            // Additional safety net: some responses keep workplaces directly on person.
            if (!$email) {
                foreach ($person['workplaces'] ?? [] as $workplace) {
                    $email = $this->extractFirstWorkplaceEmail((array) $workplace);
                    if ($email) {
                        break;
                    }
                }
            }

            $result[] = [
                'id' => $post_id,
                'error' => false,
                'message' => '',
                'label' => $label,
                'honorificPrefix' => $prefix,
                'givenName' => $given,
                'familyName' => $family,
                'email' => $email,
                'location' => $location,
                'locationUrl' => $locationUrl,
                'consultationHours' => $consultationHours,
                'hoursType' => $hoursType,
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
                $booked = (array) get_option('rrze_appointment_booked_slots', []);
                $pending = TokenManager::getPendingSlots();
                wp_localize_script($viewHandle, 'rrze_appointment', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'restUrl' => rest_url('rrze/v2/appointment/booker'),                    
                    'nonce' => wp_create_nonce('rrze_appointment_book'),
                    'bookedSlots' => array_values(array_unique(array_merge($booked, $pending))),
                    'i18n' => [
                        'waitlist' => __('Yes, I would like to be notified if an earlier appointment becomes available.', 'rrze-appointment'),
                        'yourAppointment' => __('Your appointment on %s at %s', 'rrze-appointment'),
                        'yourEmail' => __('Your email address:', 'rrze-appointment'),
                        'yourName' => __('Your name:', 'rrze-appointment'),
                        'message' => __('Message (optional):', 'rrze-appointment'),
                        'messageOptional' => __('Message (optional):', 'rrze-appointment'),
                        'nameRequired' => __('Please enter your name.', 'rrze-appointment'),
                        'emailRequired' => __('Please enter a valid email address.', 'rrze-appointment'),
                        'messageRequired' => __('Please enter a message.', 'rrze-appointment'),
                        'book' => __('Book', 'rrze-appointment'),
                        'cancel' => __('Cancel', 'rrze-appointment'),
                        'booking' => __('Booking…', 'rrze-appointment'),
                        'booked' => __('Appointment booked! A confirmation has been sent.', 'rrze-appointment'),
                        'close' => __('Close', 'rrze-appointment'),
                        'bookingError' => __('Error booking appointment.', 'rrze-appointment'),
                        'networkError' => __('Network error. Please try again.', 'rrze-appointment'),
                        'availableOn' => __('Available appointments on %s', 'rrze-appointment'),
                        'allAppointments' => __('All appointments', 'rrze-appointment'),
                        'slotsOnDay' => __('Times on selected day', 'rrze-appointment'),
                    ],
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
            $data = wp_json_encode([
                'persons' => $persons,
                'recurrenceLimit' => (int) Settings::get('recurrence_limit'),
                'editorI18n' => [
                    'requireMessageField' => __('Require message field', 'rrze-appointment'),
                    'requireMessageHelp' => __('If enabled, users must fill in the message field during booking.', 'rrze-appointment'),
                    'hideAllAppointmentsField' => __('Hide "All appointments" accordion', 'rrze-appointment'),
                    'hideAllAppointmentsHelp' => __('If enabled, the grouped list under "All appointments" is hidden on the frontend.', 'rrze-appointment'),
                ],
            ]);
        } catch (CustomException $e) {
            $data = wp_json_encode([
                'persons' => ['error' => true, 'message' => $e->getMessage(), 'data' => []],
                'recurrenceLimit' => 52,
                'editorI18n' => [
                    'requireMessageField' => __('Require message field', 'rrze-appointment'),
                    'requireMessageHelp' => __('If enabled, users must fill in the message field during booking.', 'rrze-appointment'),
                    'hideAllAppointmentsField' => __('Hide "All appointments" accordion', 'rrze-appointment'),
                    'hideAllAppointmentsHelp' => __('If enabled, the grouped list under "All appointments" is hidden on the frontend.', 'rrze-appointment'),
                ],
            ]);
        }
        wp_add_inline_script('rrze-appointment-editor-script', 'window.rrze_appointment = ' . $data . ';', 'before');
    }

    public function handleGetPersons(): void
    {
        check_ajax_referer('rrze_appointment_persons', 'nonce');
        wp_send_json($this->getFAUdirPersons());
    }


    public function handleGetBooker($request = null)
    {
        $isRestRequest = $request instanceof WP_REST_Request;

        try {
            $requestReturnTo = '';
            if ($isRestRequest) {
                $requestReturnTo = (string) ($request->get_param('returnTo') ?? '');
            } elseif (isset($_POST['returnTo'])) {
                $requestReturnTo = sanitize_text_field(wp_unslash($_POST['returnTo']));
            }
            $redirectUrl = wp_validate_redirect($requestReturnTo, wp_get_referer() ?: home_url('/'));
            $loginUrl = add_query_arg([
                'rrze_appt_sso' => '1',
                'rrze_appt_return' => $redirectUrl,
            ], home_url('/'));

            if (!class_exists('\RRZE\AccessControl\Permissions')) {
                $response = [
                    'needsLogin' => true,
                    'loginUrl' => $loginUrl,
                    'data' => null,
                    'error' => 'AccessControl not available'
                ];
                if ($isRestRequest) {
                    return new WP_REST_Response($response, 200);
                }
                wp_send_json_error($response);
            }

            // Passive SSO check only: never trigger auth flow in this REST handler.
            $serverBooker = Rights::get();
            $idm = $serverBooker['idm'] ?? null;
            $bookerEmail = $serverBooker['bookerEmail'] ?? '';
            $bookerName = $serverBooker['bookerName'] ?? '';
            $bookerAttributes = is_array($serverBooker['attributes'] ?? null) ? $serverBooker['attributes'] : [];

            if (!$idm) {
                $response = [
                    'needsLogin' => true,
                    'loginUrl' => $loginUrl,
                    'data' => [
                        'idm' => null,
                        'bookerEmail' => '',
                        'bookerName' => '',
                        'attributes' => []
                    ]
                ];
                if ($isRestRequest) {
                    return new WP_REST_Response($response, 200);
                }
                wp_send_json_error($response);
            }

            $response = [
                'needsLogin' => false,
                'loginUrl' => '',
                'data' => [
                    'idm' => $idm,
                    'bookerEmail' => $bookerEmail,
                    'bookerName' => $bookerName,
                    'attributes' => $bookerAttributes
                ]
            ];
            if ($isRestRequest) {
                return new WP_REST_Response($response, 200);
            }
            wp_send_json_success($response['data']);

        } catch (\Throwable $e) {
            $response = [
                'needsLogin' => true,
                'loginUrl' => '',
                'error' => $e->getMessage(),
                'data' => null
            ];
            if ($isRestRequest) {
                return new WP_REST_Response($response, 200);
            }
            wp_send_json_error($response);
        }
    }

    public function handleSsoLogin(): void
    {
        if (empty($_GET['rrze_appt_sso'])) {
            return;
        }

        $returnToParam = isset($_GET['rrze_appt_return']) ? wp_unslash($_GET['rrze_appt_return']) : '';
        $returnTo = $returnToParam ?: remove_query_arg(['rrze_appt_sso', 'rrze_appt_return']);
        $returnTo = wp_validate_redirect($returnTo, home_url('/'));

        if (!class_exists('\RRZE\AccessControl\Permissions')) {
            wp_die(esc_html__('SSO is not available.', 'rrze-appointment'), '', ['response' => 500]);
        }

        try {
            $permissions = new \RRZE\AccessControl\Permissions();
            $loggedIn = false;
            if (method_exists($permissions, 'checkSSOLoggedIn')) {
                try {
                    $loggedIn = (bool) $permissions->checkSSOLoggedIn();
                } catch (\Throwable $e) {
                    $loggedIn = false;
                }
            }

            if ($loggedIn) {
                wp_safe_redirect($returnTo);
                exit;
            }

            $auth = method_exists($permissions, 'simplesamlAuth') ? $permissions->simplesamlAuth() : null;
            if (is_object($auth)) {
                if (method_exists($auth, 'isAuthenticated') && $auth->isAuthenticated()) {
                    wp_safe_redirect($returnTo);
                    exit;
                }

                if (method_exists($auth, 'requireAuth')) {
                    $auth->requireAuth([
                        'ReturnTo' => $returnTo,
                        'KeepPost' => false,
                    ]);
                    wp_safe_redirect($returnTo);
                    exit;
                }
            }

            // Last fallback: try the plugin-level check once again in case it performs redirects internally.
            if (method_exists($permissions, 'checkSSOLoggedIn')) {
                $permissions->checkSSOLoggedIn();
                wp_safe_redirect($returnTo);
                exit;
            }

            wp_die(esc_html__('SSO is not available.', 'rrze-appointment'), '', ['response' => 500]);
        } catch (\Throwable $e) {
            wp_die(esc_html__('SSO login failed.', 'rrze-appointment'), '', ['response' => 500]);
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
            $title = sanitize_text_field($_POST['title'] ?? __('Appointment', 'rrze-appointment'));
            $location = sanitize_text_field($_POST['location'] ?? '');
            $personId = (int) ($_POST['person_id'] ?? 0);
            $personEmail = sanitize_email($_POST['person_email'] ?? '');
            $bookerName = sanitize_text_field($_POST['booker_name'] ?? '');
            $bookerMsg = sanitize_textarea_field($_POST['booker_message'] ?? '');
            $bookerWaitlist = !empty($_POST['booker_waitlist']) && $_POST['booker_waitlist'] === '1';
            $requireMessage = !empty($_POST['require_message']) && $_POST['require_message'] === '1';

            // E-Mail immer aus der Server-Session lesen, nie vom Client
            $serverBooker = Rights::get();
            $bookerEmail = sanitize_email($serverBooker['bookerEmail'] ?? '');
            if (!$bookerEmail) {
                wp_send_json_error(__('No authenticated email address found.', 'rrze-appointment'));
                return;
            }
            $tplId = (int) ($_POST['tpl_id'] ?? 0);

            if (!$slot)
                wp_send_json_error(__('No appointment specified.', 'rrze-appointment'));
            if (!$bookerEmail)
                wp_send_json_error(__('Please provide an email address.', 'rrze-appointment'));
            if (!$bookerName)
                wp_send_json_error(__('Please provide your name.', 'rrze-appointment'));
            if ($requireMessage && !$bookerMsg)
                wp_send_json_error(__('Please provide a message.', 'rrze-appointment'));

            [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
            [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

            if (!$datePart || !$startTime || !$endTime)
                wp_send_json_error(__('Invalid appointment format.', 'rrze-appointment'));

            // Slot already booked or pending?
            $booked = (array) get_option('rrze_appointment_booked_slots', []);
            $pending = TokenManager::getPendingSlots();
            if (in_array($slot, $booked, true) || in_array($slot, $pending, true)) {
                wp_send_json_error(__('This appointment is no longer available.', 'rrze-appointment'));
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
                'person_email' => $personEmail,
                'booker_email' => $bookerEmail,
                'booker_name' => $bookerName,
                'booker_message' => $bookerMsg,
                'booker_waitlist' => $bookerWaitlist,
                'tpl_id' => $tplId,
            ];

            $confirmToken = TokenManager::createPending($slot, $meta);
            $confirmUrl = TokenManager::confirmUrl($confirmToken);
            $imprintUrl = TokenManager::imprintUrl();

            $vars = [
                '[title]' => $title,
                '[date]' => date_i18n(get_option('date_format'), strtotime($datePart)),
                '[time]' => $startTime . ' – ' . $endTime,
                '[location]' => $location ?: '–',
                '[person_name]' => $pName ?: '–',
                '[name]' => $bookerName ?: '–',
                '[email]' => $bookerEmail ?: '–',
                '[message]' => $bookerMsg ?: '',
                '[confirmation_link]' => $confirmUrl,
                '[cancel_link]' => TokenManager::cancelUrl(TokenManager::createPendingCancelToken($slot, $meta)),
                '[imprint_link]' => $imprintUrl,
            ];

            $tpl = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, 'booking_pending') ?? []) : [];
            $def = MailTemplatePost::getDefault('booking_pending');
            $subject = Settings::renderTemplate(!empty($tpl['subject']) ? $tpl['subject'] : $def['subject'], $vars);
            $bodyTpl = !empty($tpl['body']) ? $tpl['body'] : $def['body'];
            $bodyHtmlTpl = !empty($tpl['body_html']) ? $tpl['body_html'] : $def['body_html'];
            if (strpos($bodyTpl, '[confirmation_link]') === false)
                $bodyTpl .= "\n\n" . __('Confirmation', 'rrze-appointment') . ": [confirmation_link]";
            if (strpos($bodyTpl, '[imprint_link]') === false)
                $bodyTpl .= "\n" . __('Imprint', 'rrze-appointment') . ": [imprint_link]";
            if (strpos($bodyHtmlTpl, '[confirmation_link]') === false)
                $bodyHtmlTpl .= '<p><a href="[confirmation_link]">' . __('Confirm appointment now', 'rrze-appointment') . '</a></p>';
            if (strpos($bodyHtmlTpl, '[imprint_link]') === false)
                $bodyHtmlTpl .= '<p><a href="[imprint_link]">' . __('Imprint', 'rrze-appointment') . '</a></p>';
            $plain = Settings::renderTemplate($bodyTpl, $vars);
            $html = Settings::renderTemplate($bodyHtmlTpl, $vars);

            Settings::sendMail($bookerEmail, $subject, $plain, $html);

            wp_send_json_success(['message' => __('Please confirm your appointment by email.', 'rrze-appointment')]);
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
            if (!$token)
                return;

            $entry = TokenManager::confirmPending($token);
            if (!$entry) {
                wp_die(__('This confirmation link has expired or is invalid.', 'rrze-appointment'), '', ['response' => 410]);
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
                '[title]' => $meta['title'] ?? '',
                '[date]' => date_i18n(get_option('date_format'), strtotime($datePart)),
                '[time]' => $startTime . ' – ' . $endTime,
                '[location]' => ($meta['location'] ?? '') ?: '–',
                '[person_name]' => $pName ?: '–',
                '[name]' => $bookerName ?: '–',
                '[email]' => $bookerEmail ?: '–',
                '[message]' => $meta['booker_message'] ?? '',
                '[confirmation_link]' => '',
                '[cancel_link]' => $cancelUrl,
                '[imprint_link]' => $imprintUrl,
            ];

            // Mail B an Buchenden
            $tplBooker = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, 'booking_booker') ?? []) : [];
            $defBooker = MailTemplatePost::getDefault('booking_booker');
            $subjectBooker = Settings::renderTemplate(!empty($tplBooker['subject']) ? $tplBooker['subject'] : $defBooker['subject'], $vars);
            $bodyBooker = !empty($tplBooker['body']) ? $tplBooker['body'] : $defBooker['body'];
            $bodyHtmlBooker = !empty($tplBooker['body_html']) ? $tplBooker['body_html'] : $defBooker['body_html'];
            if (strpos($bodyBooker, '[cancel_link]') === false)
                $bodyBooker .= "\n\n" . __('Cancel', 'rrze-appointment') . ": [cancel_link]";
            if (strpos($bodyBooker, '[imprint_link]') === false)
                $bodyBooker .= "\n" . __('Imprint', 'rrze-appointment') . ": [imprint_link]";
            if (strpos($bodyHtmlBooker, '[cancel_link]') === false)
                $bodyHtmlBooker .= '<p><a href="[cancel_link]">' . __('Cancel appointment', 'rrze-appointment') . '</a></p>';
            if (strpos($bodyHtmlBooker, '[imprint_link]') === false)
                $bodyHtmlBooker .= '<p><a href="[imprint_link]">' . __('Imprint', 'rrze-appointment') . '</a></p>';
            $plainBooker = Settings::renderTemplate($bodyBooker, $vars);
            $htmlBooker = Settings::renderTemplate($bodyHtmlBooker, $vars);

            // Mail B an Einladenden
            $tplHost = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, 'booking_host') ?? []) : [];
            $defHost = MailTemplatePost::getDefault('booking_host');
            $subjectHost = Settings::renderTemplate(!empty($tplHost['subject']) ? $tplHost['subject'] : $defHost['subject'], $vars);
            $bodyHost = !empty($tplHost['body']) ? $tplHost['body'] : $defHost['body'];
            $bodyHtmlHost = !empty($tplHost['body_html']) ? $tplHost['body_html'] : $defHost['body_html'];
            if (strpos($bodyHost, '[cancel_link]') === false)
                $bodyHost .= "\n\n" . __('Cancel', 'rrze-appointment') . ": [cancel_link]";
            if (strpos($bodyHost, '[imprint_link]') === false)
                $bodyHost .= "\n" . __('Imprint', 'rrze-appointment') . ": [imprint_link]";
            if (strpos($bodyHtmlHost, '[cancel_link]') === false)
                $bodyHtmlHost .= '<p><a href="[cancel_link]">' . __('Cancel appointment', 'rrze-appointment') . '</a></p>';
            if (strpos($bodyHtmlHost, '[imprint_link]') === false)
                $bodyHtmlHost .= '<p><a href="[imprint_link]">' . __('Imprint', 'rrze-appointment') . '</a></p>';
            $plainHost = Settings::renderTemplate($bodyHost, $vars);
            $htmlHost = Settings::renderTemplate($bodyHtmlHost, $vars);

            $tmpFile = tempnam(get_temp_dir(), 'rrze_appt_') . '.ics';
            file_put_contents($tmpFile, $ics);

            Settings::sendMail($bookerEmail, $subjectBooker, $plainBooker, $htmlBooker, [$tmpFile]);

            $toAdmin = sanitize_email((string) ($meta['person_email'] ?? ''));
            if ($toAdmin) {
                Settings::sendMail($toAdmin, $subjectHost, $plainHost, $htmlHost, [$tmpFile]);
            }

            @unlink($tmpFile);

            wp_die(
                '<p>' . esc_html__('Your appointment has been confirmed. A confirmation has been sent by email.', 'rrze-appointment') . '</p>' .
                '<p><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Back to homepage', 'rrze-appointment') . '</a></p>',
                esc_html__('Appointment confirmed', 'rrze-appointment'),
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
            if (!$token)
                return;

            $entry = TokenManager::validateCancelToken($token);
            if (!$entry) {
                wp_die(__('This cancellation link is invalid or has already been used.', 'rrze-appointment'), '', ['response' => 410]);
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
                '<p>' . esc_html__('Your appointment has been cancelled.', 'rrze-appointment') . '</p>' .
                '<p><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Back to homepage', 'rrze-appointment') . '</a></p>',
                esc_html__('Appointment cancelled', 'rrze-appointment'),
                ['response' => 200]
            );
        } catch (CustomException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 500]);
        }
    }
    public function handleSavePost(int $postId, \WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId))
            return;
        if (!has_blocks($post->post_content))
            return;

        $today = date('Y-m-d');
        $allMeta = (array) get_option(Bookings::META_OPTION, []);

        // Collect all waitlisted bookings grouped by person_id
        $waitlisted = []; // person_id => [ [slot, meta], ... ]
        foreach ($allMeta as $bookedSlot => $bookedMeta) {
            if (empty($bookedMeta['booker_waitlist']))
                continue;
            $bookedDate = explode(' ', $bookedSlot)[0] ?? '';
            if ($bookedDate < $today)
                continue;
            $pid = (int) ($bookedMeta['person_id'] ?? 0);
            $waitlisted[$pid][] = ['slot' => $bookedSlot, 'meta' => $bookedMeta];
        }

        if (empty($waitlisted))
            return;

        $blocks = parse_blocks($post->post_content);
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') !== 'rrze/appointment')
                continue;

            $attrs = $block['attrs'] ?? [];
            $personId = (int) ($attrs['personId'] ?? 0);
            if (!isset($waitlisted[$personId]))
                continue;

            $newSlots = SlotGenerator::fromAttributes($attrs);
            if (empty($newSlots))
                continue;

            // Only future slots that are not already booked
            $bookedSlots = (array) get_option(Bookings::SLOTS_OPTION, []);
            $bookedSet = array_flip($bookedSlots);
            $newSlots = array_filter($newSlots, fn($s) => !isset($bookedSet[$s]) && explode(' ', $s)[0] >= $today);

            foreach ($waitlisted[$personId] as $entry) {
                $bookedSlot = $entry['slot'];
                $bookedMeta = $entry['meta'];
                $bookedDate = explode(' ', $bookedSlot)[0];

                // Find new slots that are earlier than the booked slot
                $earlier = array_filter($newSlots, fn($s) => $s < $bookedSlot);
                if (empty($earlier))
                    continue;

                Bookings::sendWaitlistNotificationStatic(
                    (string) min($earlier), // earliest new slot
                    $attrs,
                    $bookedSlot,
                    $bookedMeta
                );
            }
        }
    }

}

