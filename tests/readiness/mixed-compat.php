<?php

/** Real WordPress/mysqli compatibility and corruption probes limited to the disposable audit database. */

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Storage\BookingCipher;
use RRZE\Appointment\Storage\BookingStorage;
use RRZE\Appointment\Storage\EncryptionKey;
use RRZE\Appointment\Storage\MixedRecordStore;

if (!defined('WP_CLI') || !WP_CLI || !defined('RRZE_APPOINTMENT_AUDIT_ROOT')
    || !str_starts_with(DB_NAME, 'rrze_appt_audit_') || $GLOBALS['wpdb']->get_var('SELECT DATABASE()') !== DB_NAME
    || wp_using_ext_object_cache()) {
    fwrite(STDERR, "This fixture requires the isolated audit database and request-local cache.\n");
    exit(1);
}

/** Loads the installed candidate without running site startup when WP-CLI skips regular plugins. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\Appointment\\';
    if (str_starts_with($class, $prefix)) {
        $file = WP_PLUGIN_DIR . '/rrze-appointment/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

const RRZE_MIXED_COMPAT_TABLE = 'rrze_appt_mixed_compat';

/** Records only a test label and result. */
function compatCheck(bool $passed, string $label): void
{
    $GLOBALS['compatChecks'][$label] = $passed;
    if (!$passed) {
        throw new RuntimeException('Synthetic compatibility assertion failed.');
    }
}

/** Checks the selected table before any destructive fixture operation. */
function compatTable(): string
{
    if ($GLOBALS['wpdb']->options !== RRZE_MIXED_COMPAT_TABLE) {
        throw new RuntimeException('Synthetic compatibility table was not selected.');
    }
    return RRZE_MIXED_COMPAT_TABLE;
}

/** Runs a query which may only address the scratch table. */
function compatQuery(#[SensitiveParameter] string $sql): void
{
    global $wpdb;
    compatTable();
    if ($wpdb->query($sql) === false) {
        throw new RuntimeException('Synthetic compatibility query failed.');
    }
}

/** Captures ordered raw rows (including autoload flags) for read-only comparisons. */
function compatRows(): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value, autoload FROM %i ORDER BY option_name', compatTable()), ARRAY_A);
    if (!is_array($rows) || $wpdb->last_error !== '') {
        throw new RuntimeException('Synthetic compatibility snapshot failed.');
    }
    return array_column($rows, null, 'option_name');
}

/** Replaces a single scratch option outside of the application under test. */
function compatPut(string $name, #[SensitiveParameter] string $raw, string $autoload = 'no'): void
{
    global $wpdb;
    if ($wpdb->replace(compatTable(), ['option_name' => $name, 'option_value' => $raw, 'autoload' => $autoload]) === false) {
        throw new RuntimeException('Synthetic fixture write failed.');
    }
}

/** Starts a scenario from exact saved bytes without inherited WordPress option caches. */
function compatRestore(#[SensitiveParameter] array $rows = []): void
{
    global $wpdb;
    BookingStorage::release();
    wp_cache_flush();
    compatQuery($wpdb->prepare('TRUNCATE TABLE %i', compatTable()));
    foreach ($rows as $name => $row) {
        compatPut($name, $row['option_value'], $row['autoload']);
    }
}

/**
 * Encodes the historical envelope independently of the candidate decoder.
 * This is the algorithm and exact AAD/JSON layout shipped in ce06259.
 *
 * @param string $name V1 physical storage name.
 * @param array $data Synthetic booking array.
 * @param string $context Persistent V1 site context.
 * @param string $id Public key identifier.
 * @param string $key Binary derived storage key.
 */
function compatV1Envelope(string $name, #[SensitiveParameter] array $data, string $context, string $id, #[SensitiveParameter] string $key): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $aad = json_encode(['rrze-appointment', 1, $id, $context, $name], JSON_THROW_ON_ERROR);
    $plain = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 64);
    $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $aad, $nonce, $key);
    return 'rrze-appointment:encrypted:' . json_encode([
        'version' => 1, 'key_id' => $id, 'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($cipher),
    ], JSON_THROW_ON_ERROR);
}

