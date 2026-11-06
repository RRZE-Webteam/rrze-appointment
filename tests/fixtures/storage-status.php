<?php

namespace RRZE\Appointment {
    /** Shared exception type for this isolated administration boundary test. */
    class AppointmentException extends \Exception {}
}
namespace RRZE\Appointment\Storage {
    /** Detects unintended migration side effects while rendering diagnostics. */
    final class BookingStorage
    {
        /** Returns the requested synthetic status or a sanitized storage error. */
        public static function status(): array
        {
            $GLOBALS['reads']++;
            if ($GLOBALS['input']['phase'] === 'error') {
                throw new \RRZE\Appointment\AppointmentException('Safe storage error.');
            }
            return ['phase' => $GLOBALS['input']['phase'], 'completed' => 2, 'total' => 5];
        }
        /** Records any unintended migration invocation from the status UI. */
        public static function migrate(): void { $GLOBALS['migrations']++; }
    }
}
namespace RRZE\Appointment\Admin {
    /** Supplies only the real settings slug needed by the status notice. */
    final class SettingsPage {
        public const PAGE_SLUG = 'rrze-appointment-settings';
        /** Returns the registered settings screen. */
        public static function screenHook(): string { return 'settings_page_' . self::PAGE_SLUG; }
    }
    /** Supplies the registered booking-management screen. */
    final class BookingsPage {
        /** Returns the registered management screen. */
        public static function screenHook(): string { return 'toplevel_page_rrze-appointment-bookings'; }
    }
    /** Models the current administration page without HTTP request parsing. */
    final class Request
    {
        /** Returns the chosen synthetic page. */
        public static function queryText(string $name, bool $key): string { return $GLOBALS['input']['page']; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('ABSPATH', __DIR__);
    $GLOBALS['input'] = json_decode($argv[1], true);
    $GLOBALS['migrations'] = 0;
    $GLOBALS['reads'] = 0;
    $GLOBALS['hooks'] = [];
    /** Records registered hooks so obsolete manual migration endpoints are detected. */
    function add_action($hook, $callback): void { $GLOBALS['hooks'][] = $hook; }

    /** Models only site administrator capability, not a logged-in user alone. */
    function current_user_can($capability): bool { return $capability === 'manage_options' && $GLOBALS['input']['admin']; }
    /** Models multisite separately from site administrator privileges. */
    function is_multisite(): bool { return $GLOBALS['input']['multisite'] ?? false; }
    /** Models explicit superadministrator membership. */
    function is_super_admin(): bool { return $GLOBALS['input']['superAdmin'] ?? false; }
    /** Supplies the real screen identity independently of spoofed query parameters. */
    function get_current_screen(): ?object {
        $screen = $GLOBALS['input']['screen'] ?? $GLOBALS['input']['page'];
        $screen = match ($screen) {
            'rrze-appointment-settings' => 'settings_page_rrze-appointment-settings',
            'rrze-appointment-bookings' => 'toplevel_page_rrze-appointment-bookings',
            default => $screen,
        };
        return $screen === '' ? null : (object) ['id' => $screen];
    }
    /** Preserves source text for locale-independent test assertions. */
    function __($message, $domain): string { return $message; }
    /** Escapes HTML text in the rendered status notice. */
    function esc_html($value): string { return htmlspecialchars($value, ENT_QUOTES); }
    /** Escapes HTML attribute values. */
    function esc_attr($value): string { return esc_html($value); }
    require dirname(__DIR__, 2) . '/includes/Admin/DiagnosticNotice.php';
    require dirname(__DIR__, 2) . '/includes/Admin/StorageStatus.php';
    $status = new \RRZE\Appointment\Admin\StorageStatus($GLOBALS['input']['initializationError'] ?? null);
    $status->register();
    ob_start();
    $status->render();
    echo json_encode(['html' => ob_get_clean(), 'migrations' => $GLOBALS['migrations'], 'reads' => $GLOBALS['reads'], 'hooks' => $GLOBALS['hooks']]);
}
