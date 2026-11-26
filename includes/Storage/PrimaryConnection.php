<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/** Executes storage SQL on one mysqli session without WordPress reconnecting or replaying it. */
final class PrimaryConnection
{
    private ?string $schema = null;

    /**
     * Captures a handle already selected by WordPress for use in a native transaction.
     *
     * @param \wpdb $database WordPress SQL formatter.
     * @param \mysqli $handle Session to use for every statement.
     */
    public function __construct(
        #[\SensitiveParameter] private readonly object $database,
        #[\SensitiveParameter] private readonly \mysqli $handle
    ) {}

    /**
     * Asks HyperDB for this table's writer before acquiring a connection-owned lock.
     *
     * @param \wpdb $database WordPress database object, possibly a HyperDB drop-in.
     * @param string $table Physical WordPress table used to select the dataset.
     * @param string $keyColumn Primary key used in the routing hint for this table.
     * @return self|null Pinned writer, or null for the ordinary wpdb path.
     * @throws AppointmentException When HyperDB cannot supply a native writer and schema.
     */
    public static function forTable(#[\SensitiveParameter] object $database, string $table, string $keyColumn = 'option_id'): ?self
    {
        if (!$database instanceof \hyperdb) {
            return null;
        }
        try {
            // db_connect inspects the query to choose a writer. It does not execute the UPDATE.
            $route = $database->prepare('UPDATE %i SET %i = %i WHERE 1 = 0', $table, $keyColumn, $keyColumn);
            $handle = $database->db_connect($database->remove_placeholder_escape($route));
            if ($handle instanceof \mysqli) {
                $primary = new self($database, $handle);
                $primary->schema = $primary->value('SELECT DATABASE()');
                if ($primary->schema !== null && $primary->schema !== '') {
                    return $primary;
                }
            }
        } catch (\Throwable) {
            // Drop-ins may throw connection errors that contain server or credential details.
        }
        throw new AppointmentException('The appointment primary database connection is unavailable.');
    }

    /**
     * Formats SQL using the pinned handle's character set, preserving HyperDB's active handle.
     *
     * @param string $format SQL template for wpdb::prepare().
     * @param mixed ...$args Template arguments.
     * @return string Prepared SQL including WordPress placeholder escapes.
     * @throws AppointmentException If the pinned handle is no longer usable for escaping.
     */
    public function prepare(string $format, #[\SensitiveParameter] mixed ...$args): string
    {
        $previous = $this->database->dbh;
        try {
            $this->database->dbh = $this->handle;
            return $this->database->prepare($format, ...$args);
        } catch (\Throwable) {
            throw new AppointmentException('The appointment transaction connection is unavailable.');
        } finally {
            $this->database->dbh = $previous;
        }
    }

    /**
     * Verifies both the named lock and the selected schema on a routed connection.
     * HyperDB can select another schema on a pooled handle between storage operations.
     *
     * @param string $name Name of the advisory lock.
     * @return bool Whether the session still owns the lock in the expected database context.
     */
    public function ownsLock(string $name): bool
    {
        return $this->value($this->prepare(
            'SELECT IS_USED_LOCK(%s) = CONNECTION_ID() AND BINARY DATABASE() = BINARY %s',
            $name, $this->schema
        )) === '1';
    }

    /**
     * Rejects a caller's open transaction before START TRANSACTION could implicitly commit it.
     *
     * @throws AppointmentException When a savepoint survives outside the expected autocommit context.
     */
    public function assertNoTransaction(): void
    {
        // SAVEPOINT is a no-op outside a transaction; RELEASE then reports 1305.
        // Inside a caller transaction both succeed, letting us reject it safely.
        $savepoint = 'rrze_appt_probe_' . bin2hex(random_bytes(8));
        $this->query('SAVEPOINT ' . $savepoint);
        try {
            $released = @mysqli_query($this->handle, 'RELEASE SAVEPOINT ' . $savepoint);
            $error = mysqli_errno($this->handle);
        } catch (\mysqli_sql_exception $exception) {
            $released = false;
            $error = $exception->getCode();
        }
        if ($released !== false || $error !== 1305) {
            throw new AppointmentException('Appointment confirmation cannot join an existing transaction.');
        }
    }

    /**
     * Executes SQL directly, restoring percent signs normally restored by wpdb's query filter.
     *
     * @param string $sql Prepared SQL which can contain protected values.
     * @return \mysqli_result|bool Result of a successful query.
     * @throws AppointmentException On failure, without exposing SQL or native diagnostics.
     */
    public function query(#[\SensitiveParameter] string $sql): \mysqli_result|bool
    {
        try {
            $result = @mysqli_query($this->handle, $this->database->remove_placeholder_escape($sql));
            if ($result !== false) {
                return $result;
            }
        } catch (\Throwable) {
            // A closed handle can also throw, including during rollback.
        }
        throw new AppointmentException('The appointment transaction could not be completed.');
    }

    /**
     * Reads one native scalar and promptly releases the result buffer.
     *
     * @param string $sql Prepared SELECT statement.
     * @return string|null First column, preserving empty strings and SQL NULL.
     */
    public function value(#[\SensitiveParameter] string $sql): ?string
    {
        $result = $this->query($sql);
        if (!$result instanceof \mysqli_result) {
            throw new AppointmentException('The appointment transaction could not be verified.');
        }
        try {
            $row = $result->fetch_row();
            if ($row === false) {
                throw new AppointmentException('The appointment transaction could not be verified.');
            }
            $value = $row[0] ?? null;
            return $value === null ? null : (string) $value;
        } finally {
            $result->free();
        }
    }

    /**
     * Reads associative rows from the pinned writer and releases the result buffer.
     *
     * @param string $sql Prepared SELECT statement.
     * @return array<int, array<string, mixed>> Rows in query order.
     */
    public function rows(#[\SensitiveParameter] string $sql): array
    {
        $result = $this->query($sql);
        if (!$result instanceof \mysqli_result) {
            throw new AppointmentException('Appointment storage could not be read.');
        }
        try {
            $rows = [];
            while (($row = $result->fetch_assoc()) !== null) {
                if ($row === false) {
                    throw new AppointmentException('Appointment storage could not be read.');
                }
                $rows[] = $row;
            }
            return $rows;
        } finally {
            $result->free();
        }
    }
}