/** Seeds complete V1 or the crash boundary between a write and a signed V1 checkpoint. */
function compatSeedV1(bool $partial): array
{
    compatRestore();
    $slot = '2099-11-12 10:00-10:30';
    $second = '2099-12-12 13:00-13:30';
    $values = array_fill_keys(BookingStorage::OPTIONS, []);
    $values[Bookings::SLOTS_OPTION] = [$slot, $second];
    $values[Bookings::META_OPTION] = [$slot => ['booker_name' => 'Synthetic legacy A', 'cancel_token' => 'v1-cancel', 'waitlist_optout_token' => 'v1-optout'],
        $second => ['booker_name' => 'Synthetic legacy B']];
    $values[TokenManager::PENDING_OPTION] = ['v1-pending' => ['slot' => '2099-12-13 12:00-12:30', 'meta' => ['booker_name' => 'V1 pending'], 'expires' => time() + 1800]];
    $values[TokenManager::CANCEL_OPTION] = ['v1-cancel' => $slot];
    $context = bin2hex(random_bytes(16));
    $master = base64_decode(preg_replace('/\s+/', '', RRZE_APPOINTMENT_ENCRYPTION_KEY), true);
    $id = bin2hex(hash_hkdf('sha256', $master, 16, 'rrze-appointment:key-id:v1', $context));
    $storageKey = hash_hkdf('sha256', $master, 32, 'rrze-appointment:storage:v1', $context);
    $completed = $partial ? [Bookings::SLOTS_OPTION] : BookingStorage::OPTIONS;
    foreach ($values as $name => $data) {
        // PENDING is already ciphered in both snapshots; in the partial case its checkpoint is absent.
        $encrypted = in_array($name, $completed, true) || $name === TokenManager::PENDING_OPTION;
        compatPut($name, $encrypted ? compatV1Envelope($name, $data, $context, $id, $storageKey) : serialize($data));
    }
    $state = ['version' => 1, 'phase' => $partial ? 'migrating' : 'encrypted',
        'context' => $context, 'key_id' => $id, 'completed' => $completed];
    $state['mac'] = hash_hmac('sha256', json_encode(array_values($state), JSON_THROW_ON_ERROR),
        hash_hkdf('sha256', $master, 32, 'rrze-appointment:state:v1', $context));
    compatPut(BookingStorage::STATE_OPTION, json_encode($state, JSON_THROW_ON_ERROR));
    $hook = 'rrze_appointment_send_reminder';
    $args = ['rrze-appt-job:v1:' . hash_hmac('sha256', $hook . "\0" . $slot,
        hash_hkdf('sha256', $master, 32, 'rrze-appointment:jobs:v1', $context))];
    $due = time() + 7200;
    compatPut('cron', serialize([$due => [$hook => [md5(serialize($args)) => ['schedule' => false, 'args' => $args]]], 'version' => 2]));
    return [$values, $slot, $second, $due];
}

/** Returns true only for a safe domain rejection of a storage operation. */
function compatRejected(callable $operation): bool
{
    try {
        $operation();
    } catch (\RRZE\Appointment\AppointmentException $exception) {
        return true;
    }
    return false;
}

