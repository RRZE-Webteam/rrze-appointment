<?php
/** Verifies that bootstrap retires the old event without starting any network inventory. */

defined('ABSPATH') || define('ABSPATH', __DIR__);
if (PHP_SAPI !== 'cli') {
    exit;
}

use RRZE\Appointment\Storage\NetworkMigration;

/** Selects the requested WordPress installation type. */
function is_multisite(): bool
{
    return $GLOBALS['argv'][1] === 'network';
}

/** Records the only allowed registration; database and scheduling APIs are intentionally absent. */
function add_filter(string $name, callable $callback, int $priority, int $arguments): void
{
    $GLOBALS['registered'][] = $name;
}

require dirname(__DIR__, 2) . '/includes/Storage/NetworkMigration.php';
NetworkMigration::register();
echo json_encode([
    'registered' => $GLOBALS['registered'] ?? [],
    'retired' => NetworkMigration::retireEvent(null, (object) ['hook' => NetworkMigration::HOOK]),
    'foreign' => NetworkMigration::retireEvent(null, (object) ['hook' => 'foreign_hook']),
    'foreignDecision' => NetworkMigration::retireEvent(false, (object) ['hook' => 'foreign_hook']),
], JSON_THROW_ON_ERROR);
