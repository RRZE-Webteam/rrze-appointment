<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Common\Settings\Settings as CommonSettings;
use RRZE\Appointment\Controller\BookingOpeningController;
use RRZE\Appointment\Controller\BookingRequestController;
use RRZE\Appointment\Controller\CancellationController;
use RRZE\Appointment\Controller\ConfirmationController;
use RRZE\Appointment\Controller\SsoController;
use RRZE\Appointment\Notification\BookingOpeningNotifier;
use RRZE\Appointment\Notification\Reminder;
use RRZE\Appointment\Notification\WaitlistNotifier;
use RRZE\Appointment\Mail\MailTemplatePost;
use RRZE\Appointment\Presentation\AssetManager;
use RRZE\Appointment\Presentation\PublicPageRenderer;

defined('ABSPATH') || exit;

/**
 * Boots the plugin and connects focused services to WordPress hooks.
 *
 * Business workflows live in dedicated controllers. This class deliberately
 * contains only lifecycle setup, route registration, and legacy settings
 * initialization.
 *
 * @package RRZE\Appointment
 * @since 1.0.0
 */
final class Main
{
    private const REST_NAMESPACE = 'rrze/v2/appointment';
    private const BOOKER_ROUTE = '/booker';
    private const PERSONS_ROUTE = '/persons';
    private const BOOKING_AJAX_ACTION = 'rrze_appointment_book';
    private const OPENING_AJAX_ACTION = 'rrze_appointment_notify_opening';
    private const BOOKER_AJAX_ACTION = 'rrze_appointment_get_booker';
    private const LEGACY_FIELD_KEYS = [
        'name',
        'label',
        'description',
        'options',
        'default',
        'sanitize',
        'validate',
        'placeholder',
    ];

    /**
     * Legacy defaults object retained for callers of settings().
     */
    public Defaults $defaults;

    /**
     * Legacy common settings builder retained for backwards compatibility.
     */
    public CommonSettings $settings;

    private AssetManager $assets;
    private BookingOpeningController $bookingOpenings;
    private BookingRequestController $bookingRequests;
    private CancellationController $cancellations;
    private ConfirmationController $confirmations;
    private FaudirPersonProvider $faudirPersons;
    private SsoController $sso;
    private WaitlistNotifier $waitlistNotifier;

    /**
     * Creates plugin services and registers hooks needed before init.
     */
    public function __construct()
    {
        $renderer = new PublicPageRenderer();
        $this->assets = new AssetManager();
        $this->bookingOpenings = new BookingOpeningController($renderer);
        $this->bookingRequests = new BookingRequestController();
        $this->cancellations = new CancellationController($renderer);
        $this->confirmations = new ConfirmationController($renderer);
        $this->faudirPersons = new FaudirPersonProvider();
        $this->sso = new SsoController();
        $this->waitlistNotifier = new WaitlistNotifier();

        add_action('init', [MailTemplatePost::class, 'register'], 5);
        add_action('init', [$this, 'onInit']);
    }

