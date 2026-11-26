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
require_once __DIR__ . '/storage-mysqli.php';

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
    public string $schema = 'isolated-appointment-tests';
    public SQLite3 $db;
    public mysqli $dbh;

    /** Creates empty synthetic site tables and the MySQL digest equivalent. */
    public function __construct()
    {
        // Unconnected native handle models the preflight driver check only.
        // Real transaction behavior is covered by readiness/atomic.php on InnoDB.
        $this->dbh = new mysqli();
        $this->db = new SQLite3(':memory:');
        $this->db->enableExceptions(true);
        $this->db->createFunction('SHA2', static fn($data, $bits) => hash('sha256', $data), 2);
        $this->db->createFunction('regexp', static fn($pattern, $value) => preg_match('~' . $pattern . '~D', $value), 2);
        foreach (['wp_options', 'wp_2_options'] as $table) {
            $this->db->exec("CREATE TABLE $table (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)");
        }
    }

    /** Quotes wpdb placeholders for the isolated SQLite database. */
    public function prepare(string $query, ...$args): string
    {
        $index = 0;
        return preg_replace_callback('/%[%isd]/', static function ($match) use ($args, &$index): string {
            if ($match[0] === '%%') {
                return '%';
            }
            $value = $args[$index++];
            return match ($match[0]) {
                '%i' => '"' . str_replace('"', '""', $value) . '"',
                '%d' => (string) (int) $value,
                default => "'" . SQLite3::escapeString($value) . "'",
            };
        }, $query);
    }

    /** The SQLite formatter leaves percent signs unchanged in prepared SQL. */
    public function remove_placeholder_escape(string $query): string
    {
        return $query;
    }

    /** Reads SQL scalars; named locks are modeled rather than translated to SQLite. */
    public function get_var(string $query): ?string
    {
        $this->last_error = $this->failReads ? 'synthetic failure' : '';
        if ($this->failReads) {
            return null;
        }
        if ($query === 'SELECT DATABASE()') {
            return $this->schema;
        }
        if (str_contains($query, 'information_schema.TABLES')) {
            return 'InnoDB';
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
            preg_match("/IS_USED_LOCK\\('([^']+)'/", $query, $match);
            $schemaChanged = preg_match("/DATABASE\\(\\) = BINARY '([^']+)'/", $query, $schema)
                && $schema[1] !== $this->schema;
            return !$this->lostLock && !$schemaChanged && isset($this->activeLocks[$match[1]]) ? '1' : '0';
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

    /** Returns associative rows for the physical V2 inventory. */
    public function get_results(string $query, string $format): array
    {
        $this->last_error = $this->failReads ? 'synthetic failure' : '';
        $rows = [];
        if ($this->last_error === '') {
            $result = $this->db->query($query);
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** Preserves the difference between an absent SQLite row and a stored empty value. */
    public function get_row(string $query, string $format): ?array
    {
        return $this->get_results($query, $format)[0] ?? null;
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

/** Maps synthetic wp_options tables to separate WordPress sites. */
if (!function_exists('get_current_blog_id')) {
    /** Selects the synthetic subsite without loading WordPress. */
    function get_current_blog_id(): int { return $GLOBALS['wpdb']->options === 'wp_options' ? 1 : 2; }
}
if (!function_exists('get_option')) {
    /** Reads scalar, serialized fixture configuration just as WordPress would. */
    function get_option(string $name, mixed $default = false): mixed
    {
        $raw = $GLOBALS['wpdb']->raw($name);
        return $raw === null ? $default : unserialize($raw, ['allowed_classes' => false]);
    }
}
if (!function_exists('wp_timezone')) {
    /** Supplies a deterministic site timezone for legacy reminder schedules. */
    function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Berlin'); }
}

/** Constructs a real signed V1 checkpoint from the fixture's independent configuration key. */
function seedV1(array $data, string $key, bool $partial): void
{
    global $wpdb;
    $context = str_repeat('a', 32);
    $material = EncryptionKey::fromBase64($key);
    $cipher = new BookingCipher($material, $context);
    $completed = $partial ? [Bookings::SLOTS_OPTION] : BookingStorage::OPTIONS;
    foreach ($completed as $name) {
        $wpdb->seed($name, $cipher->encrypt($data[$name], $name));
    }
    $state = ['version' => 1, 'phase' => $partial ? 'migrating' : 'encrypted',
        'context' => $context, 'key_id' => $material->identifier($context), 'completed' => $completed];
    $state['mac'] = hash_hmac('sha256', json_encode(array_values($state)), $material->forState($context));
    $wpdb->seed(BookingStorage::STATE_OPTION, json_encode($state));
}

/** Removes a physical row without using plugin code to simulate data loss. */
function dropRow(string $name): void
{
    global $wpdb;
    $wpdb->db->exec($wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name));
}

/** Changes one historical booking's waitlist preference using the storage contract. */
function changePreference(string $slot): string
{
    $meta = BookingStorage::get(Bookings::META_OPTION);
    $meta[$slot]['booker_waitlist'] = !($meta[$slot]['booker_waitlist'] ?? false);
    BookingStorage::set(Bookings::META_OPTION, $meta);
    return BookingStorage::recordId('booking', $slot);
}

$input = json_decode($argv[1] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
$scenario = $input['scenario'] ?? 'read';
$key = base64_encode(str_repeat('test-key', 8));
if (!in_array($scenario, ['legacy', 'missing-key'], true)) {
    define('RRZE_APPOINTMENT_ENCRYPTION_KEY', match ($scenario) {
        'invalid-key' => 'invalid',
        'wrong-key' => base64_encode(str_repeat('otherkey', 8)),
        default => $key,
    });
}
$wpdb = new BookingTestDatabase();
$GLOBALS['uuid'] = 0;
$GLOBALS['evicted'] = [];
$slot = '2099-01-02 10:00-10:30';
$other = '2099-01-09 10:00-10:30';
$pending = '2099-01-03 11:00-11:30';
$data = [
    Bookings::SLOTS_OPTION => [$slot, $other],
    Bookings::META_OPTION => [$slot => ['booker_name' => 'Synthetic Patient', 'booker_email' => 'synthetic@example.test', 'cancel_token' => 'old-cancel', 'waitlist_optout_token' => 'old-optout'], $other => ['booker_name' => 'Untouched']],
    TokenManager::PENDING_OPTION => ['old-confirm' => ['slot' => $pending, 'meta' => ['booker_name' => 'Pending Patient'], 'expires' => time() + 1800]],
    TokenManager::CANCEL_OPTION => ['old-cancel' => $slot, 'pending-cancel' => ['type' => 'pending', 'slot' => $pending]],
    BookingStorage::OPTIONS[4] => ['old-opening' => ['slot' => '2099-01-04 09:00-09:30', 'meta' => ['booker_email' => 'opening@example.test'], 'opens_at' => time() + 3600, 'closes_at' => time() + 86400]],
];
foreach ($data as $name => $value) {
    $wpdb->seed($name, serialize($value));
}
$wpdb->seed('public-settings', serialize(['title' => 'Public office hours']));
$before = $wpdb->rows();
$result = [];

if ($scenario === 'legacy') {
    BookingStorage::initialize();
    $result['phase'] = BookingStorage::status()['phase'];
    BookingStorage::set(Bookings::SLOTS_OPTION, [$slot, $other, 'new-slot']);
    $result['written'] = unserialize($wpdb->raw(Bookings::SLOTS_OPTION)) === [$slot, $other, 'new-slot'];
    $result['marker'] = BookingStorage::hasMixedState();
} elseif ($scenario === 'read') {
    BookingStorage::initialize();
    $result['phase'] = BookingStorage::status()['phase'];
    $result['same'] = true;
    foreach ($data as $name => $value) {
        $result['same'] = $result['same'] && BookingStorage::get($name) === $value;
    }
    BookingStorage::preflight();
    BookingStorage::verifyMixed();
    $result['strictRejected'] = failure(static fn() => BookingStorage::verify())['rejected'];
    $result['disabledMigrate'] = failure(static fn() => BookingStorage::migrate())['rejected'];
    $result['unchanged'] = $wpdb->rows() === $before;
} elseif ($scenario === 'new') {
    $list = $data[TokenManager::PENDING_OPTION];
    $list['private-confirm'] = ['slot' => '2099-01-06 11:00-11:30', 'meta' => ['booker_name' => 'New Secret'], 'expires' => time() + 1800];
    BookingStorage::set(TokenManager::PENDING_OPTION, $list);
    $id = BookingStorage::recordId('pending', 'private-confirm');
    $rows = $wpdb->rows();
    $name = 'rrze_appointment_v2_record_pending_' . $id;
    $result['oldUnchanged'] = !array_filter($before, static fn($row) => $row !== $rows[$row['option_name']]);
    $result['nonAutoloaded'] = $rows[$name]['autoload'] === 'no' && $rows[BookingStorage::MIXED_STATE_OPTION]['autoload'] === 'no' && $rows['rrze_appointment_record_index_v2']['autoload'] === 'no';
    $result['protected'] = !str_contains(json_encode($rows), 'New Secret') && !str_contains(json_encode($rows), 'private-confirm');
    $state = json_decode($rows[BookingStorage::MIXED_STATE_OPTION]['option_value'], true);
    $body = (new BookingCipher(EncryptionKey::fromBase64($key), $state['context']))->decrypt($rows[$name]['option_value'], $name);
    $result['versions'] = [$state['state_version'], $state['write_data_version'], $body['data_version'],
        json_decode(substr($rows[$name]['option_value'], strlen(BookingCipher::PREFIX)), true)['version']];
    BookingStorage::release();
    $result['persisted'] = BookingStorage::recordId('pending', 'private-confirm') === $id && BookingStorage::get(TokenManager::PENDING_OPTION) === $list;
} elseif ($scenario === 'lifecycle') {
    $newSlot = '2099-02-11 11:00-11:30';
    $meta = ['booker_name' => 'V2 User', 'booker_email' => 'v2@example.test'];
    $token = TokenManager::createPending($newSlot, $meta);
    $id = BookingStorage::recordId('pending', $token);
    $jobs = BookingStorage::managedJobs();
    $result['expiry'] = isset($jobs[$id]) && count($jobs[$id]['jobs']) === 1;
    $pendingCancel = TokenManager::getPending($token)['cancel_token'];
    $result['confirmed'] = TokenManager::completePending($token, $meta)['slot'] === $newSlot;
    BookingStorage::release();
    $result['idPreserved'] = $id !== null && BookingStorage::recordId('booking', $newSlot) === $id
        && BookingStorage::recordId('pending', $token) === null;
    $result['consumed'] = TokenManager::getPending($token) === null && TokenManager::validateCancelToken($pendingCancel) === null;
} elseif (in_array($scenario, ['transfer', 'replay', 'rebook', 'late-conflict', 'missing-index', 'missing-record', 'missing-marker', 'orphan-record', 'tampered-state', 'tampered-v2', 'unknown-version', 'cross-site'], true)) {
    $id = changePreference($slot);
    BookingStorage::release();
    $name = 'rrze_appointment_v2_record_booking_' . $id;
    $state = json_decode($wpdb->raw(BookingStorage::MIXED_STATE_OPTION), true);
    if ($scenario === 'transfer') {
        $result['separate'] = unserialize($wpdb->raw(Bookings::META_OPTION)) === [$other => $data[Bookings::META_OPTION][$other]];
        $result['relatedRemoved'] = !in_array($slot, unserialize($wpdb->raw(Bookings::SLOTS_OPTION)), true)
            && !isset(unserialize($wpdb->raw(TokenManager::CANCEL_OPTION))['old-cancel']);
        $result['otherSources'] = $wpdb->raw(TokenManager::PENDING_OPTION) === $before[TokenManager::PENDING_OPTION]['option_value'];
        $result['oldLinks'] = BookingStorage::get(TokenManager::CANCEL_OPTION)['old-cancel'] === $slot
            && BookingStorage::get(Bookings::META_OPTION)[$slot]['waitlist_optout_token'] === 'old-optout';
        $result['stable'] = changePreference($slot) === $id;
        BookingStorage::verifyMixed();
    } elseif ($scenario === 'replay' || $scenario === 'rebook') {
        if ($scenario === 'rebook') {
            $meta = BookingStorage::get(Bookings::META_OPTION);
            unset($meta[$slot]);
            $cancel = BookingStorage::get(TokenManager::CANCEL_OPTION);
            unset($cancel['old-cancel']);
            BookingStorage::setMany([Bookings::SLOTS_OPTION => [$other], Bookings::META_OPTION => $meta, TokenManager::CANCEL_OPTION => $cancel]);
            $meta[$slot] = ['booker_name' => 'New person'];
            BookingStorage::setMany([Bookings::SLOTS_OPTION => [$other, $slot], Bookings::META_OPTION => $meta]);
            $result['newId'] = BookingStorage::recordId('booking', $slot) !== $id;
        }
        BookingStorage::release();
        foreach ($data as $key => $old) {
            $wpdb->seed($key, serialize($old));
        }
        $meta = BookingStorage::get(Bookings::META_OPTION);
        $result['latest'] = $scenario === 'replay' ? $meta[$slot]['booker_waitlist'] : $meta[$slot]['booker_name'] === 'New person';
        $result['oldLink'] = isset(BookingStorage::get(TokenManager::CANCEL_OPTION)['old-cancel']);
        $result['strictRejected'] = failure(static fn() => BookingStorage::verify())['rejected'];
    } else {
        if ($scenario === 'late-conflict') {
            $cancel = unserialize($wpdb->raw(TokenManager::CANCEL_OPTION));
            $cancel['late-token'] = $slot;
            $wpdb->seed(TokenManager::CANCEL_OPTION, serialize($cancel));
        } elseif ($scenario === 'tampered-state') {
            $state['write_data_version'] = 1;
            $wpdb->seed(BookingStorage::MIXED_STATE_OPTION, json_encode($state));
        } elseif ($scenario === 'unknown-version') {
            $codec = new BookingCipher(EncryptionKey::fromBase64($key), $state['context']);
            $record = $codec->decrypt($wpdb->raw($name), $name);
            $record['data_version'] = 3;
            $wpdb->seed($name, $codec->encrypt($record, $name));
        } elseif ($scenario === 'tampered-v2') {
            $wpdb->seed($name, $wpdb->raw($name) . 'broken');
        } elseif ($scenario === 'cross-site') {
            $rows = $wpdb->rows();
            $wpdb->options = 'wp_2_options';
            foreach ($rows as $row) {
                $wpdb->seed($row['option_name'], $row['option_value']);
            }
        } else {
            if (in_array($scenario, ['missing-index', 'orphan-record'], true)) { dropRow('rrze_appointment_record_index_v2'); }
            if (in_array($scenario, ['missing-marker', 'orphan-record'], true)) { dropRow(BookingStorage::MIXED_STATE_OPTION); }
            if ($scenario === 'missing-record') { dropRow($name); }
        }
        $result = failure(static fn() => BookingStorage::initialize());
    }
} elseif (in_array($scenario, ['wrong-key', 'missing-key', 'missing-v1-option', 'foreign-v1-envelope', 'downgraded-v1', 'tamper-v1', 'v1', 'partial-v1'], true)) {
    seedV1($data, $key, $scenario === 'partial-v1');
    if ($scenario === 'v1' || $scenario === 'partial-v1') {
        $before = $wpdb->rows();
        BookingStorage::verifyMixed();
        $result['unchanged'] = $before === $wpdb->rows();
        changePreference($slot);
        BookingStorage::release();
        BookingStorage::verifyMixed();
        $result['linked'] = BookingStorage::get(TokenManager::CANCEL_OPTION)['old-cancel'] === $slot;
        $result['sourceFormats'] = str_starts_with($wpdb->raw(Bookings::META_OPTION), BookingCipher::PREFIX)
            === str_starts_with($before[Bookings::META_OPTION]['option_value'], BookingCipher::PREFIX)
            && str_starts_with($wpdb->raw(Bookings::SLOTS_OPTION), BookingCipher::PREFIX)
            && $wpdb->raw(BookingStorage::STATE_OPTION) === $before[BookingStorage::STATE_OPTION]['option_value'];
        $result['strict'] = !failure(static fn() => BookingStorage::verify())['rejected'];
        $result['untouched'] = $wpdb->raw(TokenManager::PENDING_OPTION) === $before[TokenManager::PENDING_OPTION]['option_value'];
    } else {
        if ($scenario === 'missing-v1-option') { dropRow(Bookings::META_OPTION); }
        if ($scenario === 'foreign-v1-envelope') { $wpdb->seed(Bookings::META_OPTION, $wpdb->raw(TokenManager::PENDING_OPTION)); }
        if ($scenario === 'tamper-v1') { $wpdb->seed(Bookings::META_OPTION, 'damaged'); }
        if ($scenario === 'downgraded-v1') {
            $state = json_decode($wpdb->raw(BookingStorage::STATE_OPTION), true);
            $state['completed'] = []; $state['phase'] = 'migrating';
            $wpdb->seed(BookingStorage::STATE_OPTION, json_encode($state));
        }
        $before = $wpdb->rows();
        $result = failure(static fn() => BookingStorage::initialize());
        $result['unchanged'] = $before === $wpdb->rows();
    }
} elseif ($scenario === 'rollback') {
    $wpdb->failWrite = 3;
    $result['failure'] = failure(static fn() => changePreference($slot))['rejected'];
    $result['unchanged'] = $before === $wpdb->rows();
    BookingStorage::release();
    $result['retry'] = strlen(changePreference($slot)) === 32;
} elseif ($scenario === 'readset') {
    BookingStorage::get(Bookings::META_OPTION);
    $wpdb->seed(TokenManager::PENDING_OPTION, serialize([]));
    $result = failure(static fn() => changePreference($slot));
    $result['preserved'] = $wpdb->raw(TokenManager::PENDING_OPTION) === serialize([]) && !BookingStorage::hasMixedState();
} else {
    if ($scenario === 'invalid-legacy') { $wpdb->seed(Bookings::META_OPTION, serialize([new stdClass()])); }
    if ($scenario === 'corrupt-legacy') { $wpdb->seed(Bookings::META_OPTION, 'bad'); }
    if ($scenario === 'lock-busy') { $wpdb->denyLock = true; }
    if ($scenario === 'database-error') { $wpdb->failReads = true; }
    if ($scenario === 'duplicate-slot') {
        $list = $data[TokenManager::PENDING_OPTION];
        $list['duplicate'] = ['slot' => $slot, 'expires' => time() + 500, 'meta' => []];
        $wpdb->seed(TokenManager::PENDING_OPTION, serialize($list));
    }
    $before = $wpdb->rows();
    $result = failure(static fn() => BookingStorage::initialize());
    $result['unchanged'] = $before === $wpdb->rows();
}
BookingStorage::release();
echo json_encode($result, JSON_THROW_ON_ERROR);
