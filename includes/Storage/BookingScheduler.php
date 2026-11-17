<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Notification\BookingOpeningNotifier;
use RRZE\Appointment\Notification\Reminder;

defined('ABSPATH') || exit;

/** Dispatches older WordPress events and the encrypted jobs owned by individual V2 records. */
final class BookingScheduler
{
    public const WAKE_HOOK = 'rrze_appointment_run_jobs';
    private const RETRY_SECONDS = 300;
    private const BATCH_SIZE = 10;
    private const OPAQUE = '/^rrze-appt-job:v1:[a-f0-9]{64}$/D';

    /** Maps the three supported legacy hook names to their record type and lookup store. */
    private const SOURCES = [
        'rrze_appointment_send_reminder' => ['booking', 'rrze_appointment_booked_slots_meta'],
        'rrze_appointment_expire_pending' => ['pending', 'rrze_appointment_pending_slots'],
        'rrze_appointment_booking_opened' => ['opening', 'rrze_appointment_booking_opening_notifications'],
    ];

    /** @var array{string,string}|null Record ID and job key of a callback currently executing. */
    private static ?array $executing = null;

    /** Registers the argument-free wake and repairs it when this site already has V2 data. */
    public static function register(): void
    {
        add_action(self::WAKE_HOOK, [self::class, 'run']);
        try {
            self::repairWake();
        } finally {
            BookingStorage::release();
        }
    }

    /** Ensures cleanup and the earliest V2 wake after a successful domain commit. */
    public static function afterCommit(): void
    {
        Reminder::ensureDailyCheck(true);
        self::repairWake();
    }

    /**
     * Prepares the jobs to commit in the same transaction as their owning record.
     *
     * @param string $type pending, booking or opening.
     * @param string $target Slot or action token in the source record.
     * @param array $entry Pending entry, confirmed metadata or opening subscription.
     * @param array|null $previousJobs Completed receipts and unfinished jobs of the same lifecycle stage.
     * @param bool $fromLegacy Whether the current record is being adopted from a legacy/V1 source.
     * @param int|null $requestedTime Explicit follow-up time when a legacy retry triggers the adoption.
     * @return array<string,array> Jobs including previously scheduled due times.
     */
    public static function jobsForRecord(
        string $type, #[\SensitiveParameter] string $target, #[\SensitiveParameter] array $entry,
        ?array $previousJobs, bool $fromLegacy, ?int $requestedTime = null
    ): array {
        $times = $fromLegacy && $previousJobs === null ? self::legacyTimes($type, $target) : [];
        if ($requestedTime !== null) {
            $times[] = $requestedTime;
        }
        return RecordJobs::forRecord($type, $target, $entry, $previousJobs, $times);
    }

