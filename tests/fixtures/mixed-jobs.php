<?php

/** Isolated delivery and failure simulation for V2 scheduling. No real mail or database connections. */
namespace {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('ABSPATH', __DIR__);
    require_once dirname(__DIR__, 2) . '/includes/AppointmentException.php';

    final class WP_Error {}
    $clock = 1800000000;
    $events = [];
    $actions = [];
    $deliveries = [];
    $dispatches = 0;
    $retryOpening = false;
    $crashAfterSend = false;
    $reenter = false;

    /** Records which hooks a plugin startup registers. */
    function add_action($hook, $callback, $priority = 10): void { $GLOBALS['actions'][] = $hook; }
    /** Provides a deterministic site-local date for reminders. */
    function wp_date($format, $at): string { return gmdate($format, $at); }
    /** Reads the first matching scheduled event. */
    function wp_next_scheduled($hook, $args = []): int|false
    {
        foreach ($GLOBALS['events'] as $time => $hooks) {
            if (isset($hooks[$hook][md5(serialize($args))])) {
                return $time;
            }
        }
        return false;
    }
    /** Mimics WordPress argument hashes and retains unrelated events. */
    function wp_schedule_single_event($time, $hook, $args = [], $errors = false): bool
    {
        $GLOBALS['events'][$time][$hook][md5(serialize($args))] = ['schedule' => false, 'args' => $args];
        ksort($GLOBALS['events']);
        return true;
    }
    /** Removes matching events only. */
    function wp_clear_scheduled_hook($hook, $args = []): void
    {
        foreach ($GLOBALS['events'] as $time => &$hooks) {
            unset($hooks[$hook][md5(serialize($args))]);
            if (empty($hooks[$hook])) {
                unset($hooks[$hook]);
            }
            if (!$hooks) {
                unset($GLOBALS['events'][$time]);
            }
        }
        unset($hooks);
    }
}

namespace RRZE\Appointment\Storage {
    use RRZE\Appointment\AppointmentException;
    /** Exposes the test clock to the scheduler. */
    function time(): int { return $GLOBALS['clock']; }
    /** In-memory analogue of committed records; the real transaction is tested on InnoDB separately. */
    final class BookingStorage {
        public static array $records = [];
        public static bool $marker = false;
        public static bool $outage = false;
        public static int $reads = 0;

        /** Simulates a cache-free cheap marker check. */
        public static function hasMixedState(): bool { return self::$marker; }
        /** Reopens committed state, including a temporary unavailable-key failure. */
        public static function managedJobs(): array
        {
            self::$reads++;
            if (self::$outage) {
                throw new AppointmentException('Synthetic storage outage.');
            }
            return self::$records;
        }
        /** Stores a claim/ack and optionally simulates failure just after an SMTP delivery. */
        public static function setRecordJobs(string $id, array $jobs): void
        {
            BookingScheduler::validateJobs(self::$records[$id]['type'], $jobs);
            if ($GLOBALS['crashAfterSend'] && $GLOBALS['deliveries']) {
                $GLOBALS['crashAfterSend'] = false;
                throw new AppointmentException('Synthetic missing delivery acknowledgement.');
            }
            self::$records[$id]['jobs'] = $jobs;
        }
        /** Finds the identity of an active record. */
        public static function recordId(string $type, string $target): ?string
        {
            foreach (self::$records as $id => $record) {
                if ($record['type'] === $type && $record['target'] === $target) {
                    return $id;
                }
            }
            return null;
        }
        /** Models a legacy opening subscription whose first V2 change is a retry at a future time. */
        public static function promoteForJob(string $type, string $target, int $timestamp): ?string
        {
            if ($type !== 'opening' || $target !== 'retrying-legacy') {
                return null;
            }
            $entry = ['opens_at' => $GLOBALS['clock'] - 60, 'closes_at' => $timestamp + 600, 'notified_at' => 0];
            self::$records['adopted'] = [
                'type' => $type, 'target' => $target,
                'jobs' => BookingScheduler::jobsForRecord($type, $target, $entry, null, true, $timestamp),
            ];
            return 'adopted';
        }
        /** Returns the combined keys needed to resolve the older callback shapes. */
        public static function get(string $store): array
        {
            $data = [];
            foreach (self::$records as $record) {
                $data[$record['target']] = [];
            }
            return $data;
        }
        /** Derives a deterministic synthetic V1 reference. */
        public static function jobReference(string $hook, string $target): string
        {
            return 'rrze-appt-job:v1:' . hash('sha256', $hook . $target);
        }
        /** Returns a snapshot used by promotions. */
        public static function cronSnapshot(): array { return $GLOBALS['events']; }
        /** Ends a work unit in this in-memory fixture. */
        public static function release(): void {}
    }
}