/** Exercises old V1 links, independent checkpoint formats and atomic promotion of one booking. */
function compatPromotion(bool $partial): string
{
    [$values, $slot, $other, $due] = compatSeedV1($partial);
    $mode = $partial ? 'partial-v1' : 'complete-v1';
    $before = compatRows();
    BookingStorage::verifyMixed();
    foreach ($values as $name => $array) {
        compatCheck(BookingStorage::get($name) === $array, $mode . '-read-' . $name);
    }
    compatCheck(compatRejected([BookingStorage::class, 'verify']) === $partial, $mode . '-strict-before');
    compatCheck(compatRows() === $before, $mode . '-reads-unchanged');
    if ($partial) {
        compatPut(Bookings::SLOTS_OPTION, serialize($values[Bookings::SLOTS_OPTION]));
        compatCheck(compatRejected([BookingStorage::class, 'verifyMixed']), 'signed-v1-checkpoint-rejects-plaintext');
        compatRestore($before);
    }
    $meta = BookingStorage::get(Bookings::META_OPTION);
    $meta[$slot]['booker_waitlist'] = true;
    BookingStorage::set(Bookings::META_OPTION, $meta);
    BookingStorage::release();
    BookingStorage::verifyMixed();
    $after = compatRows();
    $id = BookingStorage::recordId('booking', $slot);
    compatCheck(is_string($id) && strlen($id) === 32, $mode . '-record-created');
    compatCheck(BookingStorage::recordId('booking', $other) === null && BookingStorage::get(Bookings::META_OPTION)[$other] === $values[Bookings::META_OPTION][$other], $mode . '-untouched-booking');
    compatCheck($after[BookingStorage::STATE_OPTION] === $before[BookingStorage::STATE_OPTION]
        && $after[TokenManager::PENDING_OPTION] === $before[TokenManager::PENDING_OPTION], $mode . '-v1-checkpoints-preserved');
    compatCheck(str_starts_with($after[Bookings::META_OPTION]['option_value'], BookingCipher::PREFIX) === !$partial,
        $mode . '-remaining-source-format');
    compatCheck(BookingStorage::get(TokenManager::CANCEL_OPTION)['v1-cancel'] === $slot
        && TokenManager::validateWaitlistOptOutToken('v1-optout') === $slot, $mode . '-links-preserved');
    $jobs = BookingStorage::managedJobs()[$id]['jobs'];
    compatCheck(in_array($due, array_column($jobs, 'due'), true), $mode . '-v1-hmac-job-due-preserved');
    compatCheck(compatRejected([BookingStorage::class, 'verify']) === $partial, $mode . '-strict-after');
    return $id;
}