    /**
     * Initializes plugin state and registers runtime WordPress hooks.
     */
    public function onInit(): void
    {
        $this->defaults = new Defaults();
        $this->initializePluginState();

        (new Settings())->register();
        (new Reminder())->register();

        $this->registerAssetHooks();
        $this->registerAjaxHooks();
        $this->registerPublicPageHooks();
        $this->registerBackgroundHooks();
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    /**
     * Runs one-time maintenance needed before request hooks execute.
     */
    private function initializePluginState(): void
    {
        MailTemplatePost::ensureEditableDefaultTemplateExists();
        TokenManager::cleanupPendingState();
        BookingOpeningNotifier::cleanup();
    }

    /**
     * Registers public and editor asset hooks.
     */
    private function registerAssetHooks(): void
    {
        add_action('wp_enqueue_scripts', [$this->assets, 'enqueueFrontendAssets']);
        add_action('enqueue_block_assets', [$this->assets, 'enqueueFrontendAssets']);
        add_action('enqueue_block_editor_assets', [$this->assets, 'enqueueEditorAssets']);
    }

    /**
     * Registers authenticated and anonymous AJAX endpoints.
     */
    private function registerAjaxHooks(): void
    {
        $this->addPublicAjaxAction(
            self::BOOKING_AJAX_ACTION,
            [$this->bookingRequests, 'handleRequest']
        );
        $this->addPublicAjaxAction(
            self::OPENING_AJAX_ACTION,
            [$this->bookingOpenings, 'handleSubscription']
        );
        $this->addPublicAjaxAction(
            self::BOOKER_AJAX_ACTION,
            [$this->sso, 'handleBookerRequest']
        );
    }

    /**
     * Registers an AJAX callback for logged-in and anonymous visitors.
     *
     * @param callable $callback WordPress AJAX callback.
     */
    private function addPublicAjaxAction(string $action, callable $callback): void
    {
        add_action('wp_ajax_' . $action, $callback);
        add_action('wp_ajax_nopriv_' . $action, $callback);
    }

    /**
     * Registers controllers that handle public page links.
     */
    private function registerPublicPageHooks(): void
    {
        add_action('template_redirect', [$this->sso, 'handleLogin']);
        add_action('template_redirect', [$this->confirmations, 'handleConfirmation']);
        add_action('template_redirect', [$this->bookingOpenings, 'handleClaim']);
        add_action('template_redirect', [$this->bookingOpenings, 'handleRegistrationStatus']);
        add_action('template_redirect', [$this->cancellations, 'handleCancellation']);
        add_action('template_redirect', [$this->cancellations, 'handleWaitlistPreference']);
    }

    /**
     * Registers cron and content-change callbacks.
     */
    private function registerBackgroundHooks(): void
    {
        add_action(TokenManager::PENDING_EXPIRY_HOOK, [TokenManager::class, 'expirePending']);
        add_action(BookingOpeningNotifier::CRON_HOOK, [BookingOpeningNotifier::class, 'notify']);
        add_action('post_updated', [$this->waitlistNotifier, 'handlePostUpdated'], 10, 3);
    }

    /**
     * Registers REST endpoints used by the public dialog and block editor.
     */
    public function registerRestRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::BOOKER_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this->sso, 'handleBookerRequest'],
            'permission_callback' => [$this, 'allowBookerRequest'],
        ]);
        register_rest_route(self::REST_NAMESPACE, self::PERSONS_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this->faudirPersons, 'handleRequest'],
            'permission_callback' => [$this, 'allowPersonsRequest'],
        ]);
    }

    /**
     * Allows public access to the passive booker identity endpoint.
     *
     * Authentication is intentionally resolved by SsoController so the REST
     * endpoint can return the login URL to anonymous visitors.
     *
     * @param mixed $request REST request supplied by WordPress.
     */
    public function allowBookerRequest($request): bool
    {
        return true;
    }

    /**
     * Restricts the FAUdir person endpoint to users who can edit content.
     *
     * @param mixed $request REST request supplied by WordPress.
     */
    public function allowPersonsRequest($request): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Builds the legacy common settings screen from Defaults configuration.
     *
     * The current Settings service is registered from onInit(); this method is
     * retained for compatibility with integrations that still invoke it.
     */
    public function settings(): void
    {
        $settings = $this->defaults->get('settings');
        $sections = $this->defaults->get('sections');
        $fields = $this->defaults->get('fields');
        if (!is_array($settings) || !is_array($sections) || !is_array($fields)) {
            return;
        }

        $this->settings = new CommonSettings((string) ($settings['page_title'] ?? ''));
        $this->settings
            ->setCapability((string) ($settings['capability'] ?? 'manage_options'))
            ->setOptionName((string) ($settings['option_name'] ?? ''))
            ->setMenuTitle((string) ($settings['menu_title'] ?? ''))
            ->setMenuPosition(6)
            ->setMenuParentSlug('options-general.php');

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $sectionId = sanitize_key((string) ($section['id'] ?? ''));
            $sectionTitle = (string) ($section['title'] ?? '');
            if ($sectionId === '' || $sectionTitle === '') {
                continue;
            }

            $tab = $this->settings->addTab(
                __($sectionTitle, 'rrze-appointment'),
                $sectionId
            );
            $settingsSection = $tab->addSection(
                __($sectionTitle, 'rrze-appointment'),
                $sectionId
            );

            $sectionFields = is_array($fields[$sectionId] ?? null) ? $fields[$sectionId] : [];
            foreach ($sectionFields as $field) {
                if (!is_array($field) || !is_string($field['type'] ?? null)) {
                    continue;
                }

                $settingsSection->addOption(
                    $field['type'],
                    array_intersect_key($field, array_flip(self::LEGACY_FIELD_KEYS))
                );
            }
        }

        $this->settings->build();
    }
}
