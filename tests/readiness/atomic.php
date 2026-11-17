<?php

namespace RRZE\Appointment\Storage {
    /** Models an unacknowledged commit after a real server commit, without changing production code. */
    function mysqli_query(#[\SensitiveParameter] \mysqli $connection, #[\SensitiveParameter] string $query): \mysqli_result|bool
    {
        if ($query === 'COMMIT' && !empty($GLOBALS['atomicCrashBeforeCommit'])) {
            exit(77);
        }
        $result = \mysqli_query($connection, $query);
        if ($query === 'COMMIT' && !empty($GLOBALS['atomicLostAcknowledgement'])) {
            $GLOBALS['atomicLostAcknowledgement'] = false;
            throw new \mysqli_sql_exception('Synthetic missing commit acknowledgement');
        }
        return $result;
    }
}
namespace {

/** Real InnoDB failure tests, restricted to the disposable readiness database. */

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Storage\BookingStorage;
use RRZE\Appointment\Storage\MixedRecordStore;

if (!defined('WP_CLI') || !WP_CLI || !defined('RRZE_APPOINTMENT_AUDIT_ROOT')
    || !str_starts_with(DB_NAME, 'rrze_appt_audit_') || $GLOBALS['wpdb']->get_var('SELECT DATABASE()') !== DB_NAME
    || wp_using_ext_object_cache()) {
    fwrite(STDERR, "This fixture requires the isolated audit database and request-local cache.\n");
    exit(1);
}

/** Loads the candidate installed in the isolated WordPress tree without starting its runtime. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\Appointment\\';
    if (str_starts_with($class, $prefix)) {
        $file = WP_PLUGIN_DIR . '/rrze-appointment/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

/** Reports only assertion labels, never synthetic tokens or payloads. */
function atomicAssert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['atomicChecks'][$label] = true;
}

/** Reads exact raw storage for rollback and no-write assertions. */
function atomicRows(): array
{
    global $wpdb;
    atomicAssert($wpdb->options === 'rrze_appt_atomic_test', 'isolated-table');
    return $wpdb->get_results('SELECT option_name, option_value, autoload FROM rrze_appt_atomic_test ORDER BY option_name', ARRAY_A);
}

/** Creates a fresh legacy reservation, then initializes the selected storage mode. */
function atomicSeed(bool $managed = false): void
{
    global $wpdb;
    BookingStorage::release();
    atomicAssert($wpdb->options === 'rrze_appt_atomic_test', 'isolated-table');
    wp_cache_flush();
    $wpdb->query('TRUNCATE TABLE rrze_appt_atomic_test');
    $values = array_fill_keys(BookingStorage::OPTIONS, []);
    $values[TokenManager::PENDING_OPTION] = [
        'synthetic-old-confirm' => [
            'slot' => '2099-11-06 10:00-10:30',
            'expires' => time() + 1800,
            'meta' => ['booker_name' => 'Synthetic person', 'booker_email' => 'synthetic@example.test'],
            'cancel_token' => 'synthetic-old-cancel',
        ],
    ];
    $values[TokenManager::CANCEL_OPTION] = [
        'synthetic-old-cancel' => ['type' => 'pending', 'pending_token' => 'synthetic-old-confirm', 'expires' => time() + 1800],
    ];
    foreach ($values as $name => $value) {
        $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => serialize($value), 'autoload' => 'no']);
    }
    BookingStorage::initialize();
    if ($managed) {
        $pending = BookingStorage::get(TokenManager::PENDING_OPTION);
        $pending['synthetic-old-confirm']['meta']['booker_name'] = 'Updated synthetic person';
        BookingStorage::set(TokenManager::PENDING_OPTION, $pending);
        atomicAssert(BookingStorage::recordId('pending', 'synthetic-old-confirm') !== null, 'managed-reservation-seeded');
    }
}

/** Completes the synthetic reservation through the real domain/storage methods. */
function atomicConfirm(): ?array
{
    return TokenManager::completePending('synthetic-old-confirm', ['booker_name' => 'Synthetic person', 'booker_email' => 'synthetic@example.test']);
}

/** Installs a server-side fault, which also intercepts native mysqli writes. */
function atomicTrigger(string $body, string $event = 'UPDATE'): void
{
    global $wpdb;
    if (!in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true) || $wpdb->options !== 'rrze_appt_atomic_test') {
        throw new RuntimeException('invalid-synthetic-trigger');
    }
    atomicAssert($wpdb->query("CREATE TRIGGER rrze_atomic_fault BEFORE $event ON rrze_appt_atomic_test FOR EACH ROW BEGIN $body END") !== false, 'fault-installed');
}

