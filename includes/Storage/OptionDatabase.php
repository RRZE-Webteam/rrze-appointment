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
