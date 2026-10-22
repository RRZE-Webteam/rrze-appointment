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
    final class SettingsPage { public const PAGE_SLUG = 'rrze-appointment-settings'; }
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
    $GLOBALS['hooks'] = [];
    /** Records registered hooks so obsolete manual migration endpoints are detected. */
    function add_action($hook, $callback): void { $GLOBALS['hooks'][] = $hook; }

    /** Models only site administrator capability, not a logged-in user alone. */
    function current_user_can($capability): bool { return $capability === 'manage_options' && $GLOBALS['input']['admin']; }
    /** Preserves source text for locale-independent test assertions. */
    function __($message, $domain): string { return $message; }
    /** Escapes HTML text in the rendered status notice. */
    function esc_html($value): string { return htmlspecialchars($value, ENT_QUOTES); }
    /** Escapes HTML attribute values. */
    function esc_attr($value): string { return esc_html($value); }
    require dirname(__DIR__, 2) . '/includes/Admin/StorageStatus.php';
    $status = new \RRZE\Appointment\Admin\StorageStatus($GLOBALS['input']['initializationError'] ?? null);
    $status->register();
    ob_start();
    $status->render();
    echo json_encode(['html' => ob_get_clean(), 'migrations' => $GLOBALS['migrations'], 'hooks' => $GLOBALS['hooks']]);
}
