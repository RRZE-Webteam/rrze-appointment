<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\Controller\AdminBookingsController;
use function RRZE\Appointment\plugin;

defined('ABSPATH') || exit;

/**
 * Handles appointment administration and host-initiated cancellations.
 */
final class BookingsPage
{
    public const PAGE_SLUG = 'rrze-appointment-bookings';
    private const ADMIN_MENU_ICON_PATH = 'assets/svg/approval_delegation_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg';

    /** Registers the management menu and its assets. */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('load-' . self::screenHook(), [$this, 'prepareResponse']);
    }

    /**
     * Registers the appointment page with a distinct, recognizable menu label.
     */
    public function addMenuPage(): void
    {
        add_menu_page(
            __('Appointments', 'rrze-appointment'),
            _x('Appointments', 'Admin menu label', 'rrze-appointment'),
            'read',
            self::PAGE_SLUG,
            [$this, 'render'],
            self::getAdminMenuIcon(),
            30
        );
        if (!AppointmentPermissions::currentUserCanManage()) {
            remove_menu_page(self::PAGE_SLUG);
        }
    }

    /** Returns the WordPress screen identifier. */
    public static function screenHook(): string
    {
        return 'toplevel_page_' . self::PAGE_SLUG;
    }

    /** Loads the bundled menu icon, with a WordPress fallback. */
    private static function getAdminMenuIcon(): string
    {
        $iconPath = plugin()->getPath() . self::ADMIN_MENU_ICON_PATH;
        if (!is_readable($iconPath)) {
            return 'dashicons-calendar-alt';
        }

        $svg = file_get_contents($iconPath);
        if (!is_string($svg) || $svg === '') {
            return 'dashicons-calendar-alt';
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** Loads the React application only for authorized appointment managers. */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== self::screenHook() || !AppointmentPermissions::currentUserCanManage()
            || is_wp_error(AppointmentPermissions::dataAccess()) || self::accessTab()) {
            return;
        }
        $assetPath = plugin()->getPath() . 'build/admin-bookings.asset.php';
        if (!is_readable($assetPath)) {
            return;
        }
        $asset = require $assetPath;
        wp_enqueue_script(
            'rrze-appointment-admin-bookings',
            plugin()->getUrl() . 'build/admin-bookings.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
        wp_enqueue_style(
            'rrze-appointment-admin-bookings',
            plugin()->getUrl() . 'build/admin-bookings.css',
            ['wp-components'],
            $asset['version']
        );
        wp_style_add_data('rrze-appointment-admin-bookings', 'rtl', 'replace');
        wp_set_script_translations(
            'rrze-appointment-admin-bookings',
            'rrze-appointment',
            plugin()->getPath() . 'languages'
        );
        wp_add_inline_script(
            'rrze-appointment-admin-bookings',
            'window.rrzeAppointmentAdmin = ' . wp_json_encode([
                'url' => rest_url(AdminBookingsController::REST_NAMESPACE . AdminBookingsController::ROUTE),
                'cancelUrl' => rest_url(AdminBookingsController::REST_NAMESPACE . AdminBookingsController::ROUTE . '/cancel'),
                'nonce' => wp_create_nonce('wp_rest'),
                'loginUrl' => BackendSsoSettings::loginUrl(),
                'dashboardUrl' => admin_url(),
                'accessMessages' => AccessNotice::messages(),
            ]) . ';',
            'before'
        );
    }

    /** Identifies the independent permissions tab without coercing malformed request values. */
    private static function accessTab(): bool
    {
        return ($_GET['tab'] ?? '') === 'access';
    }

    /** Sets the denial status before WordPress emits the admin header. */
    public function prepareResponse(): void
    {
        $access = self::accessTab() ? AppointmentPermissions::grantsAccess() : AppointmentPermissions::dataAccess();
        if (is_wp_error($access)) {
            status_header($access->get_error_data()['status'] ?? 403);
        }
    }

    /** Renders a useful denial, the owner-only forms, or the authorized booking application. */
    public function render(): void
    {
        $access = AppointmentPermissions::dataAccess();
        if (is_wp_error($access)) {
            echo '<div class="wrap"><h1>' . esc_html__('Appointments', 'rrze-appointment') . '</h1>';
            AccessNotice::render($access);
            echo '</div>';
            return;
        }
        if (AccessPolicy::owner()) {
            echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Appointment management', 'rrze-appointment') . '">';
            echo '<a class="nav-tab' . (!self::accessTab() ? ' nav-tab-active' : '') . '" href="' . esc_url(BackendSsoSettings::destination('bookings')) . '">' . esc_html__('Appointments', 'rrze-appointment') . '</a>';
            echo '<a class="nav-tab' . (self::accessTab() ? ' nav-tab-active' : '') . '" href="' . esc_url(AccessSettings::url()) . '">' . esc_html__('Manage access', 'rrze-appointment') . '</a></nav>';
        }
        if (self::accessTab()) {
            echo '<div class="wrap"><h1>' . esc_html__('Manage access', 'rrze-appointment') . '</h1>';
            AccessSettings::renderManagement();
            echo '</div>';
            return;
        }
        ?>
        <div class="wrap rrze-appointment-settings-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Appointments', 'rrze-appointment'); ?></h1>
            <hr class="wp-header-end">
            <div id="rrze-appointment-admin" class="rrze-appointment-admin">
                <p role="status"><?php esc_html_e('Loading appointments…', 'rrze-appointment'); ?></p>
            </div>
            <noscript>
                <p><?php esc_html_e('Enable JavaScript to manage appointments.', 'rrze-appointment'); ?></p>
            </noscript>
        </div>
        <?php
    }
}
