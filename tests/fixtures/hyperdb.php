<?php

use RRZE\Appointment\Storage\OptionDatabase;

define('RRZE_APPOINTMENT_STORAGE_TEST_BOOTSTRAP_ONLY', true);
require __DIR__ . '/booking-storage.php';

/** Routes ordinary SELECTs to a stale replica and writes to the primary for the named site. */
final class hyperdb
{
    public string $blogs = 'wp_blogs';
    public string $options = 'wp_options';
    public BookingTestDatabase $primary;
    public BookingTestDatabase $secondPrimary;
    public BookingTestDatabase $replica;
    public mysqli $dbh;
    public string $last_error = '';
    public bool $writerUnavailable = false;
    public ?mysqli $disconnected = null;
    /** @var array<string, bool> Tables whose writer was selected earlier in this request. */
    private array $writtenTables = [];

    /** Creates separate database contents, named locks and native handles for each endpoint. */
    public function __construct()
    {
        $this->primary = new BookingTestDatabase();
        $this->secondPrimary = new BookingTestDatabase();
        $this->secondPrimary->options = 'wp_2_options';
        $this->replica = new BookingTestDatabase();
        $this->dbh = $this->replica->dbh;
    }

    /** Applies wpdb's percent-sign protection to prepared SQL before query filters run. */
    public function prepare(string $query, mixed ...$args): string
    {
        if ($this->dbh === $this->disconnected && str_contains($query, '%s')) {
            throw new Error('Cannot escape a string on a closed fixture handle.');
        }
        return str_replace('%', '{fixture-percent}', $this->primary->prepare($query, ...$args));
    }

    /** Removes the placeholder marker as wpdb does immediately before executing SQL. */
    public function remove_placeholder_escape(string $query): string
    {
        return str_replace('{fixture-percent}', '%', $query);
    }

    /** Classifies SQL by its first keyword, including SELECT GET_LOCK, as in HyperDB 1.9. */
    public function is_write_query(string $query): bool
    {
        return !preg_match('/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\s/i', ltrim($query, "\r\n\t ("));
    }

    /**
     * Selects a native handle without executing the supplied routing statement.
     *
     * @param string $query Statement whose table and intent select the connection.
     * @return mysqli|false The selected session, or false when its writer is unavailable.
     */
    public function db_connect(string $query): mysqli|false
    {
        preg_match('/(?:FROM|UPDATE)\s+["`]?([\w]+)/i', $query, $match);
        $table = $match[1] ?? '';
        $write = $this->is_write_query($query);
        if ($write && $table !== '') {
            $this->writtenTables[$table] = true;
        }
        // An installation can route metadata to the primary independently of ordinary reads.
        if (!$write && !isset($this->writtenTables[$table]) && !str_contains($query, 'information_schema.TABLES')) {
            return $this->replica->dbh;
        }
        if ($this->writerUnavailable) {
            return false;
        }
        return str_contains($query, 'wp_2_options') ? $this->secondPrimary->dbh : $this->primary->dbh;
    }

    /**
     * Resolves native calls by the supplied handle even if an unrelated query changed wpdb's handle.
     *
     * @param mysqli $connection Captured handle passed to the native query.
     * @throws RuntimeException When the original session was disconnected.
     */
    public function sessionFor(mysqli $connection): BookingTestDatabase
    {
        if ($connection === $this->disconnected) {
            throw new RuntimeException('Disconnected synthetic session.');
        }
        foreach ([$this->primary, $this->secondPrimary, $this->replica] as $session) {
            if ($connection === $session->dbh) {
                return $session;
            }
        }
        throw new LogicException('Unknown fixture connection.');
    }

    /** Runs an independent WordPress SELECT and updates its most recently used handle. */
    public function get_var(string $query): ?string
    {
        $this->dbh = $this->db_connect($query);
        return $this->sessionFor($this->dbh)->get_var($this->remove_placeholder_escape($query));
    }

    /** Allows an unpinned option read to observe the replica's deliberately stale row. */
    public function get_row(string $query, string $format): ?array
    {
        $this->dbh = $this->db_connect($query);
        return $this->sessionFor($this->dbh)->get_row($this->remove_placeholder_escape($query), $format);
    }
}