    /** Validates authenticated job data before either the worker or a domain change uses it. */
    public static function validateJobs(string $type, #[\SensitiveParameter] array $jobs): void
    {
        RecordJobs::validate($type, $jobs);
    }

    /**
     * Reads all matching single-event times from the live cron row without rewriting it.
     *
     * @return int[] Matching original timestamps including already due events.
     */
    private static function legacyTimes(string $type, #[\SensitiveParameter] string $target): array
    {
        $hook = match ($type) {
            'booking' => Reminder::CRON_HOOK,
            'pending' => TokenManager::PENDING_EXPIRY_HOOK,
            'opening' => BookingOpeningNotifier::CRON_HOOK,
            default => throw new AppointmentException('Unsupported appointment job owner.'),
        };
        $reference = BookingStorage::jobReference($hook, $target);
        $times = [];
        foreach (BookingStorage::cronSnapshot() as $timestamp => $hooks) {
            if (!is_array($hooks) || !isset($hooks[$hook])) {
                continue;
            }
            foreach (self::events($hooks[$hook]) as $event) {
                $argument = $event['args'][0];
                if ($argument === $target || $argument === $reference) {
                    $times[] = (int) $timestamp;
                    break;
                }
            }
        }
        return $times;
    }

    /** Schedules a follow-up on its V2 record or in WordPress for a legacy owner. */
    public static function schedule(int $timestamp, #[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $value): void
    {
        [$type] = self::source($hook);
        $id = BookingStorage::recordId($type, $value);
        if ($id === null) {
            if (BookingStorage::promoteForJob($type, $value, $timestamp) !== null) {
                return;
            }
            $args = [BookingStorage::jobReference($hook, $value)];
            $result = wp_schedule_single_event($timestamp, $hook, $args, true);
            if (($result === false || $result instanceof \WP_Error) && !wp_next_scheduled($hook, $args)) {
                throw new AppointmentException('An appointment notification could not be scheduled.');
            }
            return;
        }
        $jobs = BookingStorage::managedJobs()[$id]['jobs'];
        $next = $jobs;
        $actions = match ($type) {
            'pending' => ['expire'], 'opening' => ['notify'], 'booking' => ['host', 'booker'],
        };
        foreach ($actions as $action) {
            RecordJobs::add($next, $action, $timestamp);
        }
        if ($next !== $jobs) {
            BookingStorage::setRecordJobs($id, $next);
            self::repairWake();
        }
    }

    /** Returns the earliest queued attempt, excluding an opening callback requesting its own retry. */
    public static function next(#[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $value): int|false
    {
        [$type] = self::source($hook);
        $id = BookingStorage::recordId($type, $value);
        if ($id !== null) {
            $at = false;
            foreach (BookingStorage::managedJobs()[$id]['jobs'] as $key => $job) {
                if ($job['run_at'] && self::$executing !== [$id, $key]) {
                    $at = $at === false ? $job['run_at'] : min($at, $job['run_at']);
                }
            }
            return $at;
        }
        $ref = BookingStorage::jobReference($hook, $value);
        $at = wp_next_scheduled($hook, [$ref]);
        if ($ref !== $value) {
            $legacy = wp_next_scheduled($hook, [$value]);
            if ($legacy !== false && ($at === false || $legacy < $at)) {
                return $legacy;
            }
        }
        return $at;
    }

    /** Cancels pending work and clears the exact legacy argument/reference of this target. */
    public static function clear(#[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $value): void
    {
        [$type] = self::source($hook);
        $id = BookingStorage::recordId($type, $value);
        if ($id !== null && BookingStorage::managedJobs()[$id]['jobs']) {
            BookingStorage::setRecordJobs($id, []);
            self::repairWake();
        }
        $reference = BookingStorage::jobReference($hook, $value);
        wp_clear_scheduled_hook($hook, [$reference]);
        if ($reference !== $value) {
            wp_clear_scheduled_hook($hook, [$value]);
        }
    }

    /** Resolves a legacy argument or V1 reference against the current combined domain view. */
    public static function resolve(#[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $argument): ?string
    {
        [, $store] = self::source($hook);
        foreach (array_keys(BookingStorage::get($store)) as $value) {
            $value = (string) $value;
            if ($value === $argument || hash_equals(BookingStorage::jobReference($hook, $value), $argument)) {
                return $value;
            }
        }
        return null;
    }

    /** Handles an older pending expiry callback, including callbacks already dispatched during an update. */
    public static function expirePending(#[\SensitiveParameter] string $argument): void
    {
        self::dispatch(TokenManager::PENDING_EXPIRY_HOOK, $argument, [TokenManager::class, 'expirePending']);
    }

    /** Handles the plaintext or V1 argument of an older opening notification. */
    public static function notifyOpening(#[\SensitiveParameter] string $argument): void
    {
        self::dispatch(BookingOpeningNotifier::CRON_HOOK, $argument, [BookingOpeningNotifier::class, 'notify']);
    }

    /** Handles an older slot-specific reminder. */
    public static function sendReminder(#[\SensitiveParameter] string $argument): void
    {
        self::dispatch(Reminder::CRON_HOOK, $argument, [new Reminder(), 'sendReminder']);
    }

    /**
     * Runs a legacy callback for a legacy owner. Once adopted, its own due jobs decide delivery.
     *
     * @param callable(string):void $callback Domain callback used by the older schedule.
     */
    private static function dispatch(string $hook, #[\SensitiveParameter] string $argument, callable $callback): void
    {
        try {
            $target = self::resolve($hook, $argument);
            if ($target === null) {
                return;
            }
            [$type] = self::source($hook);
            $id = BookingStorage::recordId($type, $target);
            if ($id === null) {
                $callback($target);
            } else {
                self::runDue($id);
            }
        } catch (AppointmentException) {
            // An opaque event may safely be deferred even when the key is unavailable.
            if (preg_match(self::OPAQUE, $argument) && !wp_next_scheduled($hook, [$argument])) {
                wp_schedule_single_event(time() + self::RETRY_SECONDS, $hook, [$argument]);
            }
        }
    }

    /**
     * Schedules a site-local wake for its earliest unfinished job.
     *
     * A lost wake is rebuilt at startup. Commit success is independent of core's cron row.
     * With a V2 marker and a temporarily unavailable store, only the empty-argument wake is retried.
     */
    public static function repairWake(): void
    {
        try {
            if (!BookingStorage::hasMixedState()) {
                return;
            }
            try {
                $earliest = null;
                foreach (BookingStorage::managedJobs() as $record) {
                    foreach ($record['jobs'] as $job) {
                        if ($job['run_at'] > 0) {
                            $earliest = $earliest === null ? $job['run_at'] : min($earliest, $job['run_at']);
                        }
                    }
                }
            } catch (AppointmentException) {
                $earliest = time() + self::RETRY_SECONDS;
            }
            $scheduled = wp_next_scheduled(self::WAKE_HOOK, []);
            if ($scheduled !== false && $earliest !== null && $scheduled <= max(time() + 1, $earliest)) {
                return;
            }
            if ($scheduled !== false) {
                // Core de-duplicates equal arguments within ten minutes; remove a later wake first.
                wp_clear_scheduled_hook(self::WAKE_HOOK, []);
            }
            if ($earliest !== null) {
                wp_schedule_single_event(max(time() + 1, $earliest), self::WAKE_HOOK, []);
            }
        } catch (\Throwable) {
            // The committed job remains authoritative and the next startup tries again.
        }
    }

    /** Processes a bounded batch when WordPress dispatches the generic wake. */
    public static function run(): void
    {
        try {
            self::runDue();
        } catch (AppointmentException) {
            // Configuration and integrity errors are reported by the site's storage diagnostics.
        } finally {
            self::repairWake();
            BookingStorage::release();
        }
    }

    /**
     * Handles the daily fallback and changed reminder settings for a V2 booking.
     *
     * @return bool Whether the V2 record owns reminder delivery for this slot.
     */
    public static function dailyReminder(#[\SensitiveParameter] string $slot): bool
    {
        $id = BookingStorage::recordId('booking', $slot);
        if ($id === null) {
            return false;
        }
        $jobs = BookingStorage::managedJobs()[$id]['jobs'];
        $next = $jobs;
        $today = Reminder::today();
        foreach (Reminder::plannedTimes($slot) as $role => $due) {
            if (wp_date('Y-m-d', $due) === $today && !RecordJobs::sentOn($jobs, $role, $today)) {
                RecordJobs::add($next, $role, $due);
            }
        }
        if ($next !== $jobs) {
            BookingStorage::setRecordJobs($id, $next);
            self::repairWake();
        }
        self::runDue($id);
        return true;
    }

    /**
     * Retains delivery when an old reminder creates a missing cancellation token during mail preparation.
     * This can adopt the booking while its original reminder is already being sent.
     */
    public static function acknowledgeLegacyReminder(#[\SensitiveParameter] string $slot, string $role): void
    {
        $id = BookingStorage::recordId('booking', $slot);
        if ($id === null) {
            return;
        }
        $jobs = BookingStorage::managedJobs()[$id]['jobs'];
        $at = time();
        RecordJobs::add($jobs, $role, $at);
        $key = $role . ':' . $at;
        $jobs[$key]['run_at'] = 0;
        $jobs[$key]['claim'] = '';
        $jobs[$key]['sent_on'] = Reminder::today();
        BookingStorage::setRecordJobs($id, $jobs);
        self::repairWake();
    }

    /** Runs at most ten due attempts, optionally only for the owner of an older callback. */
    private static function runDue(?string $onlyId = null): void
    {
        $count = 0;
        foreach (BookingStorage::managedJobs() as $id => $record) {
            if ($onlyId !== null && $id !== $onlyId) {
                continue;
            }
            foreach ($record['jobs'] as $key => $job) {
                if ($job['run_at'] > 0 && $job['run_at'] <= time()) {
                    self::runJob($id, $key);
                    if ($onlyId === null) {
                        // Independent worker jobs are separate work units on the same busy site.
                        BookingStorage::release();
                    }
                    if (++$count >= self::BATCH_SIZE) {
                        return;
                    }
                }
            }
        }
    }

    /**
     * Persists a retryable claim before the external delivery. A crash makes it eligible again.
     *
     * Domain callbacks may reschedule the job, change the record type or remove the record.
     * The acknowledgement therefore reloads the ID, action and claim before changing anything.
     */
    private static function runJob(string $id, string $key): void
    {
        $owner = BookingStorage::managedJobs()[$id] ?? null;
        $job = $owner['jobs'][$key] ?? null;
        if ($job === null || !$job['run_at'] || $job['run_at'] > time()) {
            return;
        }
        $claim = bin2hex(random_bytes(16));
        $jobs = $owner['jobs'];
        $jobs[$key]['claim'] = $claim;
        $jobs[$key]['run_at'] = time() + self::RETRY_SECONDS;
        BookingStorage::setRecordJobs($id, $jobs);
        self::repairWake();

        $previous = self::$executing;
        self::$executing = [$id, $key];
        $sentOn = '';
        try {
            if ($job['action'] === 'expire') {
                TokenManager::expirePending($owner['target']);
            } elseif ($job['action'] === 'notify') {
                BookingOpeningNotifier::notify($owner['target']);
            } else {
                $day = Reminder::today();
                if (!RecordJobs::sentOn($jobs, $job['action'], $day)
                    && (new Reminder())->sendToRecipient($owner['target'], $job['action'])) {
                    $sentOn = $day;
                }
            }
        } catch (\Throwable) {
            // A failed or interrupted external delivery leaves its bounded retry in the record.
            return;
        } finally {
            self::$executing = $previous;
        }
        $jobs = BookingStorage::managedJobs()[$id]['jobs'] ?? [];
        if (($jobs[$key]['claim'] ?? null) !== $claim) {
            return;
        }
        if ($owner['type'] === 'booking') {
            $jobs[$key]['claim'] = '';
            $jobs[$key]['run_at'] = 0;
            $jobs[$key]['sent_on'] = $sentOn;
        } else {
            unset($jobs[$key]);
        }
        BookingStorage::setRecordJobs($id, $jobs);
    }

    /** Returns the record type and domain option for a supported legacy hook. */
    private static function source(string $hook): array
    {
        return self::SOURCES[$hook] ?? throw new AppointmentException('Unsupported appointment job.');
    }

    /**
     * Validates the supported legacy event shape without interpreting other plugins' payloads.
     *
     * @return array<string,array> Valid events from this known hook.
     */
    private static function events(#[\SensitiveParameter] mixed $events): array
    {
        if (!is_array($events)) {
            throw new AppointmentException('An appointment cron event is malformed.');
        }
        foreach ($events as $event) {
            if (!is_array($event) || !is_array($event['args'] ?? null)
                || array_keys($event['args']) !== [0] || !is_string($event['args'][0])) {
                throw new AppointmentException('An appointment cron event is malformed.');
            }
        }
        return $events;
    }

    /**
     * Inspects legacy events for maintenance, allowing plaintext arguments only in mixed mode.
     *
     * @param OptionDatabase $database Site connection holding the storage lock.
     * @param bool $requireOpaque Whether every legacy event needs a valid V1 reference and key.
     */
    public static function inspect(OptionDatabase $database, bool $requireOpaque = false): void
    {
        $raw = $database->read('cron');
        if ($raw === null) {
            return;
        }
        $cron = @unserialize($raw, ['allowed_classes' => false, 'max_depth' => 64]);
        if (!is_array($cron)) {
            throw new AppointmentException('The appointment cron queue could not be read.');
        }
        foreach ($cron as $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            if (isset($hooks[self::WAKE_HOOK])) {
                $wakes = $hooks[self::WAKE_HOOK];
                if (!is_array($wakes) || array_keys($wakes) !== [md5(serialize([]))]
                    || (reset($wakes)['args'] ?? null) !== []) {
                    throw new AppointmentException('An appointment wake contains unexpected arguments.');
                }
            }
            foreach (array_keys(self::SOURCES) as $hook) {
                if (!isset($hooks[$hook])) {
                    continue;
                }
                foreach (self::events($hooks[$hook]) as $key => $event) {
                    $argument = $event['args'][0];
                    if (($requireOpaque || str_starts_with($argument, 'rrze-appt-job:'))
                        && (!preg_match(self::OPAQUE, $argument) || $key !== md5(serialize($event['args'])))) {
                        throw new AppointmentException('Appointment cron protection is incomplete. Check the scheduled jobs.');
                    }
                }
            }
        }
    }
}
