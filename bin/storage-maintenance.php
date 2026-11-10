<?php

/**
 * Read-only storage inspection entrypoint, loaded without the normal plugin bootstrap.
 *
 * Usage: wp --skip-plugins --skip-themes eval-file /absolute/path/bin/storage-maintenance.php preflight network
 * Modes: preflight, verify-mixed, verify. Omit "network" for the --url-selected site.
 */

use RRZE\Appointment\Storage\StorageMaintenance;

if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    http_response_code(403);
    exit;
}
if (class_exists(\RRZE\Appointment\Main::class, false)) {
    WP_CLI::error('Run appointment maintenance with --skip-plugins --skip-themes.');
}
if (count($args) < 1 || count($args) > 2 || (isset($args[1]) && $args[1] !== 'network')) {
    WP_CLI::error('Usage: storage-maintenance.php <preflight|verify-mixed|verify> [network]');
}

/** Loads only Appointment classes requested by maintenance, without registering runtime hooks. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\Appointment\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

if ($args[0] === 'migrate') {
    WP_CLI::error('Bulk migration is disabled in mixed mode. New and changed records are encrypted automatically.');
}

try {
    /** Emits operational metadata only; never exception details, keys, payloads or action URLs. */
    $report = static function (array $result): void {
        WP_CLI::line(json_encode($result, JSON_THROW_ON_ERROR));
    };
    $success = StorageMaintenance::run($args[0], isset($args[1]), $report);
    if (!$success) {
        WP_CLI::error('Appointment storage checks failed. Inspect the reported sites.');
    }
    WP_CLI::success('Appointment maintenance checks completed.');
} catch (Throwable) {
    WP_CLI::error('Appointment maintenance is unavailable. Check the mode, scope, key, database and migration state.');
}
