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
    public function __construct(private readonly object $database, private readonly string $table)
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
    public function read(string $name): ?string
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
    public function replace(string $name, #[\SensitiveParameter] ?string $previous, #[\SensitiveParameter] string $next): bool
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
