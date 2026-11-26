<?php

namespace RRZE\Appointment\Storage;

if (PHP_SAPI !== 'cli' || !defined('DB_NAME') || DB_NAME !== 'isolated-appointment-tests') {
    exit;
}

/** Minimal result for one selected SQLite scalar; the native InnoDB tests use real mysqli. */
final class FixtureScalar extends \mysqli_result
{
    /** Remembers a scalar value or an absent row. */
    public function __construct(private readonly ?string $value) {}
    /** Returns the only row for the production read-set verifier. */
    public function fetch_row(): array|null|false { return $this->value === null ? null : [$this->value]; }
    /** The scalar was already copied out of SQLite. */
    public function free(): void {}
}

/** Exposes SQLite result rows through the mysqli interface used by the storage layer. */
final class FixtureRows extends \mysqli_result
{
    /** Keeps the cursor returned by the selected fixture session. */
    public function __construct(private readonly \SQLite3Result $rows) {}

    /** Reads one row with the database column names used by option inventory queries. */
    public function fetch_assoc(): array|null|false
    {
        return $this->rows->fetchArray(SQLITE3_ASSOC) ?: null;
    }

    /** Returns one positional row for scalar reads and read-set verification. */
    public function fetch_row(): array|null|false
    {
        return $this->rows->fetchArray(SQLITE3_NUM) ?: null;
    }

    /** Releases the fixture cursor after the production caller consumes it. */
    public function free(): void
    {
        $this->rows->finalize();
    }
}

/** Executes the production transaction's statements against the isolated in-memory database. */
function mysqli_query(\mysqli $connection, string $sql): bool|\mysqli_result
{
    global $wpdb;
    $session = $wpdb instanceof \hyperdb ? $wpdb->sessionFor($connection) : $wpdb;
    if (str_starts_with($sql, 'SAVEPOINT ')) {
        return true;
    }
    if (str_starts_with($sql, 'RELEASE SAVEPOINT ')) {
        return false;
    }
    if ($sql === 'SELECT @@session.autocommit') {
        return new FixtureScalar('1');
    }
    if (str_contains($sql, 'information_schema.TABLES') || preg_match('/^SELECT (?:DATABASE|GET_LOCK|IS_USED_LOCK|RELEASE_LOCK)\(/i', $sql)) {
        return new FixtureScalar($session->get_var($sql));
    }
    if (str_starts_with($sql, 'SELECT ')) {
        return new FixtureRows($session->db->query(str_replace(' FOR UPDATE', '', $sql)));
    }
    if (in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
        return $session->db->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql);
    }
    return $session->query($sql) !== false;
}

/** Reports the expected absent savepoint outside a transaction. */
function mysqli_errno(\mysqli $connection): int { return 1305; }