global $wpdb;
wp_cache_flush();
$GLOBALS['atomicChecks'] = [];
$originalTable = $wpdb->options;
$table = 'rrze_appt_atomic_test';
if (($args[0] ?? '') === 'crash-worker') {
    $wpdb->options = $table;
    $GLOBALS['atomicCrashBeforeCommit'] = true;
    atomicConfirm();
    exit(1);
}
$wpdb->query("DROP TABLE IF EXISTS `$table`");
$wpdb->query("CREATE TABLE `$table` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'no') ENGINE=InnoDB");
$wpdb->options = $table;
try {
    $mixed = defined('RRZE_APPOINTMENT_ENCRYPTION_KEY');
    $targets = $mixed ? [
        ['insert-policy', 'INSERT', 'NEW.option_name = %s', BookingStorage::MIXED_STATE_OPTION, false],
        ['insert-index', 'INSERT', 'NEW.option_name = %s', MixedRecordStore::INDEX, false],
        ['insert-booking', 'INSERT', 'LEFT(NEW.option_name, ' . strlen('rrze_appointment_v2_record_booking_') . ') = %s', 'rrze_appointment_v2_record_booking_', false],
        ['remove-source-pending', 'UPDATE', 'NEW.option_name = %s', TokenManager::PENDING_OPTION, false],
        ['remove-source-cancel', 'UPDATE', 'NEW.option_name = %s', TokenManager::CANCEL_OPTION, false],
        ['update-index', 'UPDATE', 'NEW.option_name = %s', MixedRecordStore::INDEX, true],
        ['delete-pending-record', 'DELETE', 'LEFT(OLD.option_name, ' . strlen('rrze_appointment_v2_record_pending_') . ') = %s', 'rrze_appointment_v2_record_pending_', true],
    ] : array_map(static fn(string $name): array => [$name, 'UPDATE', 'NEW.option_name = %s', $name, false],
        [Bookings::SLOTS_OPTION, Bookings::META_OPTION, TokenManager::CANCEL_OPTION, TokenManager::PENDING_OPTION]);
    foreach ($targets as [$label, $event, $predicate, $name, $managed]) {
        atomicSeed($managed);
        $before = atomicRows();
        $beforeId = BookingStorage::recordId('pending', 'synthetic-old-confirm');
        $wpdb->query('SET @rrze_atomic_fault = 0');
        $condition = $wpdb->prepare($predicate, $name);
        atomicTrigger("IF $condition THEN SET @rrze_atomic_fault = 1; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic failure'; END IF;", $event);
        $failed = false;
        try {
            atomicConfirm();
        } catch (Throwable) {
            $failed = true;
        }
        atomicAssert($failed && $wpdb->get_var('SELECT @rrze_atomic_fault') === '1', 'write-failure-reached-' . $label);
        atomicAssert(atomicRows() === $before, 'rollback-' . $label);
        $wpdb->query('DROP TRIGGER rrze_atomic_fault');
        BookingStorage::release();
        atomicAssert(TokenManager::getPending('synthetic-old-confirm') !== null, 'old-token-retained');
        $entry = atomicConfirm();
        atomicAssert($entry !== null && count(BookingStorage::get(Bookings::SLOTS_OPTION)) === 1, 'retry-confirms-once');
        atomicAssert(TokenManager::validateCancelToken($entry['meta']['cancel_token']) !== null, 'new-cancel-token-valid');
        atomicAssert(atomicConfirm() === null, 'repeat-token-rejected');
        if ($beforeId !== null) {
            atomicAssert(BookingStorage::recordId('booking', $entry['slot']) === $beforeId, 'immutable-id-through-retry');
        }
        atomicAssert(!isset(BookingStorage::get(TokenManager::CANCEL_OPTION)['synthetic-old-cancel']), 'pending-cancel-token-removed');
    }

    atomicSeed();
    $before = atomicRows();
    BookingStorage::release();
    $manifest = json_decode(file_get_contents(RRZE_APPOINTMENT_AUDIT_ROOT . '/manifest.json'), true);
    $worker = proc_open([PHP_BINARY, $manifest['wp'], '--path=' . $manifest['wordpress'], '--skip-plugins', '--skip-themes', 'eval-file', __FILE__, 'crash-worker'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    atomicAssert(proc_close($worker) === 77 && atomicRows() === $before, 'process-exit-before-commit-rolls-back');
    atomicAssert(atomicConfirm() !== null, 'retry-after-process-exit');

    atomicSeed();
    $GLOBALS['atomicLostAcknowledgement'] = true;
    $failed = false;
    try {
        atomicConfirm();
    } catch (Throwable) {
        $failed = true;
    }
    BookingStorage::release();
    $slots = BookingStorage::get(Bookings::SLOTS_OPTION);
    $metadata = BookingStorage::get(Bookings::META_OPTION);
    $pending = BookingStorage::get(TokenManager::PENDING_OPTION);
    $tokens = BookingStorage::get(TokenManager::CANCEL_OPTION);
    $slot = '2099-11-06 10:00-10:30';
    atomicAssert($failed && $slots === [$slot] && isset($tokens[$metadata[$slot]['cancel_token']])
        && !isset($pending['synthetic-old-confirm']), 'uncertain-commit-is-complete');
    atomicAssert(atomicConfirm() === null, 'uncertain-commit-retry-cannot-duplicate');

    atomicSeed();
    $before = atomicRows();
    $wpdb->query('START TRANSACTION');
    $failed = false;
    try {
        atomicConfirm();
    } catch (Throwable) {
        $failed = true;
    }
    atomicAssert($failed && atomicRows() === $before, 'ambient-transaction-rejected');
    $wpdb->query('ROLLBACK');

    atomicSeed();
    $before = atomicRows();
    BookingStorage::release();
    $wpdb->query("ALTER TABLE `$table` ENGINE=MyISAM");
    $failed = false;
    try {
        atomicConfirm();
    } catch (Throwable) {
        $failed = true;
    }
    atomicAssert($failed && atomicRows() === $before, 'myisam-rejected-before-write');
    BookingStorage::release();
    $wpdb->query("ALTER TABLE `$table` ENGINE=InnoDB");

    atomicSeed();
    $before = atomicRows();
    $connection = $wpdb->dbh;
    $connectionId = mysqli_thread_id($connection);
    // The trigger signals entry through an advisory lock, then pauses before a later write.
    $barrier = 'rrze_atomic_barrier_' . $connectionId;
    atomicTrigger($wpdb->prepare("IF NEW.option_name = %s THEN SET @rrze_atomic_wait = GET_LOCK(%s, 0); DO SLEEP(5); END IF;", TokenManager::PENDING_OPTION, $barrier));
    $script = <<<'PHP'
<?php
/** Kills only the explicitly supplied synthetic database connection after its barrier. */
$config = json_decode(stream_get_contents(STDIN), true);
$db = new mysqli('localhost', 'root', '', $config['database'], 0, $config['socket']);
for ($attempt = 0; $attempt < 200; $attempt++) {
    $row = $db->query("SELECT IS_USED_LOCK('" . $db->real_escape_string($config['barrier']) . "')")->fetch_row();
    if ((int) $row[0] === $config['connection']) {
        $db->query('KILL CONNECTION ' . (int) $config['connection']);
        exit(0);
    }
    usleep(25000);
}
exit(1);
PHP;
    $helper = RRZE_APPOINTMENT_AUDIT_ROOT . '/atomic-kill.php';
    file_put_contents($helper, $script);
    $worker = proc_open([PHP_BINARY, $helper], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['database' => DB_NAME, 'socket' => RRZE_APPOINTMENT_AUDIT_ROOT . '/mysql.sock', 'barrier' => $barrier, 'connection' => $connectionId]));
    fclose($pipes[0]);
    $failed = false;
    try {
        atomicConfirm();
    } catch (Throwable) {
        $failed = true;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    atomicAssert(proc_close($worker) === 0 && $failed, 'connection-killed-during-transaction');
    // A fresh connection is allowed only after the atomic operation has failed.
    $wpdb->check_connection(false);
    atomicAssert(atomicRows() === $before, 'disconnect-rolled-back-without-replay');
    $wpdb->query('DROP TRIGGER rrze_atomic_fault');
    BookingStorage::release();
    atomicAssert(atomicConfirm() !== null, 'retry-after-disconnect');

    echo json_encode(['checks' => $GLOBALS['atomicChecks']], JSON_THROW_ON_ERROR) . "\n";
} finally {
    BookingStorage::release();
    $wpdb->query('DROP TRIGGER IF EXISTS rrze_atomic_fault');
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
    $wpdb->options = $originalTable;
    wp_cache_flush();
}

}