namespace RRZE\Appointment\Booking {
    use RRZE\Appointment\Storage\BookingStorage;
    /** Deletes pending owners just like a committed expiry. */
    final class TokenManager {
        public const PENDING_EXPIRY_HOOK = 'rrze_appointment_expire_pending';
        public const EXPIRY_SCHEDULE_DELAY = 60;
        /** Expires the target so a stale callback cannot acknowledge a replacement record. */
        public static function expirePending($token): void
        {
            $id = BookingStorage::recordId('pending', $token);
            unset(BookingStorage::$records[$id]);
        }
    }
}

namespace RRZE\Appointment\Notification {
    use RRZE\Appointment\Storage\BookingScheduler;
    use RRZE\Appointment\Storage\BookingStorage;
    /** Records actual delivery, including deliberate re-entry while a claim is live. */
    final class Reminder {
        public const CRON_HOOK = 'rrze_appointment_send_reminder';
        /** Supplies one role with a deterministic due date. */
        public static function plannedTimes($slot): array { return ['host' => $GLOBALS['reminderDue']]; }
        /** Computes the receipt day on the fixture clock. */
        public static function today(): string { return gmdate('Y-m-d', $GLOBALS['clock']); }
        /** Exercises a legacy callback re-entering a claimed V2 job. */
        public function sendToRecipient($slot, $role): bool
        {
            if ($GLOBALS['reenter']) {
                $GLOBALS['reenter'] = false;
                BookingScheduler::sendReminder($slot);
            }
            $GLOBALS['dispatches']++;
            $GLOBALS['deliveries'][] = [$slot, $role];
            return true;
        }
        /** Fails if an already adopted booking bypasses its durable jobs. */
        public function sendReminder($slot): void { throw new \LogicException('Unexpected legacy dispatch.'); }
    }

    /** Simulates a claimed opening subscription needing a later delivery while its slot is pending. */
    final class BookingOpeningNotifier {
        public const CRON_HOOK = 'rrze_appointment_booking_opened';
        /** Requests a retry or marks completion just as the domain callback would. */
        public static function notify($token): void
        {
            if ($GLOBALS['retryOpening']) {
                $GLOBALS['retryOpening'] = false;
                if (!BookingScheduler::next(self::CRON_HOOK, $token)) {
                    BookingScheduler::schedule($GLOBALS['clock'] + 120, self::CRON_HOOK, $token);
                }
            } else {
                $GLOBALS['deliveries'][] = [$token, 'open'];
                $id = BookingStorage::recordId('opening', $token);
                BookingStorage::setRecordJobs($id, []);
            }
        }
    }
}

namespace {
    use RRZE\Appointment\AppointmentException;
    use RRZE\Appointment\Storage\BookingStorage;
    use RRZE\Appointment\Storage\BookingScheduler;
    use RRZE\Appointment\Notification\Reminder;
    use RRZE\Appointment\Notification\BookingOpeningNotifier;
    use RRZE\Appointment\Booking\TokenManager;

    require dirname(__DIR__, 2) . '/includes/Storage/RecordJobs.php';
    require dirname(__DIR__, 2) . '/includes/Storage/BookingScheduler.php';

    $wake = BookingScheduler::WAKE_HOOK;
    $foreignDue = $clock + 17;
    wp_schedule_single_event($foreignDue, 'another_plugin', ['retained']);
    BookingScheduler::register();
    $results['inactive'] = !BookingStorage::$reads && !wp_next_scheduled($wake);
    BookingStorage::$marker = true;

    // Both the undispatched plaintext and V1 reminder retain the original time without a cron write.
    $first = $clock + 900;
    $second = $clock + 3600;
    $slot = 'legacy-slot';
    $v1 = BookingStorage::jobReference(Reminder::CRON_HOOK, $slot);
    wp_schedule_single_event($first, Reminder::CRON_HOOK, [$slot]);
    wp_schedule_single_event($second, Reminder::CRON_HOOK, [$v1]);
    $before = $events;
    $legacy = BookingScheduler::jobsForRecord('booking', $slot, [], null, true);
    $results['adoptedTimes'] = array_values(array_unique(array_column($legacy, 'due')));
    $results['adoptionIsReadOnly'] = $before === $events;

    $reminderDue = $clock + 120;
    $target = 'new-slot';
    $jobs = BookingScheduler::jobsForRecord('booking', $target, [], null, false);
    BookingStorage::$records['id1'] = ['type' => 'booking', 'target' => $target, 'jobs' => $jobs];
    BookingScheduler::repairWake();
    wp_clear_scheduled_hook($wake); // A foreign stale writer lost the whole wake.
    BookingScheduler::register();
    $results['repaired'] = wp_next_scheduled($wake) === $reminderDue
        && $events[$reminderDue][$wake][md5(serialize([]))]['args'] === [];

