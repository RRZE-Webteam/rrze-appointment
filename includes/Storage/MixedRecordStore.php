<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Request-local, authenticated V2 directory. A clone stages changes until the owning
 * BookingStorage commits this directory, its records and the affected sources together.
 *
 * @phpstan-import-type RecordJob from RecordJobs
 */
final class MixedRecordStore
{
    public const POLICY = 'rrze_appointment_storage_state_v2';
    public const INDEX = 'rrze_appointment_record_index_v2';
    private const PREFIX = 'rrze_appointment_v2_record_';
    private const DATA_VERSION = 2;

    private ?string $policy;
    private ?string $rawIndex;
    private ?BookingCipher $cipher = null;
    private ?string $markerKey = null;
    private ?string $context = null;
    /** @var array<string,string> Ciphertext by physical option name. */
    private array $rawRecords;
    /** @var array<string,array> Decrypted records by immutable ID, for this request only. */
    private array $records = [];
    /** @var array<string,string> Aggregate identity to immutable ID. */
    private array $owners = [];
    /** @var array<string,array<string,bool>> Keyed fingerprints of historical cells removed from sources. */
    private array $retired = [];

    /** Authenticates the persisted policy, index and complete physical inventory. */
    public function __construct(OptionDatabase $database, private readonly ?EncryptionKey $key, private readonly int $siteId)
    {
        $this->policy = $database->read(self::POLICY);
        $this->rawIndex = $database->read(self::INDEX);
        $this->rawRecords = $database->recordRows();
        if ($this->policy === null) {
            if ($this->rawIndex !== null || $this->rawRecords) {
                throw new AppointmentException('The appointment encryption state is missing.');
            }
            return;
        }
        $state = json_decode($this->policy, true);
        if ($this->key === null || !is_array($state) || count($state) !== 7
            || ($state['state_version'] ?? null) !== 2 || ($state['mode'] ?? null) !== 'mixed'
            || ($state['write_data_version'] ?? null) !== self::DATA_VERSION || ($state['site_id'] ?? null) !== $siteId
            || !is_string($state['context'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $state['context'])
            || !is_string($state['key_id'] ?? null) || !is_string($state['mac'] ?? null)) {
            throw new AppointmentException('The appointment encryption state or key is invalid.');
        }
        $expected = $this->state($state['context']);
        if (!hash_equals($expected['key_id'], $state['key_id']) || !hash_equals($expected['mac'], $state['mac'])) {
            throw new AppointmentException('The appointment encryption key or site does not match.');
        }
        $this->useContext($state['context']);
        if ($this->rawIndex === null) {
            throw new AppointmentException('The appointment record index is missing.');
        }
        $index = $this->cipher->decrypt($this->rawIndex, self::INDEX);
        if (($index['data_version'] ?? null) !== self::DATA_VERSION || !is_array($index['retired'] ?? null)) {
            throw new AppointmentException('The appointment record index is invalid.');
        }
        $this->retired = $index['retired'];
        foreach ($this->retired as $field => $values) {
            if (!is_string($field) || !preg_match('/\A[a-f0-9]{64}\z/', $field) || !is_array($values) || !$values) {
                throw new AppointmentException('The appointment deletion markers are invalid.');
            }
            foreach ($values as $digest => $removed) {
                if ($removed !== true || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
                    throw new AppointmentException('The appointment deletion markers are invalid.');
                }
            }
        }
        foreach ($this->rawRecords as $name => $raw) {
            $record = $this->cipher->decrypt($raw, $name);
            $this->validateRecord($record, $name);
            $id = $record['id'];
            $owner = RecordProjection::owner($record['type'], $record['target']);
            if (isset($this->records[$id]) || isset($this->owners[$owner])) {
                throw RecordProjection::conflict();
            }
            $this->records[$id] = $record;
            $this->owners[$owner] = $id;
        }
        if ($this->index() !== $index) {
            throw new AppointmentException('The appointment records do not match their protected index.');
        }
    }

    /** Builds the canonical signed policy, including the physical WordPress site identity. */
    private function state(string $context): array
    {
        $fields = ['state_version' => 2, 'mode' => 'mixed', 'write_data_version' => self::DATA_VERSION,
            'site_id' => $this->siteId, 'context' => $context, 'key_id' => $this->key->identifier($context)];
        $fields['mac'] = hash_hmac('sha256', json_encode(['rrze-appointment:policy:v2', $fields], JSON_THROW_ON_ERROR), $this->key->forState($context));
        return $fields;
    }

    /** Initializes the codec and independently purpose-bound deletion-marker key. */
    private function useContext(string $context): void
    {
        $this->context = $context;
        $this->cipher = new BookingCipher($this->key, $context);
        $this->markerKey = hash_hmac('sha256', 'rrze-appointment:retired:v2', $this->key->forState($context), true);
    }

    /** Returns the site's purpose-bound auxiliary key after its first encrypted write. */
    public function auxiliaryKey(): ?string
    {
        return $this->context === null ? null : $this->key->forJobs($this->context);
    }

    /** Allocates a policy in memory only when a business operation needs to write. */
    private function activate(): void
    {
        if ($this->policy === null) {
            $context = bin2hex(random_bytes(16));
            $this->useContext($context);
            $this->policy = json_encode($this->state($context), JSON_THROW_ON_ERROR);
        }
    }

    /** Returns an option name containing only a fixed kind and cryptographically random ID. */
    private static function name(string $type, string $id): string
    {
        return self::PREFIX . $type . '_' . $id;
    }

    /** Rejects unsupported payload versions and inconsistent scope or logical ownership. */
    private function validateRecord(#[\SensitiveParameter] array $record, string $name): void
    {
        if (count($record) !== 6 || ($record['data_version'] ?? null) !== self::DATA_VERSION
            || !is_string($record['id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $record['id'])
            || !in_array($record['type'] ?? null, ['booking', 'pending', 'opening'], true)
            || !is_string($record['target'] ?? null) || $record['target'] === ''
            || !is_array($record['fields'] ?? null) || !is_array($record['jobs'] ?? null)
            || $name !== self::name($record['type'], $record['id'])) {
            throw new AppointmentException('An encrypted appointment record has an invalid format or identity.');
        }
        $view = array_fill_keys(BookingStorage::OPTIONS, []);
        foreach ($record['fields'] as $area => $fields) {
            if (!array_key_exists($area, $view) || !is_array($fields)) {
                throw new AppointmentException('Invalid fields in an encrypted appointment record.');
            }
        }
        RecordProjection::overlay($view, $record);
        $groups = RecordProjection::groups($view);
        $owner = RecordProjection::owner($record['type'], $record['target']);
        if (count($groups) !== 1 || ($groups[$owner]['fields'] ?? null) !== $record['fields']) {
            throw new AppointmentException('Inconsistent fields in an encrypted appointment record.');
        }
        RecordProjection::entry($record);
        BookingScheduler::validateJobs($record['type'], $record['jobs']);
    }

    /** Returns the entire independently verified V2 read set, including absence. */
    public function rawValues(): array
    {
        return [self::POLICY => $this->policy, self::INDEX => $this->rawIndex] + $this->rawRecords;
    }

    /** Reconstructs the index from records. Deterministic order makes round-trip verification exact. */
    private function index(): array
    {
        $directory = [];
        $byId = $this->records;
        ksort($byId, SORT_STRING);
        foreach ($byId as $id => $record) {
            $name = self::name($record['type'], $id);
            $directory[$id] = ['type' => $record['type'], 'digest' => hash('sha256', $this->rawRecords[$name])];
        }
        return ['data_version' => self::DATA_VERSION, 'records' => $directory,
            'lookups' => RecordProjection::lookups($byId, false), 'retired' => $this->retired];
    }

    /** Verifies the encoded bytes and returns the physical value for one transaction write. */
    private function encode(#[\SensitiveParameter] array $value, string $name): string
    {
        $raw = $this->cipher->encrypt($value, $name);
        if ($this->cipher->decrypt($raw, $name) !== $value) {
            throw new AppointmentException('Encrypted appointment data could not be verified.');
        }
        return $raw;
    }

    /** Returns two non-reversible fingerprints; removed personal data is never retained in tombstones. */
    private function fingerprints(string $area, #[\SensitiveParameter] string|int $key, #[\SensitiveParameter] mixed $value): array
    {
        $field = hash_hmac('sha256', $area . "\0" . $key, $this->markerKey);
        return [$field, hash_hmac('sha256', $field . "\0" . serialize($value), $this->markerKey)];
    }

    /** Hides exact replays of retired fields; unexpected different values require operator review. */
    private function visibleSources(#[\SensitiveParameter] array $source): array
    {
        if (!$this->retired) {
            return $source;
        }
        foreach ($source as $area => $fields) {
            foreach ($fields as $key => $value) {
                $identity = $area === RecordProjection::SLOTS ? $value : $key;
                [$field, $digest] = $this->fingerprints($area, $identity, $value);
                if (isset($this->retired[$field])) {
                    if (!isset($this->retired[$field][$digest])) {
                        throw RecordProjection::conflict();
                    }
                    unset($source[$area][$key]);
                }
            }
        }
        return $source;
    }

    /** Returns the combined logical data and checks live reservations and token uniqueness. */
    public function view(#[\SensitiveParameter] array $source): array
    {
        $view = $this->visibleSources($source);
        $sourceGroups = RecordProjection::groups($view);
        if (!$this->records) {
            RecordProjection::lookups($sourceGroups, true);
            return $view;
        }
        foreach (array_keys($sourceGroups) as $owner) {
            // A late, previously unseen legacy token must never gain authority over
            // a different encrypted booking which happens to reuse the same slot.
            if (isset($this->owners[$owner])) {
                throw RecordProjection::conflict();
            }
        }
        foreach ($this->records as $record) {
            RecordProjection::overlay($view, $record);
        }
        RecordProjection::lookups(RecordProjection::groups($view), true);
        return $view;
    }

    /** Looks up the immutable ID belonging to a current V2 aggregate. */
    public function recordId(string $type, #[\SensitiveParameter] string $target): ?string
    {
        return $this->owners[RecordProjection::owner($type, $target)] ?? null;
    }

    /**
     * Returns the current job map for every indexed record, including empty maps.
     *
     * @return array<string,array{type:'pending'|'booking'|'opening',target:string,jobs:array<string,RecordJob>}> Entries keyed by immutable record ID.
     */
    public function managedJobs(): array
    {
        $jobs = [];
        foreach ($this->records as $id => $record) {
            $jobs[$id] = ['type' => $record['type'], 'target' => $record['target'], 'jobs' => $record['jobs']];
        }
        return $jobs;
    }

    /**
     * Stages a replacement job map and its index update in the request-local record store.
     *
     * @param string $id Immutable record ID.
     * @param array<string,RecordJob> $jobs Complete job map to stage for this owner.
     * @throws AppointmentException When the record is missing or its replacement data cannot be validated or encoded.
     */
    public function setJobs(string $id, #[\SensitiveParameter] array $jobs): void
    {
        if (!isset($this->records[$id])) {
            throw new AppointmentException('The appointment job owner no longer exists.');
        }
        $record = $this->records[$id];
        if ($record['jobs'] !== $jobs) {
            BookingScheduler::validateJobs($record['type'], $jobs);
            $record['jobs'] = $jobs;
            $this->storeRecord($record);
            $this->rawIndex = $this->encode($this->index(), self::INDEX);
        }
    }

    /** Removes source fields in memory, preserving their keyed fingerprints against stale snapshots. */
    private function retire(#[\SensitiveParameter] array &$source, #[\SensitiveParameter] array $group): void
    {
        foreach ($group['fields'] as $area => $fields) {
            foreach ($fields as $key => $value) {
                [$field, $digest] = $this->fingerprints($area, $key, $value);
                $this->retired[$field][$digest] = true;
                RecordProjection::remove($source, $area, $key);
            }
        }
    }

    /** Stages a record replacement and binds its encrypted bytes to the next index. */
    private function storeRecord(#[\SensitiveParameter] array $record): void
    {
        $id = $record['id'];
        $name = self::name($record['type'], $id);
        $this->validateRecord($record, $name);
        $this->records[$id] = $record;
        $this->owners[RecordProjection::owner($record['type'], $record['target'])] = $id;
        $this->rawRecords[$name] = $this->encode($record, $name);
    }

    /**
     * Plans changes to whole aggregates, preserving ID from reservation to confirmation.
     *
     * @param array $source Actual V0/V1 arrays, including any physically returned stale copies.
     * @param array $before Combined logical view used by the business operation.
     * @param array $after Its desired complete logical view.
     * @param array{type:string,target:string,at:int}|null $job New work which promotes an otherwise unchanged owner.
     * @return array Remaining source arrays to persist in the same transaction.
     */
    public function apply(#[\SensitiveParameter] array $source, #[\SensitiveParameter] array $before, #[\SensitiveParameter] array $after, #[\SensitiveParameter] ?array $job = null): array
    {
        $old = RecordProjection::groups($before);
        $new = RecordProjection::groups($after);
        RecordProjection::lookups($new, true);
        $legacy = RecordProjection::groups($this->visibleSources($source));
        $changed = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $owner) {
            if (($old[$owner] ?? null) !== ($new[$owner] ?? null)) {
                $changed[] = $owner;
            }
        }
        $jobOwner = null;
        if ($job !== null) {
            $jobOwner = RecordProjection::owner($job['type'], $job['target']);
            if (!isset($legacy[$jobOwner]) || $job['at'] < 1) {
                throw new AppointmentException('The appointment job has no valid source owner or due time.');
            }
            if (!in_array($jobOwner, $changed, true)) {
                $changed[] = $jobOwner;
            }
        }
        if (!$changed) {
            return $source;
        }
        $this->activate();
        $previous = $this->records;
        $ids = $this->owners;
        $confirmations = [];
        foreach ($changed as $owner) {
            if (isset($legacy[$owner])) {
                $this->retire($source, $legacy[$owner]);
            }
            if (isset($new[$owner]) || !isset($ids[$owner])) {
                continue;
            }
            $id = $ids[$owner];
            $prior = $previous[$id];
            $this->retire($source, $prior);
            if ($prior['type'] === 'pending') {
                $entry = RecordProjection::entry($prior);
                if (($entry['expires'] ?? 0) > time()) {
                    $confirmations[$entry['slot']] = $id;
                }
            }
            unset($this->rawRecords[self::name($prior['type'], $id)], $this->records[$id], $this->owners[$owner]);
        }
        foreach ($changed as $owner) {
            if (!isset($new[$owner])) {
                continue;
            }
            $group = $new[$owner];
            $type = $group['type'];
            $target = $group['target'];
            $id = $ids[$owner] ?? ($type === 'booking' ? ($confirmations[$target] ?? null) : null) ?? bin2hex(random_bytes(16));
            $oldJobs = isset($ids[$owner]) ? $previous[$id]['jobs'] : null;
            $record = ['data_version' => self::DATA_VERSION, 'id' => $id] + $group;
            $record['jobs'] = BookingScheduler::jobsForRecord(
                $type, $target, RecordProjection::entry($group), $oldJobs, isset($legacy[$owner]),
                $owner === $jobOwner ? $job['at'] : null
            );
            $this->storeRecord($record);
        }
        $this->rawIndex = $this->encode($this->index(), self::INDEX);
        return $source;
    }
}
