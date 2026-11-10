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

/** Executes the production transaction's statements against the isolated in-memory database. */
function mysqli_query(\mysqli $connection, string $sql): bool|\mysqli_result
{
    global $wpdb;
    if (str_starts_with($sql, 'SAVEPOINT ')) {
        return true;
    }
    if (str_starts_with($sql, 'RELEASE SAVEPOINT ')) {
        return false;
    }
    if ($sql === 'SELECT @@session.autocommit') {
        return new FixtureScalar('1');
    }
    if (str_starts_with($sql, 'SELECT ')) {
        return new FixtureScalar($wpdb->get_var(str_replace(' FOR UPDATE', '', $sql)));
    }
    if (in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
        return $wpdb->db->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql);
    }
    return $wpdb->query($sql) !== false;
}

/** Reports the expected absent savepoint outside a transaction. */
function mysqli_errno(\mysqli $connection): int { return 1305; }