    BookingScheduler::sendReminder($target); // A premature or stale event is harmless.
    $results['early'] = !$deliveries;
    $clock = $reminderDue;
    $reenter = true;
    BookingScheduler::sendReminder(BookingStorage::jobReference(Reminder::CRON_HOOK, $target));
    BookingScheduler::run();
    BookingScheduler::sendReminder($target);
    BookingScheduler::dailyReminder($target);
    $results['singleDelivery'] = $deliveries === [[$target, 'host']] && !wp_next_scheduled($wake);

    // In a fresh record, interrupt between SMTP acceptance and the corresponding DB receipt.
    $deliveries = [];
    $reminderDue = $clock + 1;
    BookingStorage::$records['id2'] = [
        'type' => 'booking', 'target' => 'replacement-slot',
        'jobs' => BookingScheduler::jobsForRecord('booking', 'replacement-slot', [], null, false),
    ];
    $clock = $reminderDue;
    $crashAfterSend = true;
    BookingScheduler::run();
    $afterCrash = reset(BookingStorage::$records['id2']['jobs']);
    $results['persistedClaim'] = (bool) $afterCrash['claim'] && $afterCrash['run_at'] === $clock + 300;
    // A consumed wake and temporarily missing key still leave the claim and payload intact.
    $unchanged = BookingStorage::$records;
    BookingStorage::$outage = true;
    wp_clear_scheduled_hook($wake);
    BookingScheduler::run();
    $results['outage'] = BookingStorage::$records === $unchanged && wp_next_scheduled($wake) === $clock + 300;
    BookingStorage::$outage = false;
    $clock += 299;
    BookingScheduler::run();
    $results['lateAckIsNotReplayedEarly'] = count($deliveries) === 1;
    $clock++;
    BookingScheduler::run();
    $results['uncertainDeliveryRetries'] = count($deliveries) === 2 && !wp_next_scheduled($wake);

    // Simulate a busy slot and reschedule inside the opening callback before its original ACK.
    $token = 'opening-token';
    $state = ['opens_at' => $clock, 'closes_at' => $clock + 600, 'notified_at' => 0];
    BookingStorage::$records['id3'] = ['type' => 'opening', 'target' => $token,
        'jobs' => BookingScheduler::jobsForRecord('opening', $token, $state, null, false)];
    $retryOpening = true;
    BookingScheduler::run();
    $results['reschedulePreserved'] = BookingScheduler::next(BookingOpeningNotifier::CRON_HOOK, $token) === $clock + 120;
    $clock += 120;
    BookingScheduler::run();
    BookingScheduler::notifyOpening($token);
    $results['openingSent'] = end($deliveries) === [$token, 'open'] && count($deliveries) === 3;

    $pending = BookingScheduler::jobsForRecord('pending', 'pending', ['expires' => $clock + 90], null, false);
    $results['pendingDeadline'] = reset($pending)['due'] === $clock + 150;
    BookingStorage::$records['id4'] = ['type' => 'pending', 'target' => 'pending', 'jobs' => $pending];
    BookingScheduler::expirePending('pending');
    $results['earlyExpiryPreserved'] = isset(BookingStorage::$records['id4']);
    $clock += 150;
    BookingScheduler::run();
    $results['expiryRemoved'] = !isset(BookingStorage::$records['id4']);
    $results['foreignPreserved'] = wp_next_scheduled('another_plugin', ['retained']) === $foreignDue;
    BookingScheduler::schedule($clock + 45, BookingOpeningNotifier::CRON_HOOK, 'retrying-legacy');
    $results['retryPromotesAtRequestedTime'] = BookingScheduler::next(BookingOpeningNotifier::CRON_HOOK, 'retrying-legacy') === $clock + 45
        && !wp_next_scheduled(BookingOpeningNotifier::CRON_HOOK, ['retrying-legacy']);

    BookingStorage::$records['old-booking-id'] = ['type' => 'booking', 'target' => $slot, 'jobs' => $legacy];
    $deliveries = [];
    $clock = $second;
    BookingScheduler::sendReminder($v1);
    BookingScheduler::sendReminder($slot);
    $results['adoptedCallbacksSendOncePerRole'] = $deliveries === [[$slot, 'host'], [$slot, 'booker']];

    try {
        BookingScheduler::validateJobs('opening', $pending);
        $results['invalidActionRejected'] = false;
    } catch (AppointmentException) {
        $results['invalidActionRejected'] = true;
    }
    echo json_encode($results, JSON_THROW_ON_ERROR);
}
