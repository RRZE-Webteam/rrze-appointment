<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\Settings\Settings as CommonSettings;

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
class Main
{
    /**
     * Legacy defaults object retained for callers of settings().
     */
    public Defaults $defaults;

    /**
     * Legacy common settings builder retained for backwards compatibility.
     */
    public CommonSettings $settings;

    private AllowedHtml $allowedHtml;
    private AssetManager $assets;
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
        $this->allowedHtml = new AllowedHtml();
        $this->assets = new AssetManager();
        $this->bookingRequests = new BookingRequestController();
        $this->cancellations = new CancellationController($renderer);
        $this->confirmations = new ConfirmationController($renderer);
        $this->faudirPersons = new FaudirPersonProvider();
        $this->sso = new SsoController();
        $this->waitlistNotifier = new WaitlistNotifier();

        add_action('init', [MailTemplatePost::class, 'register'], 5);
        add_action('init', [$this, 'onInit']);
        add_filter('wp_kses_allowed_html', [$this->allowedHtml, 'filter'], 10, 2);
    }

    /**
     * Initializes plugin state and registers runtime WordPress hooks.
     */
    public function onInit(): void
    {
        $this->defaults = new Defaults();
        MailTemplatePost::ensureEditableDefaultTemplateExists();
        TokenManager::cleanupPendingState();

        (new Settings())->register();
        (new Reminder())->register();

        add_action('wp_enqueue_scripts', [$this->assets, 'enqueueFrontendAssets']);
        add_action('enqueue_block_assets', [$this->assets, 'enqueueFrontendAssets']);
        add_action('enqueue_block_editor_assets', [$this->assets, 'enqueueEditorAssets']);
        add_action('wp_ajax_rrze_appointment_book', [$this->bookingRequests, 'handleRequest']);
        add_action('wp_ajax_nopriv_rrze_appointment_book', [$this->bookingRequests, 'handleRequest']);
        add_action('wp_ajax_rrze_appointment_get_booker', [$this->sso, 'handleBookerRequest']);
        add_action('wp_ajax_nopriv_rrze_appointment_get_booker', [$this->sso, 'handleBookerRequest']);
        add_action('template_redirect', [$this->sso, 'handleLogin']);
        add_action('template_redirect', [$this->confirmations, 'handleConfirmation']);
        add_action('template_redirect', [$this->cancellations, 'handleCancellation']);
        add_action('template_redirect', [$this->cancellations, 'handleWaitlistPreference']);
        add_action(TokenManager::PENDING_EXPIRY_HOOK, [TokenManager::class, 'expirePending']);
        add_action('post_updated', [$this->waitlistNotifier, 'handlePostUpdated'], 10, 3);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    /**
     * Registers REST endpoints used by the public dialog and block editor.
     */
    public function registerRestRoutes(): void
    {
        register_rest_route('rrze/v2/appointment', '/booker', [
            'methods' => 'POST',
            'callback' => [$this->sso, 'handleBookerRequest'],
            'permission_callback' => [$this, 'allowBookerRequest'],
        ]);
        register_rest_route('rrze/v2/appointment', '/persons', [
            'methods' => 'GET',
            'callback' => [$this->faudirPersons, 'handleRequest'],
            'permission_callback' => static function (): bool {
                return current_user_can('edit_posts');
            },
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
     * Builds the legacy common settings screen from Defaults configuration.
     *
     * The current Settings service is registered from onInit(); this method is
     * retained for compatibility with integrations that still invoke it.
     */
    public function settings(): void
    {
        $this->settings = new CommonSettings($this->defaults->get('settings')['page_title']);
        $this->settings
            ->setCapability($this->defaults->get('settings')['capability'])
            ->setOptionName($this->defaults->get('settings')['option_name'])
            ->setMenuTitle($this->defaults->get('settings')['menu_title'])
            ->setMenuPosition(6)
            ->setMenuParentSlug('options-general.php');

        foreach ($this->defaults->get('sections') as $section) {
            $tab = $this->settings->addTab(
                __($section['title'], 'rrze-appointment'),
                $section['id']
            );
            $settingsSection = $tab->addSection(
                __($section['title'], 'rrze-appointment'),
                $section['id']
            );

            foreach ($this->defaults->get('fields')[$section['id']] as $field) {
                $settingsSection->addOption(
                    $field['type'],
                    array_intersect_key(
                        $field,
                        array_flip([
                            'name',
                            'label',
                            'description',
                            'options',
                            'default',
                            'sanitize',
                            'validate',
                            'placeholder',
                        ])
                    )
                );
            }
        }

        $this->settings->build();
    }
}
