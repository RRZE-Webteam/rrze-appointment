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
use RRZE\Appointment\AppointmentBlock;
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
        MailTemplatePost::ensureEditableDefaultTemplateExists();
        TokenManager::cleanupPendingState();

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
        add_action('template_redirect', [$this, 'handleWaitlistOptOut']);
        add_action(TokenManager::PENDING_EXPIRY_HOOK, [TokenManager::class, 'expirePending']);
        add_action('post_updated', [$this, 'handlePostUpdated'], 10, 3);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }


    public function registerRestRoutes()
    {
        register_rest_route('rrze/v2/appointment', '/booker', [
            'methods' => 'POST',
            'callback' => [$this, 'handleGetBooker'],
            'permission_callback' => [$this, 'allowBookerRequest'],
        ]);
        register_rest_route('rrze/v2/appointment', '/persons', [
            'methods' => 'GET',
            'callback' => [$this, 'handleGetPersons'],
            'permission_callback' => static function (): bool {
                return current_user_can('edit_posts');
            },
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
            'nav'
        ];

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
                        'dialogTitle' => __('Request appointment', 'rrze-appointment'),
                        'dialogIntro' => __('Enter your details to request this appointment. You will receive an email to confirm it.', 'rrze-appointment'),
                        'selectedAppointment' => __('Selected appointment', 'rrze-appointment'),
                        'closeDialog' => __('Close dialog', 'rrze-appointment'),
                        'successTitle' => __('Check your inbox', 'rrze-appointment'),
                        'waitlist' => __('Notify me if an earlier appointment becomes available.', 'rrze-appointment'),
                        'yourAppointment' => __('Your appointment on %s at %s', 'rrze-appointment'),
                        'yourEmail' => __('Email address', 'rrze-appointment'),
                        'yourName' => __('Name', 'rrze-appointment'),
                        'namePlaceholder' => __('First and last name', 'rrze-appointment'),
                        'message' => __('Message', 'rrze-appointment'),
                        'messageOptional' => __('Message (optional)', 'rrze-appointment'),
                        'messagePlaceholder' => __('What would you like to discuss?', 'rrze-appointment'),
                        'nameRequired' => __('Enter your name.', 'rrze-appointment'),
                        'emailRequired' => __('Enter a valid email address.', 'rrze-appointment'),
                        'messageRequired' => __('Enter a message.', 'rrze-appointment'),
                        'book' => __('Request appointment', 'rrze-appointment'),
                        'cancel' => __('Cancel', 'rrze-appointment'),
                        'booking' => __('Sending request…', 'rrze-appointment'),
                        'booked' => __('Check your inbox to confirm the appointment. We sent a confirmation link to your email address.', 'rrze-appointment'),
                        'close' => __('Close', 'rrze-appointment'),
                        'bookingError' => __("We couldn't request this appointment. Please try again.", 'rrze-appointment'),
                        'networkError' => __('Connection problem. Check your internet connection and try again.', 'rrze-appointment'),
                        'availableOn' => __('Available appointments on %s', 'rrze-appointment'),
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
            $data = wp_json_encode([
                'faudir' => [
                    'available' => post_type_exists('custom_person')
                        && class_exists('\RRZE\FAUdir\API')
                        && class_exists('\RRZE\FAUdir\Config'),
                    'personsPath' => '/rrze/v2/appointment/persons',
                ],
                'recurrenceLimit' => (int) Settings::get('recurrence_limit'),
                'editorI18n' => [
                    'requireMessageField' => __('Require a message', 'rrze-appointment'),
                    'requireMessageHelp' => __('People must enter a message when requesting an appointment.', 'rrze-appointment'),
                    'hideWeekendsField' => __('Hide weekends', 'rrze-appointment'),
                    'hideWeekendsHelp' => __('Only show Monday through Friday in the calendar.', 'rrze-appointment'),
                ],
            ]);
        } catch (CustomException $e) {
            $data = wp_json_encode([
                'faudir' => [
                    'available' => false,
                    'personsPath' => '/rrze/v2/appointment/persons',
                ],
                'recurrenceLimit' => 52,
                'editorI18n' => [
                    'requireMessageField' => __('Require a message', 'rrze-appointment'),
                    'requireMessageHelp' => __('People must enter a message when requesting an appointment.', 'rrze-appointment'),
                    'hideWeekendsField' => __('Hide weekends', 'rrze-appointment'),
                    'hideWeekendsHelp' => __('Only show Monday through Friday in the calendar.', 'rrze-appointment'),
                ],
            ]);
        }
        wp_add_inline_script('rrze-appointment-editor-script', 'window.rrze_appointment = ' . $data . ';', 'before');
    }

    public function handleGetPersons()
    {
        return rest_ensure_response($this->getFAUdirPersons());
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
            $isAuthenticated = !empty($serverBooker['authenticated']);
            $bookerEmail = $serverBooker['bookerEmail'] ?? '';
            $bookerName = $serverBooker['bookerName'] ?? '';

            if (!$isAuthenticated) {
                $response = [
                    'needsLogin' => true,
                    'loginUrl' => $loginUrl,
                    'data' => [
                        'bookerEmail' => '',
                        'bookerName' => '',
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
                    'bookerEmail' => $bookerEmail,
                    'bookerName' => $bookerName,
                ]
            ];
            if ($isRestRequest) {
                return new WP_REST_Response($response, 200);
            }
            wp_send_json_success($response['data']);

        } catch (\Throwable) {
            $response = [
                'needsLogin' => true,
                'loginUrl' => '',
                'error' => __('SSO login failed.', 'rrze-appointment'),
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
            $postId = absint($_POST['post_id'] ?? 0);
            $blockFingerprint = sanitize_text_field($_POST['block_id'] ?? '');
            $postedBookerEmail = sanitize_email($_POST['booker_email'] ?? '');
            $postedBookerName = sanitize_text_field($_POST['booker_name'] ?? '');
            $bookerMsg = sanitize_textarea_field($_POST['booker_message'] ?? '');
            $bookerWaitlist = !empty($_POST['booker_waitlist']) && $_POST['booker_waitlist'] === '1';
            if (!$slot) {
                wp_send_json_error(__('No appointment specified.', 'rrze-appointment'));
            }

            $bookingContext = AppointmentBlock::resolvePublished(
                $postId,
                $blockFingerprint,
                $slot
            );
            if (is_wp_error($bookingContext)) {
                wp_send_json_error($bookingContext->get_error_message());
            }

            $title = $bookingContext['title'];
            $location = $bookingContext['location'];
            $personId = $bookingContext['person_id'];
            $personEmail = $bookingContext['person_email'];
            $pName = $bookingContext['person_name'];
            $tplId = $bookingContext['tpl_id'];
            $requireMessage = $bookingContext['require_message'];
            $questions = $bookingContext['questions'];
            $disableSso = $bookingContext['disable_sso'];
            $postLink = $bookingContext['post_link'];

            if ($disableSso) {
                $bookerEmail = $postedBookerEmail;
                $bookerName = $postedBookerName;
            } else {
                // E-Mail immer aus der Server-Session lesen, nie vom Client
                $serverBooker = Rights::get();
                $isSsoAuthenticated = !empty($serverBooker['authenticated']);
                $bookerEmail = sanitize_email($serverBooker['bookerEmail'] ?? '');
                $serverBookerName = sanitize_text_field($serverBooker['bookerName'] ?? '');
                $bookerName = $isSsoAuthenticated ? $serverBookerName : $postedBookerName;
                if (!$isSsoAuthenticated || !$bookerEmail) {
                    wp_send_json_error(__('No authenticated email address found.', 'rrze-appointment'));
                    return;
                }
            }

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

            // Custom-question answers must never be added here. This array is
            // stored in the database while pending and after confirmation.
            $meta = [
                'title' => $title,
                'location' => $location,
                'person_id' => $personId,
                'person_name' => $pName,
                'person_email' => $personEmail,
                'booker_email' => $bookerEmail,
                'booker_name' => $bookerName,
                'booker_message' => $bookerMsg,
                'booker_waitlist' => $bookerWaitlist,
                'waitlist_notified_slots' => [],
                'tpl_id' => $tplId,
                'post_link' => $postLink,
                // Question definitions are safe to keep with the pending
                // request. User answers are collected only on confirmation
                // and must never be added to this stored metadata.
                'questions' => $questions,
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
                '[message]' => $bookerMsg,
                '[questions]' => '',
                '[confirmation_link]' => $confirmUrl,
                '[cancel_link]' => TokenManager::cancelUrl(TokenManager::createPendingCancelToken($confirmToken)),
                '[imprint_link]' => $imprintUrl,
                '[post_link]' => $postLink,
            ];

            $pendingTemplateType = empty($questions) ? 'booking_pending' : 'booking_pending_questions';
            $tpl = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, $pendingTemplateType) ?? []) : [];
            $def = MailTemplatePost::getDefault($pendingTemplateType);
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

            Settings::sendMail($bookerEmail, $subject, $plain, $html, [], MailTemplate::STATUS_WARNING);

            wp_send_json_success(['message' => __('Please confirm your appointment by email.', 'rrze-appointment')]);
        } catch (CustomException $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Validates question answers submitted on the confirmation page.
     *
     * The returned answers are request-scoped and must never be added to
     * pending or confirmed booking metadata.
     *
     * @return array{answers: array<int, array{label: string, answer: string}>, values: array<string, string>, error: string}
     */
    private function validateQuestionAnswers(array $questions, array $postedAnswers): array
    {
        $answers = [];
        $values = [];
        $error = '';

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionId = sanitize_key((string) ($question['id'] ?? ''));
            $label = sanitize_text_field((string) ($question['label'] ?? ''));
            if ($questionId === '' || $label === '') {
                continue;
            }

            $rawAnswer = $postedAnswers[$questionId] ?? '';
            $answer = is_scalar($rawAnswer) ? (string) $rawAnswer : '';
            $answer = ($question['type'] ?? '') === 'select'
                ? sanitize_text_field($answer)
                : sanitize_textarea_field($answer);
            $answer = trim(function_exists('mb_substr')
                ? mb_substr($answer, 0, 5000)
                : substr($answer, 0, 5000));
            $values[$questionId] = $answer;

            if (!empty($question['required']) && $answer === '' && $error === '') {
                $error = sprintf(
                    /* translators: %s: Question that requires an answer. */
                    __('Please answer “%s”.', 'rrze-appointment'),
                    $label
                );
            }

            $options = is_array($question['options'] ?? null) ? $question['options'] : [];
            if (
                ($question['type'] ?? '') === 'select'
                && $answer !== ''
                && !in_array($answer, $options, true)
                && $error === ''
            ) {
                $error = sprintf(
                    /* translators: %s: Question with an invalid dropdown answer. */
                    __('Select a valid answer for “%s”.', 'rrze-appointment'),
                    $label
                );
            }

            if ($answer !== '') {
                $answers[] = [
                    'label' => $label,
                    'answer' => $answer,
                ];
            }
        }

        return [
            'answers' => $answers,
            'values' => $values,
            'error' => $error,
        ];
    }

    /**
     * Formats request-scoped answers for the host's plain-text and HTML mail.
     *
     * @return array{plain: string, html: string}
     */
    private function formatQuestionAnswers(array $answers): array
    {
        if (empty($answers)) {
            return ['plain' => '', 'html' => ''];
        }

        $plainRows = [];
        $htmlRows = '';
        foreach ($answers as $answer) {
            $label = (string) ($answer['label'] ?? '');
            $value = (string) ($answer['answer'] ?? '');
            $plainRows[] = $label . ': ' . $value;
            $htmlRows .= '<tr>'
                . '<th scope="row" style="width:34%;padding:10px 12px;border-bottom:1px solid #e5e9ef;color:#5f6b7a;font-size:13px;font-weight:600;line-height:20px;text-align:left;vertical-align:top;">'
                . esc_html($label)
                . '</th><td style="padding:10px 12px;border-bottom:1px solid #e5e9ef;color:#1f2937;font-size:15px;line-height:22px;text-align:left;vertical-align:top;">'
                . nl2br(esc_html($value))
                . '</td></tr>';
        }

        return [
            'plain' => "\n\n" . __('Additional information:', 'rrze-appointment') . "\n" . implode("\n", $plainRows),
            'html' => '<h2 style="margin:28px 0 8px;color:#1f2937;font-size:20px;line-height:28px;">'
                . esc_html__('Additional information', 'rrze-appointment')
                . '</h2><table class="rrze-email-details" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:12px 0 20px;border-collapse:collapse;">'
                . $htmlRows
                . '</table>',
        ];
    }

    /**
     * Keeps the confirmed booking metadata deliberately narrow. In
     * particular, question answers are never eligible for persistence.
     */
    private function getPersistableBookingMeta(array $meta): array
    {
        return array_intersect_key($meta, array_flip([
            'title',
            'location',
            'person_id',
            'person_name',
            'person_email',
            'booker_email',
            'booker_name',
            'booker_message',
            'booker_waitlist',
            'waitlist_notified_slots',
            'tpl_id',
            'post_link',
        ]));
    }

    /**
     * Confirms a pending booking, collecting configured questions first.
     */
    public function handleConfirm(): void
    {
        try {
            $rawToken = wp_unslash($_GET['rrze_appt_confirm'] ?? '');
            $token = is_string($rawToken) ? sanitize_text_field($rawToken) : '';
            if (!$token)
                return;

            $pendingEntry = TokenManager::getPending($token);
            if (!$pendingEntry) {
                $this->renderErrorPage(
                    __('This confirmation link has expired or is invalid.', 'rrze-appointment'),
                    410
                );
            }

            $pendingMeta = is_array($pendingEntry['meta'] ?? null) ? $pendingEntry['meta'] : [];
            $questions = is_array($pendingMeta['questions'] ?? null) ? $pendingMeta['questions'] : [];
            $questionAnswers = [];

            if (!empty($questions)) {
                $isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
                if (!$isPost) {
                    $this->renderConfirmationPage($token, $questions);
                }

                $rawNonce = wp_unslash($_POST['rrze_appt_questions_nonce'] ?? '');
                $postedNonce = is_string($rawNonce) ? sanitize_text_field($rawNonce) : '';
                $postedAnswers = wp_unslash($_POST['question_answers'] ?? []);
                $postedAnswers = is_array($postedAnswers) ? $postedAnswers : [];
                $validation = $this->validateQuestionAnswers($questions, $postedAnswers);

                if (!wp_verify_nonce($postedNonce, 'rrze_appointment_confirm_questions_' . $token)) {
                    $this->renderConfirmationPage(
                        $token,
                        $questions,
                        $validation['values'],
                        __('The form has expired. Please try again.', 'rrze-appointment')
                    );
                }
                if ($validation['error'] !== '') {
                    $this->renderConfirmationPage(
                        $token,
                        $questions,
                        $validation['values'],
                        $validation['error']
                    );
                }

                $questionAnswers = $validation['answers'];
            }

            $entry = TokenManager::confirmPending($token);
            if (!$entry) {
                $this->renderErrorPage(
                    __('This confirmation link has expired or is invalid.', 'rrze-appointment'),
                    410
                );
            }

            $slot = $entry['slot'];
            $meta = $this->getPersistableBookingMeta(
                is_array($entry['meta'] ?? null) ? $entry['meta'] : []
            );

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
            $bookerEmail = $meta['booker_email'] ?? '';
            $bookerName = $meta['booker_name'] ?? '';
            $tplId = (int) ($meta['tpl_id'] ?? 0);

            $pName = trim((string) ($meta['person_name'] ?? ''));
            $questionSections = $this->formatQuestionAnswers($questionAnswers);

            $vars = [
                '[title]' => $meta['title'] ?? '',
                '[date]' => date_i18n(get_option('date_format'), strtotime($datePart)),
                '[time]' => $startTime . ' – ' . $endTime,
                '[location]' => ($meta['location'] ?? '') ?: '–',
                '[person_name]' => $pName ?: '–',
                '[name]' => $bookerName ?: '–',
                '[email]' => $bookerEmail ?: '–',
                '[message]' => $meta['booker_message'] ?? '',
                '[questions]' => '',
                '[confirmation_link]' => '',
                '[cancel_link]' => $cancelUrl,
                '[imprint_link]' => $imprintUrl,
                '[post_link]' => esc_url_raw($meta['post_link'] ?? home_url('/')),
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
            if ($questionSections['plain'] !== '' && strpos($bodyHost, '[questions]') === false)
                $bodyHost .= '[questions]';
            if ($questionSections['html'] !== '' && strpos($bodyHtmlHost, '[questions]') === false)
                $bodyHtmlHost .= '[questions]';
            if (strpos($bodyHost, '[cancel_link]') === false)
                $bodyHost .= "\n\n" . __('Cancel', 'rrze-appointment') . ": [cancel_link]";
            if (strpos($bodyHost, '[imprint_link]') === false)
                $bodyHost .= "\n" . __('Imprint', 'rrze-appointment') . ": [imprint_link]";
            if (strpos($bodyHtmlHost, '[cancel_link]') === false)
                $bodyHtmlHost .= '<p><a href="[cancel_link]">' . __('Cancel appointment', 'rrze-appointment') . '</a></p>';
            if (strpos($bodyHtmlHost, '[imprint_link]') === false)
                $bodyHtmlHost .= '<p><a href="[imprint_link]">' . __('Imprint', 'rrze-appointment') . '</a></p>';
            $plainHostVars = array_merge($vars, ['[questions]' => $questionSections['plain']]);
            $htmlHostVars = array_merge($vars, ['[questions]' => $questionSections['html']]);
            $plainHost = Settings::renderTemplate($bodyHost, $plainHostVars);
            $htmlHost = Settings::renderTemplate($bodyHtmlHost, $htmlHostVars);

            $tmpFile = tempnam(get_temp_dir(), 'rrze_appt_') . '.ics';
            file_put_contents($tmpFile, $ics);

            Settings::sendMail($bookerEmail, $subjectBooker, $plainBooker, $htmlBooker, [$tmpFile], MailTemplate::STATUS_SUCCESS);

            $toAdmin = sanitize_email((string) ($meta['person_email'] ?? ''));
            if ($toAdmin) {
                Settings::sendMail($toAdmin, $subjectHost, $plainHost, $htmlHost, [$tmpFile], MailTemplate::STATUS_SUCCESS);
            }

            @unlink($tmpFile);

            $this->renderConfirmationPage();
        } catch (CustomException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * Renders the public confirmation form or the completed success page.
     */
    private function renderConfirmationPage(
        string $token = '',
        array $questions = [],
        array $submittedAnswers = [],
        string $formError = ''
    ): void
    {
        status_header(200);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $isQuestionForm = $token !== '' && !empty($questions);
        $isCancellation = false;
        $isWaitlistOptOut = false;
        $waitlistNotificationsEnabled = false;
        $waitlistOptInAction = '';
        $waitlistOptInNonce = '';
        $illustrationUrl = plugin()->getUrl('src/illustrations')
            . ($isQuestionForm ? 'financial-analyst-31.png' : 'order-confirmed-62.png');
        $formAction = $isQuestionForm ? TokenManager::confirmUrl($token) : '';
        $formNonce = $isQuestionForm
            ? wp_create_nonce('rrze_appointment_confirm_questions_' . $token)
            : '';

        require plugin()->getPath('templates') . 'confirmation-page.php';
        exit;
    }

    /**
     * Renders the public success page after a request or booking is cancelled.
     */
    private function renderCancellationPage(): void
    {
        status_header(200);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $isQuestionForm = false;
        $isCancellation = true;
        $isWaitlistOptOut = false;
        $waitlistNotificationsEnabled = false;
        $waitlistOptInAction = '';
        $waitlistOptInNonce = '';
        $illustrationUrl = plugin()->getUrl('src/illustrations') . 'neutral-face-89.png';
        $questions = [];
        $submittedAnswers = [];
        $formError = '';
        $formAction = '';
        $formNonce = '';

        require plugin()->getPath('templates') . 'confirmation-page.php';
        exit;
    }

    /**
     * Renders the public success page after earlier-slot notifications are disabled.
     */
    private function renderWaitlistOptOutPage(string $token, bool $notificationsEnabled = false): void
    {
        status_header(200);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $isQuestionForm = false;
        $isCancellation = false;
        $isWaitlistOptOut = true;
        $waitlistNotificationsEnabled = $notificationsEnabled;
        $waitlistOptInAction = TokenManager::waitlistOptOutUrl($token);
        $waitlistOptInNonce = wp_create_nonce('rrze_appointment_waitlist_optin_' . $token);
        $illustrationUrl = plugin()->getUrl('src/illustrations') . 'reminder-note-28.png';
        $questions = [];
        $submittedAnswers = [];
        $formError = '';
        $formAction = '';
        $formNonce = '';

        require plugin()->getPath('templates') . 'confirmation-page.php';
        exit;
    }

    /**
     * Renders public link and form errors without exposing the WordPress error UI.
     */
    private function renderErrorPage(string $message, int $statusCode = 410): void
    {
        status_header($statusCode);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $illustrationUrl = plugin()->getUrl('src/illustrations') . 'bug-fixing-71.png';
        $errorMessage = $message;

        require plugin()->getPath('templates') . 'error-page.php';
        exit;
    }

    public function handleCancel(): void
    {
        try {
            $token = sanitize_text_field($_GET['rrze_appt_cancel'] ?? '');
            if (!$token)
                return;

            $entry = TokenManager::validateCancelToken($token);
            if (!$entry) {
                $this->renderErrorPage(
                    __('This cancellation link is invalid or has already been used.', 'rrze-appointment'),
                    410
                );
            }

            if ($entry['type'] === 'pending') {
                TokenManager::deletePending((string) ($entry['pending_token'] ?? ''));
            } else {
                TokenManager::deleteCancelToken($token);
                Bookings::cancel($entry['slot']);
            }

            $this->renderCancellationPage();
        } catch (CustomException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 500]);
        }
    }

    public function handleWaitlistOptOut(): void
    {
        try {
            $rawToken = wp_unslash($_GET['rrze_appt_waitlist_optout'] ?? '');
            $token = is_string($rawToken) ? sanitize_text_field($rawToken) : '';
            if ($token === '') {
                return;
            }

            $slot = TokenManager::validateWaitlistOptOutToken($token);
            if ($slot === null) {
                $this->renderErrorPage(
                    __('This notification opt-out link is invalid or has expired.', 'rrze-appointment'),
                    410
                );
            }

            $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $rawAction = wp_unslash($_POST['rrze_appt_waitlist_action'] ?? '');
            $action = is_string($rawAction) ? sanitize_key($rawAction) : '';
            $isOptInRequest = $requestMethod === 'POST' && $action === 'optin';

            if ($isOptInRequest) {
                $rawNonce = wp_unslash($_POST['rrze_appt_waitlist_nonce'] ?? '');
                $nonce = is_string($rawNonce) ? sanitize_text_field($rawNonce) : '';
                if (!wp_verify_nonce($nonce, 'rrze_appointment_waitlist_optin_' . $token)) {
                    $this->renderErrorPage(
                        __('The form has expired. Please try again.', 'rrze-appointment'),
                        403
                    );
                }

                if (!Bookings::enableWaitlistNotifications($slot)) {
                    $this->renderErrorPage(
                        __('This notification opt-out link is invalid or has expired.', 'rrze-appointment'),
                        410
                    );
                }

                $this->renderWaitlistOptOutPage($token, true);
            }

            if (!Bookings::disableWaitlistNotifications($slot)) {
                $this->renderErrorPage(
                    __('This notification opt-out link is invalid or has expired.', 'rrze-appointment'),
                    410
                );
            }

            $this->renderWaitlistOptOutPage($token);
        } catch (CustomException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 500]);
        }
    }

    public function handlePostUpdated(int $postId, \WP_Post $postAfter, \WP_Post $postBefore): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId))
            return;
        if ($postAfter->post_status !== 'publish' || !has_blocks($postAfter->post_content))
            return;

        $today       = current_time('Y-m-d');
        $allMeta     = (array) get_option(Bookings::META_OPTION, []);
        $bookedSlots = (array) get_option(Bookings::SLOTS_OPTION, []);
        $bookedSet   = array_flip($bookedSlots);

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

        $previousSlotsByPerson = [];
        if ($postBefore->post_status === 'publish' && has_blocks($postBefore->post_content)) {
            $previousSlotsByPerson = $this->collectAppointmentSlots($postBefore->post_content);
        }

        $currentSlotsByPerson = $this->collectAppointmentSlots($postAfter->post_content);

        foreach ($waitlisted as $personId => $entries) {
            if (empty($currentSlotsByPerson[$personId]))
                continue;

            // Only slots introduced by this update became newly available.
            // Slots that were already free when a booking was made are present
            // in both snapshots and therefore never trigger a notification.
            $addedSlots = array_diff_key(
                $currentSlotsByPerson[$personId],
                $previousSlotsByPerson[$personId] ?? []
            );
            $addedSlots = array_filter(
                $addedSlots,
                fn($attrs, $slot) => !isset($bookedSet[$slot]) && explode(' ', $slot)[0] >= $today,
                ARRAY_FILTER_USE_BOTH
            );
            if (empty($addedSlots))
                continue;

            foreach ($entries as $entry) {
                $bookedSlot = $entry['slot'];
                $bookedMeta = $entry['meta'];

                // Find the single earliest newly available slot before the booking.
                $earlier = array_filter(
                    array_keys($addedSlots),
                    fn($slot) => $slot < $bookedSlot
                );
                if (empty($earlier))
                    continue;

                $earliest = (string) min($earlier);
                Bookings::sendWaitlistNotificationStatic(
                    $earliest,
                    $addedSlots[$earliest],
                    $bookedSlot,
                    $bookedMeta
                );
            }
        }
    }

    private function collectAppointmentSlots(string $postContent): array
    {
        $slotsByPerson = [];
        $this->collectAppointmentSlotsFromBlocks(parse_blocks($postContent), $slotsByPerson);
        return $slotsByPerson;
    }

    private function collectAppointmentSlotsFromBlocks(array $blocks, array &$slotsByPerson): void
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'rrze/appointment') {
                $attrs    = $block['attrs'] ?? [];
                $personId = (int) ($attrs['personId'] ?? 0);

                foreach (SlotGenerator::fromAttributes($attrs) as $slot) {
                    $slotsByPerson[$personId][$slot] = $attrs;
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collectAppointmentSlotsFromBlocks($block['innerBlocks'], $slotsByPerson);
            }
        }
    }

}
