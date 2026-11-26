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
    private readonly ?PrimaryConnection $primary;
    /** @var \wpdb|PrimaryConnection Formats and executes SQL for the selected connection. */
    private readonly object $sql;
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
        $this->primary = PrimaryConnection::forTable($database, $table);
        $this->sql = $this->primary ?? $database;
        $this->lock = 'rrze-appt:' . substr(hash('sha256', DB_NAME . ':' . $table), 0, 48);
        if ($this->lockValue('SELECT GET_LOCK(%s, 5)') !== '1') {
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
        $ownsLock = !$this->released && ($this->primary !== null
            ? $this->primary->ownsLock($this->lock)
            : $this->lockValue('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()') === '1');
        if (!$ownsLock) {
            throw new AppointmentException('The appointment storage lock was lost. Please try again.');
        }
    }

    /**
     * Runs a single-argument advisory lock function on this store's connection.
     *
     * @param string $sql SQL template with the lock name as its only placeholder.
     * @return string|null Database lock function result.
     */
    private function lockValue(string $sql): ?string
    {
        $query = $this->sql->prepare($sql, $this->lock);
        return $this->primary !== null ? $this->primary->value($query) : $this->database->get_var($query);
    }

    /**
     * Reads the database directly; absence and database failure are distinct.
     *
     * @param string $name Option name in this site's table.
     * @return string|null Raw stored value, or null only when the row is absent.
     */
    public function read(#[\SensitiveParameter] string $name): ?string
    {
        $this->assertOwnership();
        $query = $this->sql->prepare('SELECT option_value FROM %i WHERE option_name = %s', $this->table, $name);
        if ($this->primary !== null) {
            return $this->primary->value($query);
        }
        // wpdb::get_var() also returns null for an existing empty string. A
        // damaged empty ciphertext must be distinguishable from a missing option.
        $row = $this->database->get_row($query, 'ARRAY_A');
        if ($this->database->last_error !== '') {
            throw new AppointmentException('Appointment storage could not be read.');
        }
        return $row === null ? null : $row['option_value'];
    }

    /**
     * Reads the physical V2 inventory, including rows missing from a damaged index.
     *
     * A single indexed prefix query also detects records when both marker and index
     * are missing. Never place ciphertext or decrypted business data in option caches.
     *
     * @return array<string, string> Option name to raw value for this site's records.
     */
    public function recordRows(): array
    {
        $this->assertOwnership();
        $query = $this->sql->prepare(
            "SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ESCAPE '!' ORDER BY option_name",
            $this->table, 'rrze!_appointment!_v2!_record!_%'
        );
        if ($this->primary !== null) {
            $rows = $this->primary->rows($query);
        } else {
            $rows = $this->database->get_results($query, 'ARRAY_A');
            if ($this->database->last_error !== '' || !is_array($rows)) {
                throw new AppointmentException('Appointment record inventory could not be read.');
            }
        }
        return array_column($rows, 'option_value', 'option_name');
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
            ? $this->sql->prepare(
                'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
                $this->table, $name, $next, 'no'
            )
            : $this->sql->prepare(
                'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s',
                $this->table, $next, 'no', $name, hash('sha256', $previous)
            );
        try {
            if ($this->sql->query($query) === false) {
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
        $query = $this->sql->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $this->table
        );
        $engine = $this->primary !== null ? $this->primary->value($query) : $this->database->get_var($query);
        if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
            throw new AppointmentException('Atomic appointment confirmation requires an InnoDB options table.');
        }
        if ($this->primary === null && !(($this->database->dbh ?? null) instanceof \mysqli)) {
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
     * @param array<string, string|null> $previous Read set; includes unchanged rows used for the decision.
     * @param array<string, string|null> $next Changed subset; null deletes the verified existing row.
     * @throws AppointmentException On unsupported tables, conflicts or any database failure.
     */
    public function replaceMany(#[\SensitiveParameter] array $previous, #[\SensitiveParameter] array $next): void
    {
        if (!$next || array_diff_key($next, $previous)) {
            throw new AppointmentException('Invalid atomic appointment update.');
        }
        $this->assertTransactional();
        $writes = $reads = [];
        ksort($previous);
        foreach ($previous as $name => $observed) {
            $reads[$name] = $this->sql->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE', $this->table, $name
            );
        }
        foreach ($next as $name => $value) {
            if ($value === null) {
                if ($previous[$name] === null) {
                    throw new AppointmentException('Invalid atomic appointment deletion.');
                }
                $writes[$name] = $this->sql->prepare(
                    'DELETE FROM %i WHERE option_name = %s AND SHA2(option_value, 256) = %s',
                    $this->table, $name, hash('sha256', $previous[$name])
                );
                continue;
            }
            $writes[$name] = $previous[$name] === null
                ? $this->sql->prepare(
                    'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
                    $this->table, $name, $value, 'no'
                )
                : $this->sql->prepare(
                    'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s',
                    $this->table, $value, 'no', $name, hash('sha256', $previous[$name])
                );
        }
        $ownership = $this->sql->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->lock);
        $connection = $this->primary ?? new PrimaryConnection($this->database, $this->database->dbh);
        $started = false;
        try {
            if ($connection->value($ownership) !== '1'
                || $connection->value('SELECT @@session.autocommit') !== '1') {
                throw new AppointmentException('The appointment transaction connection is unavailable.');
            }
            $connection->assertNoTransaction();
            $connection->query('START TRANSACTION');
            $started = true;
            foreach ($previous as $name => $observed) {
                if ($connection->value($reads[$name]) !== $observed) {
                    throw new AppointmentException('Appointment data changed concurrently. Please reload and try again.');
                }
            }
            foreach ($next as $name => $value) {
                if ($connection->value($ownership) !== '1') {
                    throw new AppointmentException('The appointment storage lock was lost. Please try again.');
                }
                $connection->query($writes[$name]);
                if ($connection->value($reads[$name]) !== $value) {
                    throw new AppointmentException('Atomic appointment data could not be verified.');
                }
            }
            if ($connection->value($ownership) !== '1') {
                throw new AppointmentException('The appointment storage lock was lost. Please try again.');
            }
            $connection->query('COMMIT');
            $started = false;
        } catch (\Throwable $exception) {
            if ($started) {
                try {
                    $connection->query('ROLLBACK');
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
        $query = $this->sql->prepare(
            "SELECT option_name FROM %i WHERE (option_name LIKE 'rrze_appointment_waitlist_lock_%%'
             OR option_name LIKE 'rrze_appointment_opening_claim_%%')
             AND option_name REGEXP %s AND option_value REGEXP '^[0-9]+$'
             AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_name LIMIT 100",
            $this->table, $pattern, $before
        );
        if ($this->primary !== null) {
            $names = array_column($this->primary->rows($query), 'option_name');
        } else {
            $names = $this->database->get_col($query);
            if ($this->database->last_error !== '') {
                throw new AppointmentException('Expired appointment locks could not be checked.');
            }
        }
        if (!$names) {
            return;
        }
        $this->assertOwnership();
        try {
            if ($this->sql->query($this->sql->prepare(
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
            try {
                $this->lockValue('SELECT RELEASE_LOCK(%s)');
            } catch (\Throwable) {
                // A disconnected session loses its locks. Continue releasing other sites at shutdown.
            }
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
