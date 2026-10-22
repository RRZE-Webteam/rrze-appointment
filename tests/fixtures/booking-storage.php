<?php

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Storage\BookingCipher;
use RRZE\Appointment\Storage\BookingScheduler;
use RRZE\Appointment\Storage\BookingStorage;
use RRZE\Appointment\Storage\EncryptionKey;

define('ABSPATH', __DIR__);
if (PHP_SAPI !== 'cli') {
    exit;
}
define('DB_NAME', 'isolated-appointment-tests');
define('MINUTE_IN_SECONDS', 60);

/** Loads real plugin classes without WordPress or the configured application key. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\Appointment\\';
    if (str_starts_with($class, $prefix)) {
        require_once dirname(__DIR__, 2) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

/** Executes the production conditional SQL against SQLite and models connection locks. */
final class BookingTestDatabase
{
    public string $options = 'wp_options';
    public string $blogs = 'wp_blogs';
    public string $last_error = '';
    public bool $failReads = false;
    public bool $failSiteQuery = false;
    public bool $denyLock = false;
    public bool $lostLock = false;
    public int $writeCount = 0;
    public int $failWrite = 0;
    public ?Closure $beforeWrite = null;
    public array $locks = [];
    public array $activeLocks = [];
    public int $peakLocks = 0;
    public SQLite3 $db;

    /** Creates empty synthetic site tables and the MySQL digest equivalent. */
    public function __construct()
    {
        $this->db = new SQLite3(':memory:');
        $this->db->enableExceptions(true);
        $this->db->createFunction('SHA2', static fn($data, $bits) => hash('sha256', $data), 2);
        foreach (['wp_options', 'wp_2_options'] as $table) {
            $this->db->exec("CREATE TABLE $table (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)");
        }
    }

    /** Quotes wpdb placeholders for the isolated SQLite database. */
    public function prepare(string $query, ...$args): string
    {
        $index = 0;
        return preg_replace_callback('/%[isd]/', static function ($match) use ($args, &$index): string {
            $value = $args[$index++];
            return match ($match[0]) {
                '%i' => '"' . str_replace('"', '""', $value) . '"',
                '%d' => (string) (int) $value,
                default => "'" . SQLite3::escapeString($value) . "'",
            };
        }, $query);
    }

    /** Reads SQL scalars; named locks are modeled rather than translated to SQLite. */
    public function get_var(string $query): ?string
    {
        $this->last_error = $this->failReads ? 'synthetic failure' : '';
        if ($this->failReads) {
            return null;
        }
        if (str_contains($query, 'GET_LOCK(')) {
            $this->locks[] = $query;
            if (!$this->denyLock) {
                preg_match("/GET_LOCK\('([^']+)'/", $query, $match);
                $this->activeLocks[$match[1]] = true;
                $this->peakLocks = max($this->peakLocks, count($this->activeLocks));
            }
            return $this->denyLock ? '0' : '1';
        }
        if (str_contains($query, 'IS_USED_LOCK(')) {
            return $this->lostLock ? '0' : '1';
        }
        if (str_contains($query, 'RELEASE_LOCK(')) {
            preg_match("/RELEASE_LOCK\('([^']+)'/", $query, $match);
            unset($this->activeLocks[$match[1]]);
            return '1';
        }
        $result = $this->db->querySingle($query);
        return $result === null ? null : (string) $result;
    }

