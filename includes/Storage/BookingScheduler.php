<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Notification\BookingOpeningNotifier;
use RRZE\Appointment\Notification\Reminder;

defined('ABSPATH') || exit;

/**
 * Keeps booking times and action tokens out of the persisted WordPress cron queue.
 *
 * References are keyed, hook-specific hashes. Their targets are resolved from the
 * encrypted stores, so no additional plaintext reference-to-booking map is needed.
 */
final class BookingScheduler
{
    private const STORES = [
        'rrze_appointment_send_reminder' => 'rrze_appointment_booked_slots_meta',
        'rrze_appointment_expire_pending' => 'rrze_appointment_pending_slots',
        'rrze_appointment_booking_opened' => 'rrze_appointment_booking_opening_notifications',
    ];
    private const PREFIX = 'rrze-appt-job:v1:';

    /**
     * Schedules a job with an opaque argument once encryption has started.
     */
    public static function schedule(int $timestamp, #[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $value): void
    {
        wp_schedule_single_event($timestamp, $hook, [BookingStorage::jobReference($hook, $value)]);
    }

    /**
     * Finds a scheduled job using the same reference used when it was persisted.
     *
     * @return int|false Next run timestamp, or false if no matching event exists.
     */
    public static function next(#[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $value): int|false
    {
        return wp_next_scheduled($hook, [BookingStorage::jobReference($hook, $value)]);
    }

    /**
     * Removes both reference-based and pre-migration events for the given target.
     */
    public static function clear(#[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $value): void
    {
        $reference = BookingStorage::jobReference($hook, $value);
        wp_clear_scheduled_hook($hook, [$reference]);
        if ($reference !== $value) {
            wp_clear_scheduled_hook($hook, [$value]);
        }
    }

    /**
     * Resolves a current or already-dispatched legacy argument against stored data.
     *
     * @return string|null Target token/slot, or null for a deleted target.
     */
    public static function resolve(#[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $argument): ?string
    {
        if (!isset(self::STORES[$hook])) {
            throw new AppointmentException('Unsupported appointment job.');
        }
        foreach (array_keys(BookingStorage::get(self::STORES[$hook])) as $value) {
            $value = (string) $value;
            if (hash_equals(BookingStorage::jobReference($hook, $value), $argument)
                || hash_equals($value, $argument)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Dispatches an expiry callback after resolving its non-secret queue reference.
     */
    public static function expirePending(#[\SensitiveParameter] string $argument): void
    {
        self::dispatch(TokenManager::PENDING_EXPIRY_HOOK, $argument, [TokenManager::class, 'expirePending']);
    }

    /**
     * Dispatches an opening email after resolving its non-secret queue reference.
     */
    public static function notifyOpening(#[\SensitiveParameter] string $argument): void
    {
        self::dispatch(BookingOpeningNotifier::CRON_HOOK, $argument, [BookingOpeningNotifier::class, 'notify']);
    }

    /**
     * Dispatches a reminder after resolving its non-secret queue reference.
     */
    public static function sendReminder(#[\SensitiveParameter] string $argument): void
    {
        self::dispatch(Reminder::CRON_HOOK, $argument, [new Reminder(), 'sendReminder']);
    }

    /**
     * Defers an opaque job when storage is temporarily unavailable.
     *
     * WordPress removes one-shot events before invoking their callback. Retrying
     * the existing reference needs no key and writes no personal data. Legacy
     * arguments are never re-persisted; regular maintenance restores those jobs.
     *
     * @param callable(string): void $callback Resolved domain callback.
     */
    private static function dispatch(#[\SensitiveParameter] string $hook, #[\SensitiveParameter] string $argument, callable $callback): void
    {
        try {
            $target = self::resolve($hook, $argument);
            if ($target !== null) {
                $callback($target);
            }
        } catch (AppointmentException) {
            if (preg_match('/^rrze-appt-job:v1:[a-f0-9]{64}$/D', $argument)
                && !wp_next_scheduled($hook, [$argument])) {
                wp_schedule_single_event(time() + 300, $hook, [$argument]);
            }
        }
    }

    /**
     * Replaces legacy cron arguments, retaining timestamps and unrelated events.
     *
     * A conditional write retries concurrent changes to WordPress's shared cron
     * option. No get_option()/update_option() round-trip can reintroduce stale data.
     * Malformed plugin events or unsafe serialized objects stop migration.
     */
    public static function migrate(OptionDatabase $database): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = $database->read('cron');
            if ($raw === null) {
                return;
            }
            $cron = BookingStorage::decodeLegacy($raw);
            foreach ($cron as $timestamp => &$hooks) {
                if (!is_array($hooks)) {
                    continue;
                }
                foreach (self::STORES as $hook => $store) {
                    if (!isset($hooks[$hook])) {
                        continue;
                    }
                    if (!is_array($hooks[$hook])) {
                        throw new AppointmentException('An appointment cron event is malformed.');
                    }
                    foreach ($hooks[$hook] as $eventKey => $event) {
                        if (!is_array($event) || !is_array($event['args'] ?? null)
                            || count($event['args']) !== 1 || !is_string($event['args'][0] ?? null)) {
                            throw new AppointmentException('An appointment cron event is malformed.');
                        }
                        $argument = $event['args'][0];
                        if (str_starts_with($argument, self::PREFIX)) {
                            if (!preg_match('/^rrze-appt-job:v1:[a-f0-9]{64}$/D', $argument)) {
                                throw new AppointmentException('An appointment cron reference is malformed.');
                            }
                            continue;
                        }
                        $event['args'] = [BookingStorage::jobReference($hook, $argument)];
                        unset($hooks[$hook][$eventKey]);
                        $hooks[$hook][md5(serialize($event['args']))] = $event;
                    }
                }
            }
            unset($hooks);
            if ($database->replace('cron', $raw, serialize($cron))) {
                return;
            }
        }
        throw new AppointmentException('The cron queue changed concurrently. Please resume appointment migration.');
    }
}
