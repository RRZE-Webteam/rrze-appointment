<?php

namespace RRZE\Appointment\Storage;

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Notification\Reminder;

defined('ABSPATH') || exit;

/** The schedule and delivery receipts embedded in one authenticated V2 record. */
final class RecordJobs
{
    /** The action names allowed for each record type. */
    private const ACTIONS = [
        'pending' => ['expire'],
        'opening' => ['notify'],
        'booking' => ['host', 'booker'],
    ];

    /**
     * Builds work for a newly created or adopted record and retains outstanding retries on edits.
     *
     * @param string $type Storage record type.
     * @param string $target Slot or action token.
     * @param array $entry Domain fields for this record.
     * @param array|null $previous Jobs retained from the same record and lifecycle stage.
     * @param int[] $legacyTimes Existing WordPress event times belonging to this target.
     * @return array<string,array> Jobs for the transaction containing the domain change.
     */
    public static function forRecord(
        string $type, #[\SensitiveParameter] string $target, #[\SensitiveParameter] array $entry,
        ?array $previous, array $legacyTimes
    ): array {
        if (!isset(self::ACTIONS[$type])) {
            throw new AppointmentException('Unsupported appointment job owner.');
        }
        $deadline = match ($type) {
            'pending' => self::timestamp($entry['expires'] ?? null) + TokenManager::EXPIRY_SCHEDULE_DELAY,
            'opening' => self::timestamp($entry['opens_at'] ?? null),
            default => null,
        };
        if ($type === 'opening' && !empty($entry['notified_at'])) {
            return [];
        }
        if ($previous !== null) {
            self::validate($type, $previous);
            return $previous;
        }
        $jobs = [];
        if ($legacyTimes) {
            foreach ($legacyTimes as $time) {
                foreach (self::ACTIONS[$type] as $action) {
                    self::add($jobs, $action, $time);
                }
            }
        } elseif ($type === 'pending') {
            self::add($jobs, 'expire', $deadline);
        } elseif ($type === 'opening') {
            self::add($jobs, 'notify', $deadline);
        } else {
            foreach (Reminder::plannedTimes($target) as $role => $time) {
                if ($time > time()) {
                    self::add($jobs, $role, $time);
                }
            }
        }
        return $jobs;
    }

    /** Validates historical numeric strings and new integer timestamps before adopting a record. */
    private static function timestamp(#[\SensitiveParameter] mixed $value): int
    {
        $time = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - TokenManager::EXPIRY_SCHEDULE_DELAY]])
            : false;
        if ($time === false) {
            throw new AppointmentException('An appointment job has an invalid due time.');
        }
        return $time;
    }

    /**
     * Adds one action at its original due time. A retry or receipt keeps its identity.
     *
     * @param array $jobs Mutable job collection.
     * @param string $action expire, notify, host or booker.
     * @param int $time UNIX timestamp of the intended event.
     */
    public static function add(array &$jobs, string $action, int $time): void
    {
        if ($time < 1) {
            throw new AppointmentException('An appointment job has an invalid due time.');
        }
        $jobs[$action . ':' . $time] ??= [
            'action' => $action, 'due' => $time, 'run_at' => $time,
            'claim' => '', 'sent_on' => '',
        ];
    }

    /**
     * Rejects unknown actions or incoherent receipts before interpreting an authenticated record.
     *
     * @param string $type Expected owner type.
     * @param array $jobs Decoded V2 record jobs.
     */
    public static function validate(string $type, #[\SensitiveParameter] array $jobs): void
    {
        $actions = self::ACTIONS[$type] ?? [];
        foreach ($jobs as $key => $job) {
            if (!is_array($job) || count($job) !== 5 || !in_array($job['action'] ?? null, $actions, true)
                || !is_int($job['due'] ?? null) || $job['due'] < 1 || $key !== $job['action'] . ':' . $job['due']
                || !is_int($job['run_at'] ?? null) || $job['run_at'] < 0
                || !is_string($job['claim'] ?? null) || ($job['claim'] !== '' && !preg_match('/^[a-f0-9]{32}$/D', $job['claim']))
                || !is_string($job['sent_on'] ?? null)
                || ($job['sent_on'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $job['sent_on']))
                || ($job['run_at'] > 0 && $job['sent_on'] !== '')
                || ($job['run_at'] === 0 && ($type !== 'booking' || $job['claim'] !== ''))) {
                throw new AppointmentException('An appointment record contains an invalid job.');
            }
        }
    }

    /**
     * Checks whether this role already received today's reminder for this same booking ID.
     *
     * @param array $jobs Jobs and completed receipts for a single record.
     * @param string $role host or booker.
     * @param string $day Site-local delivery date.
     */
    public static function sentOn(array $jobs, string $role, string $day): bool
    {
        foreach ($jobs as $job) {
            if ($job['action'] === $role && $job['sent_on'] === $day) {
                return true;
            }
        }
        return false;
    }
}