    /** Runs bounded site-ID queries against the synthetic network registry. */
    public function get_col(string $query): array
    {
        $this->last_error = $this->failReads || $this->failSiteQuery ? 'synthetic failure' : '';
        if ($this->last_error !== '') {
            return [];
        }
        $rows = [];
        $result = $this->db->query($query);
        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            $rows[] = $row[0];
        }
        return $rows;
    }

    /** Runs real conditional writes, optionally injecting a crash or concurrent write. */
    public function query(string $query): int|false
    {
        if (++$this->writeCount === $this->failWrite) {
            return false;
        }
        if ($this->beforeWrite !== null) {
            ($this->beforeWrite)($query);
        }
        $this->db->exec(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query));
        return $this->db->changes();
    }

    /** Inserts a fixture value without invoking the storage layer under test. */
    public function seed(string $name, string $raw): void
    {
        $this->db->exec($this->prepare('INSERT OR REPLACE INTO %i VALUES (%s, %s, %s)', $this->options, $name, $raw, 'yes'));
    }

    /** Returns a fixture row directly for assertions. */
    public function raw(string $name): ?string
    {
        return $this->db->querySingle($this->prepare('SELECT option_value FROM %i WHERE option_name = %s', $this->options, $name));
    }

    /** Returns all raw rows without decrypting them. */
    public function rows(): array
    {
        $rows = [];
        $result = $this->db->query('SELECT * FROM ' . $this->options . ' ORDER BY option_name');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[$row['option_name']] = $row;
        }
        return $rows;
    }
}

/** Records invalidation; no decrypted values may be passed to a cache API. */
function wp_cache_delete($name, $group): void { $GLOBALS['evicted'][] = [$name, $group]; }
/** Generates deterministic synthetic identifiers for domain integration assertions. */
function wp_generate_uuid4(): string { return 'new-token-' . ++$GLOBALS['uuid']; }
/** Supplies a fixed site URL without reading installation configuration. */
function home_url($path = ''): string { return 'https://booking.test' . $path; }
/** Builds the public link used by existing-token regression tests. */
function add_query_arg($key, $value, $url): string { return $url . '?' . $key . '=' . rawurlencode($value); }
/** Supplies synthetic salts for the existing token generation algorithm. */
function wp_salt($scheme = 'auth'): string { return 'synthetic-salt'; }
/** Implements the existing token hash API with test-only key material. */
function wp_hash($data, $scheme = 'auth'): string { return hash_hmac('sha256', $data, wp_salt($scheme)); }
/** Persists scheduled jobs in the fixture cron option like WordPress does. */
function wp_schedule_single_event($timestamp, $hook, $arguments = []): bool
{
    global $wpdb;
    $cron = unserialize($wpdb->raw('cron') ?? 'a:0:{}', ['allowed_classes' => false]);
    $cron[$timestamp][$hook][md5(serialize($arguments))] = ['schedule' => false, 'args' => $arguments];
    $wpdb->seed('cron', serialize($cron));
    return true;
}
/** Removes matching scheduled events from the fixture cron queue. */
function wp_clear_scheduled_hook($hook, $arguments = []): void
{
    global $wpdb;
    $cron = unserialize($wpdb->raw('cron') ?? 'a:0:{}', ['allowed_classes' => false]);
    foreach ($cron as &$hooks) {
        if (is_array($hooks)) {
            unset($hooks[$hook][md5(serialize($arguments))]);
        }
    }
    unset($hooks);
    $wpdb->seed('cron', serialize($cron));
}
/** Finds a matching event in the fixture cron queue. */
function wp_next_scheduled($hook, $arguments = []): int|false
{
    global $wpdb;
    foreach (unserialize($wpdb->raw('cron') ?? 'a:0:{}', ['allowed_classes' => false]) as $timestamp => $hooks) {
        if (is_array($hooks) && isset($hooks[$hook][md5(serialize($arguments))])) {
            return (int) $timestamp;
        }
    }
    return false;
}

/** Captures only safe failure information from the real storage code. */
function failure(callable $operation): array
{
    try {
        $operation();
        return ['rejected' => false];
    } catch (AppointmentException $exception) {
        return ['rejected' => true, 'message' => $exception->getMessage()];
    }
}

if (defined('RRZE_APPOINTMENT_STORAGE_TEST_BOOTSTRAP_ONLY')) {
    return;
}

