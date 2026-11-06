<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Accesses raw option values without putting decrypted data in WordPress caches.
 *
 * A connection-owned, per-site lock spans the request, including read/modify/write
 * workflows involving several options. It is not a database transaction.
 */
final class OptionDatabase
{
    private string $lock;
    private bool $released = false;
    /** @var array<int, self> Only live locks are retained, even in long-running workers. */
    private static array $active = [];
    private static bool $shutdownRegistered = false;

    /**
     * Acquires a bounded MySQL/MariaDB advisory lock for the current options table.
     *
     * @param \wpdb $database WordPress database connection.
     * @param string $table Current site's options table.
     * @throws AppointmentException If another request holds the lock or SQL fails.
     */
    public function __construct(#[\SensitiveParameter] private readonly object $database, #[\SensitiveParameter] private readonly string $table)
    {
        $this->lock = 'rrze-appt:' . substr(hash('sha256', DB_NAME . ':' . $table), 0, 48);
        if ($this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s, 5)', $this->lock)) !== '1') {
            throw new AppointmentException('Appointment storage is busy or unavailable. Please try again.');
        }
        self::$active[spl_object_id($this)] = $this;
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'releaseAll']);
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Detects a lost lock, including reconnects, before accessing protected state.
     */
    public function assertOwnership(): void
    {
        if ($this->released || $this->database->get_var($this->database->prepare(
            'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->lock
        )) !== '1') {
            throw new AppointmentException('The appointment storage lock was lost. Please try again.');
        }
    }

    /**
     * Reads the database directly; absence and database failure are distinct.
     *
     * @return string|null Raw stored value, or null only when the row is absent.
     */
    public function read(#[\SensitiveParameter] string $name): ?string
    {
        $this->assertOwnership();
        $value = $this->database->get_var($this->database->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s', $this->table, $name
        ));
        if ($this->database->last_error !== '') {
            throw new AppointmentException('Appointment storage could not be read.');
        }
        return $value;
    }

    /**
     * Replaces exactly the observed value, disabling autoload and evicting caches.
     *
     * The predicate contains a digest, never a copy of the old plaintext payload.
     * This also detects writes by code that does not honor our advisory lock.
     *
     * @param string $name Option name.
     * @param string|null $previous Observed raw value; null requests insertion.
     * @param string $next New raw value.
     * @return bool False on a concurrent change; SQL errors throw.
     */
    public function replace(#[\SensitiveParameter] string $name, #[\SensitiveParameter] ?string $previous, #[\SensitiveParameter] string $next): bool
    {
        $this->assertOwnership();
        $query = $previous === null
            ? $this->database->prepare(
                'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
                $this->table, $name, $next, 'no'
            )
            : $this->database->prepare(
                'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s',
                $this->table, $next, 'no', $name, hash('sha256', $previous)
            );
        try {
            if ($this->database->query($query) === false) {
                throw new AppointmentException('Appointment storage could not be written.');
            }
            // Also verify no-op writes; a failed verification must still evict caches.
            return $this->read($name) === $next;
        } finally {
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    /**
     * Requires transactional tables without changing the database schema.
     *
     * @throws AppointmentException When the options table is not InnoDB or cannot be inspected.
     */
    public function assertTransactional(): void
    {
        $this->assertOwnership();
        $engine = $this->database->get_var($this->database->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $this->table
        ));
        if ($this->database->last_error !== '' || !is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
            throw new AppointmentException('Atomic appointment confirmation requires an InnoDB options table.');
        }
        if (!(($this->database->dbh ?? null) instanceof \mysqli)) {
            throw new AppointmentException('Atomic appointment confirmation requires a native mysqli connection.');
        }
    }

    /**
     * Commits related replacements together, verifying every byte before commit.
     *
     * Native mysqli calls deliberately bypass wpdb's reconnect-and-replay behavior.
     * A connection loss must never replay one statement outside its transaction.
     * Prepared SQL and plaintext digests are constructed before starting the transaction.
     * Existing caller transactions are rejected rather than implicitly committed.
     *
     * @param array<string, string|null> $previous Observed raw values, null for absent rows.
     * @param array<string, string> $next Complete replacements for the same option names.
     * @throws AppointmentException On unsupported tables, conflicts or any database failure.
     */
    public function replaceMany(#[\SensitiveParameter] array $previous, #[\SensitiveParameter] array $next): void
    {
        if (!$next || array_keys($previous) !== array_keys($next)) {
            throw new AppointmentException('Invalid atomic appointment update.');
        }
        $this->assertTransactional();
        $writes = $reads = [];
        foreach ($next as $name => $value) {
            $writes[$name] = $previous[$name] === null
                ? $this->database->prepare(
                    'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
                    $this->table, $name, $value, 'no'
                )
                : $this->database->prepare(
                    'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s',
                    $this->table, $value, 'no', $name, hash('sha256', $previous[$name])
                );
            $reads[$name] = $this->database->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE', $this->table, $name
            );
        }
        $ownership = $this->database->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->lock);
        $connection = $this->database->dbh;
        if (!$connection instanceof \mysqli) {
            throw new AppointmentException('Atomic appointment confirmation requires a native mysqli connection.');
        }
        $started = false;
        try {
            if ($this->nativeValue($connection, $ownership) !== '1'
                || $this->nativeValue($connection, 'SELECT @@session.autocommit') !== '1') {
                throw new AppointmentException('The appointment transaction connection is unavailable.');
            }
            // SAVEPOINT is a no-op outside a transaction; RELEASE then reports 1305.
            // Inside a caller transaction both succeed, letting us reject it safely.
            $savepoint = 'rrze_appt_probe_' . bin2hex(random_bytes(8));
            $this->nativeQuery($connection, 'SAVEPOINT ' . $savepoint);
            try {
                $released = @mysqli_query($connection, 'RELEASE SAVEPOINT ' . $savepoint);
                $error = mysqli_errno($connection);
            } catch (\mysqli_sql_exception $exception) {
                $released = false;
                $error = $exception->getCode();
            }
            if ($released !== false || $error !== 1305) {
                throw new AppointmentException('Appointment confirmation cannot join an existing transaction.');
            }
            $this->nativeQuery($connection, 'START TRANSACTION');
            $started = true;
            foreach ($next as $name => $value) {
                if ($this->nativeValue($connection, $ownership) !== '1'
                    || $this->nativeValue($connection, $reads[$name]) !== $previous[$name]) {
                    throw new AppointmentException('Appointment data changed concurrently. Please reload and try again.');
                }
                $this->nativeQuery($connection, $writes[$name]);
                if ($this->nativeValue($connection, $reads[$name]) !== $value) {
                    throw new AppointmentException('Atomic appointment data could not be verified.');
                }
            }
            if ($this->nativeValue($connection, $ownership) !== '1') {
                throw new AppointmentException('The appointment storage lock was lost. Please try again.');
            }
            $this->nativeQuery($connection, 'COMMIT');
            $started = false;
        } catch (\Throwable $exception) {
            if ($started) {
                try {
                    $this->nativeQuery($connection, 'ROLLBACK');
                } catch (\Throwable) {
                    // A lost connection rolls back server-side; commit acknowledgement may be uncertain.
                }
            }
            throw AppointmentException::fromThrowable($exception);
        } finally {
            foreach (array_keys($next) as $name) {
                wp_cache_delete($name, 'options');
            }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    /**
     * Executes on the captured connection without reconnecting or exposing SQL errors.
     *
     * @param \mysqli $connection Connection that owns the storage lock and transaction.
     * @param string $query Prepared SQL, potentially containing protected data.
     * @return \mysqli_result|bool Native result; failures always throw a safe exception.
     */
    private function nativeQuery(#[\SensitiveParameter] \mysqli $connection, #[\SensitiveParameter] string $query): \mysqli_result|bool
    {
        try {
            $result = @mysqli_query($connection, $query);
            if ($result === false) {
                throw new AppointmentException('The appointment transaction could not be completed.');
            }
            return $result;
        } catch (\Throwable) {
            throw new AppointmentException('The appointment transaction could not be completed.');
        }
    }

    /**
     * Reads one native scalar and promptly releases the result buffer.
     *
     * @return string|null First column, or null for an absent row/SQL NULL.
     */
    private function nativeValue(#[\SensitiveParameter] \mysqli $connection, #[\SensitiveParameter] string $query): ?string
    {
        $result = $this->nativeQuery($connection, $query);
        if (!$result instanceof \mysqli_result) {
            throw new AppointmentException('The appointment transaction could not be verified.');
        }
        try {
            $value = $result->fetch_row()[0] ?? null;
            return $value === null ? null : (string) $value;
        } finally {
            $result->free();
        }
    }

    /**
     * Removes at most 100 expired slot locks, including legacy MD5 lock names.
     *
     * The old names allow guessing the appointment time. Only the two exact
     * legacy/current formats and expired timestamp values qualify; live/foreign locks
     * remain untouched. Site context must match this database's options table.
     */
    public function pruneExpiredLocks(): void
    {
        $this->assertOwnership();
        $pattern = '^rrze_appointment_(waitlist_lock|opening_claim)_([a-f0-9]{32}|v2_[a-f0-9]{64})$';
        $before = time() - 5 * MINUTE_IN_SECONDS;
        $names = $this->database->get_col($this->database->prepare(
            "SELECT option_name FROM %i WHERE (option_name LIKE 'rrze_appointment_waitlist_lock_%%'
             OR option_name LIKE 'rrze_appointment_opening_claim_%%')
             AND option_name REGEXP %s AND option_value REGEXP '^[0-9]+$'
             AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_name LIMIT 100",
            $this->table, $pattern, $before
        ));
        if ($this->database->last_error !== '') {
            throw new AppointmentException('Expired appointment locks could not be checked.');
        }
        if (!$names) {
            return;
        }
        $this->assertOwnership();
        try {
            if ($this->database->query($this->database->prepare(
                'DELETE FROM %i WHERE option_name IN (' . implode(',', array_fill(0, count($names), '%s')) . ")
                 AND option_value REGEXP '^[0-9]+$' AND CAST(option_value AS UNSIGNED) < %d",
                $this->table, ...[...$names, $before]
            )) === false) {
                throw new AppointmentException('Expired appointment locks could not be removed.');
            }
        } finally {
            foreach ($names as $name) {
                wp_cache_delete($name, 'options');
            }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    /**
     * Releases only this connection's lock; also safe when called again at shutdown.
     */
    public function release(): void
    {
        if (!$this->released) {
            $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $this->lock));
            $this->released = true;
            unset(self::$active[spl_object_id($this)]);
        }
    }

    /**
     * Releases remaining request locks through one shutdown callback, not one per site.
     */
    public static function releaseAll(): void
    {
        foreach (self::$active as $database) {
            $database->release();
        }
    }
}
