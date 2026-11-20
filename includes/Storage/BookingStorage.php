<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Presents one site-local view of legacy arrays, authenticated V1 options and V2 records.
 * A business mutation transfers only its affected aggregates in one native transaction.
 *
 * @phpstan-import-type RecordJob from RecordJobs
 */
final class BookingStorage
{
    public const STATE_OPTION = 'rrze_appointment_storage_state';
    public const MIXED_STATE_OPTION = MixedRecordStore::POLICY;
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
    private array $source = [];
    private array $data = [];
    private MixedRecordStore $mixed;
    private bool $blocked = false;

    /**
     * Locks and validates every store before any workflow can change booking data.
     */
    private function __construct(#[\SensitiveParameter] object $database, #[\SensitiveParameter] string $table)
    {
        $this->database = new OptionDatabase($database, $table);
        try {
            $this->readSnapshot();
        } catch (\Throwable $exception) {
            $this->database->release();
            throw AppointmentException::fromThrowable($exception);
        }
    }

    /** Rereads all formats while retaining the existing site's lock and connection. */
    private function readSnapshot(): void
    {
        $this->rawState = $this->database->read(self::STATE_OPTION);
        $this->key = defined(EncryptionKey::CONFIGURATION_CONSTANT) || $this->rawState !== null
            ? EncryptionKey::fromConfiguration() : null;
        $this->state = $this->rawState !== null ? $this->decodeState($this->rawState) : null;
        $this->cipher = $this->state !== null ? new BookingCipher($this->key, $this->state['context']) : null;
        foreach (self::OPTIONS as $name) {
            $this->raw[$name] = $this->database->read($name);
            $this->source[$name] = $this->decode($name, $this->raw[$name]);
        }
        $this->mixed = new MixedRecordStore($this->database, $this->key, get_current_blog_id());
        $this->data = $this->mixed->view($this->source);
    }

    /** Returns validated site storage, retaining the same connection across a business operation. */
    private static function site(): self
    {
        global $wpdb;
        $table = $wpdb->options;
        try {
            $site = self::$sites[$table] ??= new self($wpdb, $table);
            if ($site->blocked) {
                throw new AppointmentException('Appointment storage is unavailable. Please retry after correcting the storage error.');
            }
            $site->database->assertOwnership();
            return $site;
        } catch (\Throwable $exception) {
            if (isset(self::$sites[$table])) {
                self::$sites[$table]->blocked = true;
            }
            throw AppointmentException::fromThrowable($exception);
        }
    }

    /** Validates the site's current formats without changing any data. */
    public static function initialize(): void
    {
        self::site();
    }

    /** Removes expired historical auxiliary locks during background maintenance. */
    public static function pruneExpiredLocks(): void
    {
        self::site()->database->pruneExpiredLocks();
    }

    /** Includes dormant installations with booking data, without populating option caches. */
    public static function hasStoredData(): bool
    {
        global $wpdb;
        $names = [...self::OPTIONS, self::STATE_OPTION, self::MIXED_STATE_OPTION, MixedRecordStore::INDEX];
        $arguments = [$wpdb->options, ...$names, 'rrze!_appointment!_v2!_record!_%'];
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name IN (' . implode(',', array_fill(0, count($names), '%s')) . ") OR option_name LIKE %s ESCAPE '!' LIMIT 1",
            ...$arguments
        ));
        if ($wpdb->last_error !== '') {
            throw new AppointmentException('Appointment site inventory could not be read.');
        }
        return $found !== null;
    }