global $wpdb;
$mode = $args[0] ?? 'check';
if (!in_array($mode, ['check', 'seed-key-test', 'reject-key', 'cleanup'], true)) {
    WP_CLI::error('Invalid isolated compatibility test mode.');
}
$original = $wpdb->options;
BookingStorage::release();
$wpdb->options = RRZE_MIXED_COMPAT_TABLE;
wp_cache_flush();
$GLOBALS['compatChecks'] = [];
$failed = false;
try {
    if ($mode === 'cleanup') {
        compatQuery($wpdb->prepare('DROP TABLE IF EXISTS %i', compatTable()));
        compatCheck(true, 'scratch-table-removed');
    } elseif ($mode === 'reject-key') {
        $before = compatRows();
        $policy = json_decode($before[BookingStorage::MIXED_STATE_OPTION]['option_value'], true, flags: JSON_THROW_ON_ERROR);
        compatCheck($policy['site_id'] === get_current_blog_id(), 'same-site-used-for-key-outage');
        if (defined(EncryptionKey::CONFIGURATION_CONSTANT)) {
            compatCheck(!hash_equals($policy['key_id'], EncryptionKey::fromConfiguration()->identifier($policy['context'])),
                'wrong-key-present');
        } else {
            compatCheck(true, 'key-is-absent');
        }
        compatCheck(compatRejected([BookingStorage::class, 'verifyMixed']), 'key-outage-blocks-verification');
        BookingStorage::release();
        compatCheck(compatRejected(static fn() => BookingStorage::set(Bookings::META_OPTION, [])), 'key-outage-blocks-write');
        compatCheck(compatRows() === $before, 'key-outage-retains-all-bytes');
    } else {
        EncryptionKey::fromConfiguration();
        compatQuery($wpdb->prepare('DROP TABLE IF EXISTS %i', compatTable()));
        compatQuery($wpdb->prepare("CREATE TABLE %i (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
             option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'no') ENGINE=InnoDB", compatTable()));
        if ($mode === 'seed-key-test') {
            $slot = '2099-10-20 10:00-10:30';
            BookingStorage::setMany([Bookings::SLOTS_OPTION => [$slot], Bookings::META_OPTION => [$slot => ['booker_name' => 'Synthetic']]]);
            compatCheck(BookingStorage::hasMixedState() && BookingStorage::recordId('booking', $slot) !== null, 'v2-only-key-outage-fixture-ready');
        } else {
            compatPromotion(false);
            $id = compatPromotion(true);
            $baseline = compatRows();
            $recordName = 'rrze_appointment_v2_record_booking_' . $id;
            $policyName = BookingStorage::MIXED_STATE_OPTION;
            foreach (['changed-policy', 'missing-policy', 'missing-index', 'missing-record', 'foreign-index-envelope',
                'damaged-record', 'unknown-data-version', 'unknown-state-version', 'orphan-without-index-and-policy'] as $fault) {
                compatRestore($baseline);
                $policy = json_decode($baseline[$policyName]['option_value'], true, flags: JSON_THROW_ON_ERROR);
                switch ($fault) {
                    case 'missing-policy':
                    case 'missing-index':
                    case 'missing-record':
                    case 'orphan-without-index-and-policy':
                        $names = match ($fault) {
                            'missing-policy' => [$policyName], 'missing-index' => [MixedRecordStore::INDEX],
                            'missing-record' => [$recordName], default => [$policyName, MixedRecordStore::INDEX],
                        };
                        foreach ($names as $name) {
                            compatQuery($wpdb->prepare('DELETE FROM %i WHERE option_name = %s', compatTable(), $name));
                        }
                        break;
                    case 'changed-policy':
                        $policy['mac'] = str_repeat('0', 64);
                        compatPut($policyName, json_encode($policy, JSON_THROW_ON_ERROR));
                        break;
                    case 'unknown-state-version':
                        $policy['state_version'] = 3;
                        unset($policy['mac']);
                        $policy['mac'] = hash_hmac('sha256', json_encode(['rrze-appointment:policy:v2', $policy], JSON_THROW_ON_ERROR),
                            EncryptionKey::fromConfiguration()->forState($policy['context']));
                        compatPut($policyName, json_encode($policy, JSON_THROW_ON_ERROR));
                        break;
                    case 'foreign-index-envelope':
                        compatPut(MixedRecordStore::INDEX, $baseline[$recordName]['option_value']);
                        break;
                    case 'unknown-data-version':
                        $codec = new BookingCipher(EncryptionKey::fromConfiguration(), $policy['context']);
                        $record = $codec->decrypt($baseline[$recordName]['option_value'], $recordName);
                        $record['data_version'] = 99;
                        compatPut($recordName, $codec->encrypt($record, $recordName));
                        break;
                    case 'damaged-record':
                        compatPut($recordName, $baseline[$recordName]['option_value'] . '!');
                        break;
                }
                $before = compatRows();
                compatCheck(compatRejected([BookingStorage::class, 'verifyMixed']), $fault . '-rejected');
                compatCheck(compatRows() === $before, $fault . '-unchanged');
            }
        }
    }
} catch (Throwable $failure) {
    $failed = true;
    $GLOBALS['compatChecks']['scenario-completed'] = false;
} finally {
    BookingStorage::release();
    if ($mode === 'check' || ($mode === 'seed-key-test' && $failed)) {
        compatQuery($wpdb->prepare('DROP TABLE IF EXISTS %i', compatTable()));
    }
    $wpdb->options = $original;
    wp_cache_flush();
}
echo json_encode(['checks' => $GLOBALS['compatChecks']], JSON_THROW_ON_ERROR) . "\n";
if ($failed) {
    WP_CLI::error('An isolated storage compatibility check failed.');
}
