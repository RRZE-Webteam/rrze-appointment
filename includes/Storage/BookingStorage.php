<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Owns the five booking stores, their encryption state, and resumable migration.
 *
 * Plaintext exists only in request-local memory. A configured key automatically
 * starts or resumes migration before any booking workflow can access the stores.
 */
final class BookingStorage
{
    public const STATE_OPTION = 'rrze_appointment_storage_state';
    public const OPTIONS = [
        'rrze_appointment_booked_slots',
        'rrze_appointment_booked_slots_meta',
        'rrze_appointment_pending_slots',
        'rrze_appointment_cancel_tokens',
        'rrze_appointment_booking_opening_notifications',
    ];

    /** @var array<string, self> Request-local instances, isolated by site table. */
    private static array $sites = [];
    private OptionDatabase $database;
    private ?EncryptionKey $key = null;
    private ?BookingCipher $cipher = null;
    private ?array $state = null;
    private ?string $rawState = null;
    private array $raw = [];
    private array $data = [];
    private bool $blocked = false;
    private bool $migrating = false;

    /**
     * Locks and validates every store before any workflow can change booking data.
     */
    private function __construct(#[\SensitiveParameter] object $database, #[\SensitiveParameter] string $table)
    {
        $this->database = new OptionDatabase($database, $table);
        try {
            $this->rawState = $this->database->read(self::STATE_OPTION);
            if (defined(EncryptionKey::CONFIGURATION_CONSTANT) || $this->rawState !== null) {
                $this->key = EncryptionKey::fromConfiguration();
            }
            if ($this->rawState !== null) {
                $this->state = $this->decodeState($this->rawState);
                $this->cipher = new BookingCipher($this->key, $this->state['context']);
            }
            foreach (self::OPTIONS as $name) {
                $this->raw[$name] = $this->database->read($name);
                $this->data[$name] = $this->decode($name, $this->raw[$name]);
            }
        } catch (\Throwable $exception) {
            $this->database->release();
            throw AppointmentException::fromThrowable($exception);
        }
    }

    /**
     * Returns site storage, automatically migrating before normal booking access.
     *
     * @param bool $initialize False only for status inspection and migration internals.
     */
    private static function site(bool $initialize = true): self
    {
        global $wpdb;
        $table = $wpdb->options;
        try {
            $site = self::$sites[$table] ??= new self($wpdb, $table);
            if ($site->blocked) {
                throw new AppointmentException('Appointment storage is unavailable. Please retry after correcting the storage error.');
            }
            $site->database->assertOwnership();
            if ($initialize && $site->key !== null && !$site->migrating
                && ($site->state['phase'] ?? null) !== 'encrypted') {
                self::migrate();
            }
            return $site;
        } catch (\Throwable $exception) {
            if (isset(self::$sites[$table])) {
                self::$sites[$table]->blocked = true;
            }
            throw AppointmentException::fromThrowable($exception);
        }
    }

    /**
     * Starts or resumes configured encryption before startup maintenance runs.
     *
     * Sites without a key and migration marker keep legacy mode. A missing key
     * after migration has started is an error, never permission to write plaintext.
     */
    public static function initialize(): void
    {
        try {
            self::site()->database->pruneExpiredLocks();
        } catch (\Throwable $exception) {
            throw AppointmentException::fromThrowable($exception);
        }
    }

    /**
     * Reads a validated booking array without involving the WordPress option cache.
     *
     * @return array<mixed> Decrypted or explicitly permitted legacy data.
     */
    public static function get(#[\SensitiveParameter] string $name): array
    {
        self::assertName($name);
        return self::site()->data[$name];
    }

    /**
     * Persists a workflow's updated array while retaining the request's site lock.
     *
     * @param array<mixed> $data New booking data.
     * @throws AppointmentException On invalid data, conflict, or failed persistence.
     */
    public static function set(#[\SensitiveParameter] string $name, #[\SensitiveParameter] array $data): void
    {
        self::assertName($name);
        $site = self::site();
        try {
            self::validateArray($data);
            $next = $site->cipher ? $site->cipher->encrypt($data, $name) : serialize($data);
            if (!$site->database->replace($name, $site->raw[$name], $next)) {
                throw new AppointmentException('Appointment data changed concurrently. Please reload and try again.');
            }
            if ($site->cipher && $site->cipher->decrypt($site->database->read($name), $name) !== $data) {
                throw new AppointmentException('Encrypted appointment data could not be verified.');
            }
            $site->raw[$name] = $next;
            $site->data[$name] = $data;
            if ($site->state !== null && !in_array($name, $site->state['completed'], true)) {
                $site->state['completed'][] = $name;
                $site->saveState();
            }
        } catch (\Throwable $exception) {
            $site->blocked = true;
            throw AppointmentException::fromThrowable($exception);
        }
    }

    /**
     * Reports validated, non-sensitive status for the site's administration UI.
     *
     * @return array{phase: string, completed: int, total: int}
     */
    public static function status(): array
    {
        $site = self::site(false);
        return [
            'phase' => $site->state['phase'] ?? ($site->key ? 'ready' : 'legacy'),
            'completed' => count($site->state['completed'] ?? []),
            'total' => count(self::OPTIONS),
        ];
    }

    /**
     * Starts or resumes migration, checkpointing each verified option replacement.
     *
     * All payloads are preflighted before the first write. Once state is recorded,
     * every new write is encrypted, including while migration is incomplete.
     *
     * @param int $limit Maximum number of stores to migrate in this invocation.
     */
    public static function migrate(int $limit = 5): void
    {
        $site = self::site(false);
        $site->migrating = true;
        try {
            if ($site->state === null) {
                $site->key ??= EncryptionKey::fromConfiguration();
                $context = bin2hex(random_bytes(16));
                $site->cipher = new BookingCipher($site->key, $context);
                // Round-trip all stores before committing the migration marker.
                foreach (self::OPTIONS as $name) {
                    $encrypted = $site->cipher->encrypt($site->data[$name], $name);
                    if ($site->cipher->decrypt($encrypted, $name) !== $site->data[$name]) {
                        throw new AppointmentException('Appointment data did not pass the encryption preflight.');
                    }
                }
                $site->state = [
                    'version' => 1,
                    'phase' => 'migrating',
                    'context' => $context,
                    'key_id' => $site->key->identifier($context),
                    'completed' => [],
                ];
                $site->saveState();
            }
            foreach (self::OPTIONS as $name) {
                if (!in_array($name, $site->state['completed'], true) && $limit-- > 0) {
                    self::set($name, $site->data[$name]);
                }
            }
            if (count($site->state['completed']) === count(self::OPTIONS)) {
                BookingScheduler::migrate($site->database);
                $site->state['phase'] = 'encrypted';
                $site->saveState();
            }
        } catch (\Throwable $exception) {
            $site->blocked = true;
            if ($exception instanceof AppointmentException) {
                throw AppointmentException::fromThrowable($exception);
            }
            throw new AppointmentException('Appointment migration could not be completed.');
        } finally {
            $site->migrating = false;
        }
    }

    /**
     * Converts cron payloads to non-reversible, purpose-bound references after start.
     */
    public static function jobReference(#[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $value): string
    {
        $site = self::site();
        if ($site->state === null) {
            return $value;
        }
        return 'rrze-appt-job:v1:' . hash_hmac('sha256', $hook . "\0" . $value, $site->key->forJobs($site->state['context']));
    }

    /**
     * Hides predictable slot values in auxiliary lock names with a purpose-bound HMAC.
     *
     * Legacy installations use WordPress's secret salt and site ID. Encrypted
     * installations use the configured key and persistent per-site context.
     */
    public static function lockReference(#[\SensitiveParameter] string $purpose, #[\SensitiveParameter] string $slot): string
    {
        $site = self::site();
        $key = $site->state !== null
            ? $site->key->forJobs($site->state['context'])
            : wp_salt('auth') . "\0" . get_current_blog_id();
        return 'v2_' . hash_hmac('sha256', 'lock:' . $purpose . "\0" . $slot, $key);
    }

    /**
     * Releases locks and decrypted snapshots, for explicit request/CLI boundaries.
     */
    public static function release(): void
    {
        foreach (self::$sites as $site) {
            $site->database->release();
        }
        self::$sites = [];
    }

    /**
     * Rejects accidental access to settings or unrelated WordPress options.
     */
    private static function assertName(#[\SensitiveParameter] string $name): void
    {
        if (!in_array($name, self::OPTIONS, true)) {
            throw new AppointmentException('Unsupported appointment storage area.');
        }
    }

    /**
     * Parses and validates the persistent migration marker without accepting reset.
     *
     * @return array{version: int, phase: string, context: string, key_id: string, completed: array<string>, mac: string}
     */
    private function decodeState(#[\SensitiveParameter] string $raw): array
    {
        $state = json_decode($raw, true);
        if (!is_array($state) || count($state) !== 6 || ($state['version'] ?? null) !== 1
            || !in_array($state['phase'] ?? null, ['migrating', 'encrypted'], true)
            || !is_string($state['context'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $state['context'])
            || !is_string($state['key_id'] ?? null)
            || !is_string($state['mac'] ?? null)
            || !is_array($state['completed'] ?? null) || !array_is_list($state['completed'])
        ) {
            throw new AppointmentException('The appointment storage migration state is invalid.');
        }
        foreach ($state['completed'] as $name) {
            if (!is_string($name) || !in_array($name, self::OPTIONS, true)) {
                throw new AppointmentException('The appointment storage migration state is invalid.');
            }
        }
        if (count(array_unique($state['completed'])) !== count($state['completed'])
            || ($state['phase'] === 'encrypted' && count($state['completed']) !== count(self::OPTIONS))
            || !hash_equals($this->key->identifier($state['context']), $state['key_id'])
            || !hash_equals($this->stateMac($state), $state['mac'])
        ) {
            throw new AppointmentException('The appointment encryption key or migration state does not match.');
        }
        return $state;
    }

    /**
     * Decodes only recognized ciphertext or a permitted, well-formed legacy array.
     *
     * @return array<mixed> Booking data; missing migrated rows are always errors.
     */
    private function decode(#[\SensitiveParameter] string $name, #[\SensitiveParameter] ?string $raw): array
    {
        if ($raw !== null && str_starts_with($raw, BookingCipher::PREFIX)) {
            if ($this->cipher === null) {
                throw new AppointmentException('Encrypted appointment data has no valid migration state.');
            }
            return $this->cipher->decrypt($raw, $name);
        }
        if (in_array($name, $this->state['completed'] ?? [], true)) {
            throw new AppointmentException('Encrypted appointment data is missing or has been replaced with plaintext.');
        }
        return $raw === null ? [] : self::decodeLegacy($raw);
    }

    /**
     * Reads legacy PHP arrays without instantiating any serialized objects.
     *
     * @return array<mixed> A validated legacy array, never a coerced empty fallback.
     */
    public static function decodeLegacy(#[\SensitiveParameter] string $raw): array
    {
        $data = @unserialize($raw, ['allowed_classes' => false, 'max_depth' => 64]);
        if (!is_array($data) || serialize($data) !== $raw) {
            throw new AppointmentException('Legacy appointment data is malformed. Migration was stopped.');
        }
        self::validateArray($data);
        return $data;
    }

    /**
     * Rejects recursion, invalid UTF-8 and objects before legacy writes or migration.
     *
     * @param array<mixed> $data Booking data to validate.
     */
    private static function validateArray(#[\SensitiveParameter] array $data): void
    {
        try {
            json_encode($data, JSON_THROW_ON_ERROR, 64);
            array_walk_recursive($data, static function ($value): void {
                if (is_object($value) || is_resource($value)) {
                    throw new AppointmentException('Unsupported values in appointment storage.');
                }
            });
        } catch (\JsonException) {
            throw new AppointmentException('Unsupported values in appointment storage.');
        }
    }

    /**
     * Persists the migration marker with a conditional, verified database write.
     */
    private function saveState(): void
    {
        $this->state['mac'] = $this->stateMac($this->state);
        $next = json_encode($this->state, JSON_THROW_ON_ERROR);
        if (!$this->database->replace(self::STATE_OPTION, $this->rawState, $next)) {
            throw new AppointmentException('The appointment migration state changed concurrently.');
        }
        $this->rawState = $next;
    }

    /**
     * Authenticates progress so a database edit cannot re-enable legacy acceptance.
     *
     * @param array<string, mixed> $state Validated migration fields.
     * @return string Hex-encoded HMAC over the canonical ordered field list.
     */
    private function stateMac(#[\SensitiveParameter] array $state): string
    {
        $payload = json_encode([
            $state['version'], $state['phase'], $state['context'], $state['key_id'], $state['completed'],
        ], JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', $payload, $this->key->forState($state['context']));
    }
}