    /** Returns whether wake repair may find stored work, without loading booking data or populating caches. */
    public static function hasMixedState(): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name = %s', $wpdb->options, self::MIXED_STATE_OPTION
        ));
        if ($wpdb->last_error !== '') {
            throw new AppointmentException('Appointment storage could not be read.');
        }
        return $found !== null;
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

    /** Persists an array change using its aggregate boundaries in mixed mode. */
    public static function set(#[\SensitiveParameter] string $name, #[\SensitiveParameter] array $data): void
    {
        self::setMany([$name => $data]);
    }

    /**
     * Commits a business operation across both formats, including removals and durable jobs.
     *
     * @param array<string,array> $updates Complete replacement views for the affected logical options.
     * @throws AppointmentException If validation, locking, encoding, read-set checks or commit fails.
     */
    public static function setMany(#[\SensitiveParameter] array $updates): void
    {
        if (!$updates) {
            return;
        }
        $site = self::site();
        try {
            foreach ($updates as $name => $data) {
                self::assertName($name);
                if (!is_array($data)) {
                    throw new AppointmentException('Invalid atomic appointment update.');
                }
                self::validateArray($data);
            }
            $desired = array_replace($site->data, $updates);
            if ($desired === $site->data) {
                return;
            }
            if ($site->key === null) {
                $site->writeLegacy($updates);
            } else {
                $candidate = clone $site->mixed;
                $source = $candidate->apply($site->source, $site->data, $desired);
                $site->commit($candidate, $source);
            }
        } catch (\Throwable $exception) {
            $site->blocked = true;
            throw AppointmentException::fromThrowable($exception);
        }
        self::refreshWake();
    }

    /** Preserves the unconfigured legacy installation, using InnoDB for multi-option mutations. */
    private function writeLegacy(#[\SensitiveParameter] array $updates): void
    {
        $next = [];
        foreach ($updates as $name => $data) {
            if ($data !== $this->source[$name]) {
                $next[$name] = serialize($data);
            }
        }
        if (count($updates) === 1) {
            $name = array_key_first($updates);
            if (!$this->database->replace($name, $this->raw[$name], $next[$name])) {
                throw RecordProjection::conflict();
            }
        } else {
            $this->database->replaceMany($this->raw, $next);
        }
        $this->raw = array_replace($this->raw, $next);
        $this->data = $this->source = array_replace($this->source, $updates);
    }

    /**
     * Stages source removal in the original format, respecting V1's signed write policy.
     * The request snapshot advances only after COMMIT has been acknowledged.
     */
    private function commit(MixedRecordStore $candidate, #[\SensitiveParameter] array $source): void
    {
        $oldMixed = $this->mixed->rawValues();
        $newMixed = $candidate->rawValues();
        $readSet = $this->raw + [self::STATE_OPTION => $this->rawState] + $oldMixed;
        $writes = [];
        foreach (array_keys($newMixed + $oldMixed) as $name) {
            $readSet[$name] ??= null;
            $next = $newMixed[$name] ?? null;
            if ($readSet[$name] !== $next) {
                $writes[$name] = $next;
            }
        }
        foreach (self::OPTIONS as $name) {
            if ($source[$name] === $this->source[$name]) {
                continue;
            }
            // This is a removal only. Even for a partially completed V1 migration,
            // unrelated entries keep their original format and signed checkpoint.
            $encrypted = str_starts_with($this->raw[$name] ?? '', BookingCipher::PREFIX);
            $next = $encrypted ? $this->cipher->encrypt($source[$name], $name) : serialize($source[$name]);
            $verified = $encrypted ? $this->cipher->decrypt($next, $name) : self::decodeLegacy($next);
            if ($verified !== $source[$name]) {
                throw new AppointmentException('Appointment source data could not be verified.');
            }
            $writes[$name] = $next;
        }
        if (!$writes) {
            return;
        }
        $view = $candidate->view($source);
        $this->database->replaceMany($readSet, $writes);
        $this->raw = array_replace($this->raw, array_intersect_key($writes, $this->raw));
        $this->source = $source;
        $this->data = $view;
        $this->mixed = $candidate;
    }

    /** Repairs a disposable scheduler wake after a committed write; durable work remains recoverable. */
    private static function refreshWake(): void
    {
        try {
            BookingScheduler::afterCommit();
        } catch (\Throwable) {
            // A subsequent plugin/cron start can restore the wake from the committed records.
        }
    }

    /**
     * Returns the encrypted queue's live owners and jobs under the site lock.
     *
     * @return array<string,array{type:'pending'|'booking'|'opening',target:string,jobs:array<string,RecordJob>}> Entries keyed by immutable record ID.
     */
    public static function managedJobs(): array
    {
        return self::site()->mixed->managedJobs();
    }

    /** Returns the durable ID if this particular legacy target has already been promoted. */
    public static function recordId(string $type, #[\SensitiveParameter] string $target): ?string
    {
        return self::site()->mixed->recordId($type, $target);
    }

    /**
     * Moves an old target when its next notification/expiry is newly scheduled.
     * The requested timestamp and the source removal commit atomically.
     *
     * @return string|null Immutable V2 ID, or null only in unconfigured legacy mode.
     */
    public static function promoteForJob(string $type, #[\SensitiveParameter] string $target, int $timestamp): ?string
    {
        $site = self::site();
        if ($site->key === null) {
            return null;
        }
        $id = $site->mixed->recordId($type, $target);
        if ($id !== null) {
            return $id;
        }
        try {
            $candidate = clone $site->mixed;
            $source = $candidate->apply($site->source, $site->data, $site->data,
                ['type' => $type, 'target' => $target, 'at' => $timestamp]);
            $site->commit($candidate, $source);
        } catch (\Throwable $exception) {
            $site->blocked = true;
            throw AppointmentException::fromThrowable($exception);
        }
        self::refreshWake();
        return $site->mixed->recordId($type, $target);
    }

    /**
     * Atomically saves a worker lease, retry or acknowledgement on its owning record.
     *
     * @param string $id Current immutable record ID.
     * @param array<string,RecordJob> $jobs Complete replacement job map, including any reminder receipts to retain.
     * @throws AppointmentException When the record is missing, its jobs are invalid or the update cannot be committed.
     */
    public static function setRecordJobs(string $id, #[\SensitiveParameter] array $jobs): void
    {
        $site = self::site();
        try {
            $candidate = clone $site->mixed;
            $candidate->setJobs($id, $jobs);
            $site->commit($candidate, $site->source);
        } catch (\Throwable $exception) {
            $site->blocked = true;
            throw AppointmentException::fromThrowable($exception);
        }
    }

    /** Reads a fresh raw WordPress cron snapshot for targeted adoption of existing single events. */
    public static function cronSnapshot(): array
    {
        $raw = self::site()->database->read('cron');
        if ($raw === null) {
            return [];
        }
        $events = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($events)) {
            throw new AppointmentException('The appointment cron queue could not be inspected.');
        }
        return $events;
    }

    /** Checks configuration, transaction support, readable records and the cipher without writing. */
    public static function preflight(): void
    {
        $site = self::site();
        $key = EncryptionKey::fromConfiguration();
        $site->database->assertTransactional();
        $cipher = new BookingCipher($key, bin2hex(random_bytes(16)));
        foreach (self::OPTIONS as $name) {
            if ($cipher->decrypt($cipher->encrypt($site->data[$name], $name), $name) !== $site->data[$name]) {
                throw new AppointmentException('Appointment data did not pass the encryption preflight.');
            }
        }
        BookingScheduler::inspect($site->database);
    }

    /** Reads a fresh snapshot under the site lock, rejecting changed, missing or malformed rows. */
    private static function inspection(): self
    {
        $site = self::site();
        try {
            $site->readSnapshot();
            return $site;
        } catch (\Throwable $exception) {
            $site->blocked = true;
            throw AppointmentException::fromThrowable($exception);
        }
    }

    /** Verifies old source checkpoints, V2 policy, index, immutable record scopes and scheduler inputs. */
    public static function verifyMixed(): void
    {
        $site = self::inspection();
        EncryptionKey::fromConfiguration();
        $site->database->assertTransactional();
        BookingScheduler::inspect($site->database);
    }

    /** Verifies full encryption at rest. Mixed sites with remaining plaintext data or cron arguments fail. */
    public static function verify(): void
    {
        $site = self::inspection();
        if (($site->state !== null && $site->state['phase'] !== 'encrypted')
            || ($site->state === null && $site->mixed->rawValues()[self::MIXED_STATE_OPTION] === null)) {
            throw new AppointmentException('Appointment encryption migration is incomplete.');
        }
        foreach (self::OPTIONS as $name) {
            $raw = $site->raw[$name];
            if ($raw !== null && !str_starts_with($raw, BookingCipher::PREFIX)) {
                throw new AppointmentException('Appointment source data still contains plaintext.');
            }
        }
        BookingScheduler::inspect($site->database, true);
    }

    /**
     * Reports the configured write mode and any historical V1 checkpoints.
     *
     * @return array{phase:string,completed:int,total:int} Non-sensitive diagnostics only.
     */
    public static function status(): array
    {
        $site = self::site();
        return ['phase' => $site->key === null ? 'legacy' : 'mixed',
            'completed' => count($site->state['completed'] ?? []), 'total' => count(self::OPTIONS)];
    }

    /** Rejects the old whole-site operation before changing any data. */
    public static function migrate(int $limit = 5): void
    {
        throw new AppointmentException('Full migration is disabled. New and changed appointment data are encrypted automatically.');
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
            : ($site->mixed->auxiliaryKey() ?? (wp_salt('auth') . "\0" . get_current_blog_id()));
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