$input = json_decode($argv[1] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
$scenario = $input['scenario'] ?? 'migrate';
$key = base64_encode(str_repeat('test-key', 8));
if (!in_array($scenario, ['legacy', 'missing-key'], true)) {
    define('RRZE_APPOINTMENT_ENCRYPTION_KEY', $scenario === 'wrong-key' ? base64_encode(str_repeat('otherkey', 8)) : ($scenario === 'invalid-key' ? 'invalid' : $key));
}
$wpdb = new BookingTestDatabase();
$GLOBALS['uuid'] = 0;
$GLOBALS['evicted'] = [];
$slot = '2099-01-02 10:00-10:30';
$data = [
    Bookings::SLOTS_OPTION => [$slot],
    Bookings::META_OPTION => [$slot => ['booker_name' => 'Synthetic Patient', 'booker_email' => 'synthetic@example.test', 'cancel_token' => 'old-cancel', 'waitlist_optout_token' => 'old-optout']],
    TokenManager::PENDING_OPTION => ['old-confirm' => ['slot' => '2099-01-03 11:00-11:30', 'meta' => ['booker_name' => 'Pending Patient'], 'expires' => time() + 1800]],
    TokenManager::CANCEL_OPTION => ['old-cancel' => $slot],
    BookingStorage::OPTIONS[4] => ['old-opening' => ['slot' => '2099-01-04 09:00-09:30', 'meta' => ['booker_email' => 'opening@example.test'], 'closes_at' => time() + 86400]],
];
foreach ($data as $name => $value) {
    $wpdb->seed($name, serialize($value));
}
$wpdb->seed('public-settings', serialize(['title' => 'Public office hours']));
$hooks = ['rrze_appointment_send_reminder' => $slot, TokenManager::PENDING_EXPIRY_HOOK => 'old-confirm', 'rrze_appointment_booking_opened' => 'old-opening'];
$cron = ['version' => 2, 4000000000 => ['unrelated_job' => ['untouched' => ['schedule' => 'daily', 'interval' => 86400, 'args' => ['public']]]]];
foreach ($hooks as $hook => $argument) {
    $cron[4000000000][$hook][md5(serialize([$argument]))] = ['schedule' => false, 'args' => [$argument]];
}
$wpdb->seed('cron', serialize($cron));
$result = [];

if (in_array($scenario, ['wrong-key', 'missing-key', 'tamper', 'missing-option', 'plaintext-replacement', 'missing-state', 'invalid-state', 'swapped', 'downgraded-state'], true)) {
    $context = str_repeat('a', 32);
    $cipher = new BookingCipher(EncryptionKey::fromBase64($key), $context);
    foreach ($data as $name => $value) {
        $wpdb->seed($name, $cipher->encrypt($value, $name));
    }
    $state = ['version' => 1, 'phase' => 'encrypted', 'context' => $context, 'key_id' => EncryptionKey::fromBase64($key)->identifier($context), 'completed' => BookingStorage::OPTIONS];
    $state['mac'] = hash_hmac('sha256', json_encode(array_values($state)), EncryptionKey::fromBase64($key)->forState($context));
    $wpdb->seed(BookingStorage::STATE_OPTION, json_encode($state));
    if ($scenario === 'downgraded-state') {
        $state['completed'] = [];
        $state['phase'] = 'migrating';
        $wpdb->seed(BookingStorage::STATE_OPTION, json_encode($state));
        $wpdb->seed(Bookings::META_OPTION, serialize($data[Bookings::META_OPTION]));
    } elseif ($scenario === 'missing-option') {
        $wpdb->db->exec($wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $wpdb->options, Bookings::META_OPTION));
    } elseif ($scenario === 'missing-state') {
        $wpdb->db->exec($wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $wpdb->options, BookingStorage::STATE_OPTION));
    } elseif ($scenario === 'plaintext-replacement') {
        $wpdb->seed(Bookings::META_OPTION, serialize($data[Bookings::META_OPTION]));
    } elseif ($scenario === 'tamper') {
        $wpdb->seed(Bookings::META_OPTION, $wpdb->raw(Bookings::META_OPTION) . 'broken');
    } elseif ($scenario === 'invalid-state') {
        $wpdb->seed(BookingStorage::STATE_OPTION, '{}');
    } elseif ($scenario === 'swapped') {
        $wpdb->seed(Bookings::META_OPTION, $wpdb->raw(TokenManager::PENDING_OPTION));
    }
    $before = $wpdb->rows();
    $result = failure(static fn() => TokenManager::cleanupPendingState());
    $result['unchanged'] = $wpdb->rows() === $before;
} elseif (in_array($scenario, ['invalid-key', 'corrupt-legacy', 'object-legacy', 'database-error', 'lock-busy'], true)) {
    if ($scenario === 'corrupt-legacy') { $wpdb->seed(Bookings::META_OPTION, 'broken'); }
    if ($scenario === 'object-legacy') { $wpdb->seed(Bookings::META_OPTION, serialize(['object' => new stdClass()])); }
    if ($scenario === 'database-error') { $wpdb->failReads = true; }
    if ($scenario === 'lock-busy') { $wpdb->denyLock = true; }
    $before = $wpdb->rows();
    $result = failure(static fn() => BookingStorage::initialize());
    $result['unchanged'] = $wpdb->rows() === $before;
} elseif ($scenario === 'legacy') {
    BookingStorage::initialize();
    $result['status'] = BookingStorage::status();
    $result['data'] = BookingStorage::get(Bookings::META_OPTION) === $data[Bookings::META_OPTION];
    BookingStorage::set(Bookings::SLOTS_OPTION, [$slot, 'new-slot']);
    $result['legacyWrite'] = unserialize($wpdb->raw(Bookings::SLOTS_OPTION)) === [$slot, 'new-slot'];
    $result['noState'] = $wpdb->raw(BookingStorage::STATE_OPTION) === null;
} elseif ($scenario === 'automatic-write-failure') {
    $before = $wpdb->raw(TokenManager::PENDING_OPTION);
    $wpdb->failWrite = 4;
    $result = failure(static fn() => BookingStorage::set(TokenManager::PENDING_OPTION, ['unexpected' => 'plaintext']));
    $result['secondAttempt'] = failure(static fn() => BookingStorage::set(TokenManager::PENDING_OPTION, []));
    $result['originalPendingUntouched'] = $wpdb->raw(TokenManager::PENDING_OPTION) === $before;
    $result['migrationStarted'] = json_decode($wpdb->raw(BookingStorage::STATE_OPTION), true)['phase'] === 'migrating';
} elseif ($scenario === 'write-conflict') {
    BookingStorage::get(Bookings::SLOTS_OPTION);
    $newer = [$slot, 'concurrent-booking'];
    $wpdb->beforeWrite = static function ($query) use ($wpdb, $newer): void {
        $wpdb->beforeWrite = null;
        $wpdb->seed(Bookings::SLOTS_OPTION, serialize($newer));
    };
    $result = failure(static fn() => BookingStorage::set(Bookings::SLOTS_OPTION, []));
    $result['newerPreserved'] = unserialize($wpdb->raw(Bookings::SLOTS_OPTION)) === $newer;
} elseif ($scenario === 'verification-failure') {
    BookingStorage::get(Bookings::SLOTS_OPTION);
    $GLOBALS['evicted'] = [];
    $wpdb->beforeWrite = static function ($query) use ($wpdb): void {
        $wpdb->beforeWrite = null;
        $wpdb->lostLock = true;
    };
    $result = failure(static fn() => BookingStorage::set(Bookings::SLOTS_OPTION, ['changed']));
    $context = json_decode($wpdb->raw(BookingStorage::STATE_OPTION), true)['context'];
    $cipher = new BookingCipher(EncryptionKey::fromBase64($key), $context);
    $result['writeReachedDatabase'] = $cipher->decrypt($wpdb->raw(Bookings::SLOTS_OPTION), Bookings::SLOTS_OPTION) === ['changed'];
    $result['cacheEvicted'] = in_array([Bookings::SLOTS_OPTION, 'options'], $GLOBALS['evicted'], true)
        && in_array(['alloptions', 'options'], $GLOBALS['evicted'], true)
        && in_array(['notoptions', 'options'], $GLOBALS['evicted'], true);
} elseif ($scenario === 'job-outage') {
    BookingStorage::initialize();
    $hook = 'rrze_appointment_booking_opened';
    $reference = BookingStorage::jobReference($hook, 'old-opening');
    wp_clear_scheduled_hook($hook, [$reference]);
    BookingStorage::release();
    $original = $wpdb->raw(Bookings::META_OPTION);
    $wpdb->seed(Bookings::META_OPTION, 'broken');
    BookingScheduler::notifyOpening($reference);
    $result['opaqueRetry'] = wp_next_scheduled($hook, [$reference]) !== false;
    $result['noPlaintextToken'] = !str_contains($wpdb->raw('cron'), 'old-opening');
    $wpdb->seed(Bookings::META_OPTION, $original);
    wp_clear_scheduled_hook($hook, [$reference]);
    \RRZE\Appointment\Notification\BookingOpeningNotifier::cleanup();
    $result['missingJobRepaired'] = wp_next_scheduled($hook, [$reference]) !== false;
} elseif ($scenario === 'cron-failure') {
    $wpdb->seed('cron', serialize([4000000000 => ['rrze_appointment_booking_opened' => ['broken' => ['args' => []]]]]));
    $result['failure'] = failure(static fn() => BookingStorage::initialize());
    BookingStorage::release();
    $result['phaseAfterFailure'] = BookingStorage::status()['phase'];
    $result['completedAfterFailure'] = BookingStorage::status()['completed'];
    $wpdb->seed('cron', serialize($cron));
    BookingStorage::initialize();
    $result['phaseAfterResume'] = BookingStorage::status()['phase'];
} elseif ($scenario === 'lost-lock') {
    BookingStorage::get(Bookings::SLOTS_OPTION);
    $before = $wpdb->raw(Bookings::SLOTS_OPTION);
    $wpdb->lostLock = true;
    $result = failure(static fn() => BookingStorage::set(Bookings::SLOTS_OPTION, []));
    $result['unchanged'] = $wpdb->raw(Bookings::SLOTS_OPTION) === $before;
} else {
    if ($scenario === 'resume' || $scenario === 'checkpoint-failure') {
        $wpdb->failWrite = $scenario === 'resume' ? 4 : 3;
        $result['interrupted'] = failure(static fn() => BookingStorage::initialize());
        BookingStorage::release();
        $wpdb->failWrite = 0;
        $result['partialStatus'] = BookingStorage::status();
        $result['newToken'] = TokenManager::createPending('2099-02-03 11:00-11:30', ['booker_name' => 'During Migration']);
        $result['newWriteEncrypted'] = str_starts_with($wpdb->raw(TokenManager::PENDING_OPTION), BookingCipher::PREFIX);
        $result['automaticallyResumed'] = BookingStorage::status()['phase'] === 'encrypted';
    } elseif ($scenario === 'cron-conflict') {
        $wpdb->beforeWrite = static function ($query) use ($wpdb): void {
            if (str_contains($query, "option_name = 'cron'")) {
                $wpdb->beforeWrite = null;
                $cron = unserialize($wpdb->raw('cron'));
                $cron[4000000001]['another_plugin']['added'] = ['schedule' => false, 'args' => []];
                $wpdb->seed('cron', serialize($cron));
            }
        };
    } elseif ($scenario === 'empty') {
        foreach (BookingStorage::OPTIONS as $name) {
            $wpdb->db->exec($wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name));
        }
    }
    if ($scenario === 'first-access') {
        BookingStorage::get(Bookings::META_OPTION);
    } else {
        BookingStorage::initialize();
    }
    BookingStorage::release();
    $result['status'] = BookingStorage::status();
    $result['allEncrypted'] = true;
    $result['autoloadOff'] = true;
    foreach (BookingStorage::OPTIONS as $name) {
        $result['allEncrypted'] = $result['allEncrypted'] && str_starts_with($wpdb->raw($name), BookingCipher::PREFIX);
        $result['autoloadOff'] = $result['autoloadOff'] && $wpdb->rows()[$name]['autoload'] === 'no';
    }
    $result['payloadPreserved'] = $scenario === 'empty' ? BookingStorage::get(Bookings::META_OPTION) === [] : BookingStorage::get(Bookings::META_OPTION) === $data[Bookings::META_OPTION];
    $result['publicUntouched'] = unserialize($wpdb->raw('public-settings')) === ['title' => 'Public office hours'];
    $afterCron = unserialize($wpdb->raw('cron'));
    $result['otherCronUntouched'] = $afterCron[4000000000]['unrelated_job'] === $cron[4000000000]['unrelated_job'];
    $result['concurrentCronPreserved'] = isset($afterCron[4000000001]['another_plugin']);
    $result['rawClean'] = !preg_match('/Synthetic Patient|synthetic@example|old-confirm|old-cancel|old-optout|old-opening|2099-01-0/', json_encode($wpdb->rows()));
    $result['referencesResolve'] = true;
    foreach ($hooks as $hook => $argument) {
        $event = reset($afterCron[4000000000][$hook]);
        $result['referencesResolve'] = $result['referencesResolve'] && BookingScheduler::resolve($hook, $event['args'][0]) === ($scenario === 'empty' ? null : $argument);
    }
    if ($scenario !== 'empty') {
        $result['oldCancellation'] = TokenManager::validateCancelToken('old-cancel')['slot'] === $slot;
        $result['oldUrl'] = TokenManager::getCancelUrlForSlot($slot) === 'https://booking.test/?rrze_appt_cancel=old-cancel';
        $result['oldOptout'] = TokenManager::validateWaitlistOptOutToken('old-optout') === $slot;
        $result['oldConfirmation'] = TokenManager::confirmPending('old-confirm')['meta']['booker_name'] === 'Pending Patient';
        $result['pendingConsumed'] = TokenManager::getPending('old-confirm') === null;
        if (isset($result['newToken'])) {
            $result['newBookingPreserved'] = TokenManager::getPending($result['newToken'])['meta']['booker_name'] === 'During Migration';
        }
        $token = TokenManager::createPending('2099-03-04 09:00-09:30', ['booker_name' => 'After Migration']);
        $reference = BookingStorage::jobReference(TokenManager::PENDING_EXPIRY_HOOK, $token);
        $result['newJobOpaque'] = $reference !== $token && wp_next_scheduled(TokenManager::PENDING_EXPIRY_HOOK, [$reference]) !== false;
        BookingScheduler::expirePending($reference);
        $result['dispatchWorks'] = TokenManager::getPending($token) === null;
    }
    $before = $wpdb->raw(BookingStorage::STATE_OPTION);
    $writes = $wpdb->writeCount;
    $beforeRows = $wpdb->rows();
    BookingStorage::release();
    BookingStorage::initialize();
    $result['noRepeatedWrites'] = $wpdb->writeCount === $writes && $wpdb->rows() === $beforeRows;
    $result['idempotentState'] = $wpdb->raw(BookingStorage::STATE_OPTION) === $before;
    if ($scenario === 'multisite') {
        $first = json_decode($before, true);
        $wpdb->options = 'wp_2_options';
        BookingStorage::get(Bookings::META_OPTION);
        $second = json_decode($wpdb->raw(BookingStorage::STATE_OPTION), true);
        $result['separateContext'] = $first['context'] !== $second['context'];
        $result['separateData'] = BookingStorage::get(Bookings::META_OPTION) === [];
        $result['separateLocks'] = count(array_unique($wpdb->locks)) === 2;
        $wpdb->options = 'wp_options';
        $result['firstSitePreserved'] = BookingStorage::get(Bookings::META_OPTION) === $data[Bookings::META_OPTION];
    }
}
BookingStorage::release();
echo json_encode($result, JSON_THROW_ON_ERROR);