$wpdb = new hyperdb();
$GLOBALS['evicted'] = [];
$scenario = $argv[1] ?? '';
$result = [];
if ($scenario === 'affinity') {
    $name = 'rrze_appointment_v2_record_synthetic';
    $wpdb->primary->seed($name, 'current 50%');
    $wpdb->replica->seed($name, 'stale');
    foreach ([$wpdb->primary, $wpdb->replica] as $session) {
        $session->db->exec('CREATE TABLE wp_posts (post_title TEXT)');
        $title = $session === $wpdb->primary ? 'Current title' : 'Replica title';
        $session->db->exec($session->prepare('INSERT INTO wp_posts VALUES (%s)', $title));
    }
    $store = new OptionDatabase($wpdb, 'wp_options');
    $otherSite = new OptionDatabase($wpdb, 'wp_2_options');
    $result['heldLocks'] = [count($wpdb->primary->activeLocks), count($wpdb->secondPrimary->activeLocks), count($wpdb->replica->activeLocks)];
    $result['ordinaryRead'] = $wpdb->get_var('SELECT post_title FROM wp_posts');
    $result['protectedRead'] = $store->read($name);
    $store->replaceMany([$name => 'current 50%'], [$name => 'booked 100%']);
    $result['inventory'] = $store->recordRows();
    $result['otherSiteInserted'] = $otherSite->replace($name, null, 'site two');
    $expired = 'rrze_appointment_waitlist_lock_' . md5('synthetic');
    $wpdb->primary->seed($expired, (string) (time() - 3600));
    $store->pruneExpiredLocks();
    $result['expired'] = $wpdb->primary->raw($expired);
    $result['values'] = [$wpdb->primary->raw($name), $wpdb->secondPrimary->raw($name), $wpdb->replica->raw($name)];
    $result['ordinaryHandleRestored'] = $wpdb->dbh === $wpdb->replica->dbh;
    OptionDatabase::releaseAll();
    $result['released'] = !$wpdb->primary->activeLocks && !$wpdb->secondPrimary->activeLocks && !$wpdb->replica->activeLocks;
} elseif ($scenario === 'rollback') {
    $store = new OptionDatabase($wpdb, 'wp_options');
    $wpdb->primary->failWrite = 2;
    $result['failure'] = failure(static fn() => $store->replaceMany(['first' => null, 'second' => null], ['first' => 'one', 'second' => 'two']));
    $result['primary'] = $wpdb->primary->rows();
    $result['replica'] = $wpdb->replica->rows();
    $result['attemptedWrites'] = $wpdb->primary->writeCount;
    $store->release();
    $result['released'] = !$wpdb->primary->activeLocks;
} elseif ($scenario === 'disconnect') {
    $store = new OptionDatabase($wpdb, 'wp_options');
    $wpdb->disconnected = $wpdb->primary->dbh;
    $result['failure'] = failure(static fn() => $store->replaceMany(['synthetic' => null], ['synthetic' => 'new']));
    $store->release();
    $result['writes'] = $wpdb->primary->writeCount + $wpdb->replica->writeCount + $wpdb->secondPrimary->writeCount;
    $result['replicaLocks'] = $wpdb->replica->locks;
    $result['ordinaryHandleRestored'] = $wpdb->dbh === $wpdb->replica->dbh;
} elseif ($scenario === 'schema-changed') {
    $store = new OptionDatabase($wpdb, 'wp_options');
    $wpdb->primary->schema = 'other_site_database';
    $result['failure'] = failure(static fn() => $store->replace('synthetic', null, 'new'));
    $result['writes'] = $wpdb->primary->writeCount + $wpdb->replica->writeCount;
    $store->release();
} elseif ($scenario === 'no-primary') {
    $wpdb->writerUnavailable = true;
    $result['failure'] = failure(static fn() => new OptionDatabase($wpdb, 'wp_options'));
    $result['replicaLocks'] = $wpdb->replica->locks;
} elseif ($scenario === 'network-maintenance' || $scenario === 'network-schema-changed') {
    $result = require __DIR__ . '/hyperdb-network.php';
} else {
    throw new InvalidArgumentException('Unknown test scenario.');
}

echo json_encode($result, JSON_THROW_ON_ERROR);
