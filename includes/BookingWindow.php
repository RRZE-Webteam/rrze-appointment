<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Evaluates the opening and closing boundaries of a booking window.
 *
 * All limits are expressed in whole minutes relative to the appointment's
 * start time. Negative limits are treated as zero.
 */
final class BookingWindow
{
    private const SECONDS_PER_MINUTE = 60;

    /**
     * Determines whether the slot has reached its inclusive booking cutoff.
     *
     * @param \DateTimeInterface $slotStart    Appointment start time.
     * @param \DateTimeInterface $now          Time against which to evaluate the slot.
     * @param int                $bookingCutoff Minutes before the slot when booking closes.
     */
    public static function isClosed(
        \DateTimeInterface $slotStart,
        \DateTimeInterface $now,
        int $bookingCutoff
    ): bool {
        return self::secondsUntil($slotStart, $now)
            <= self::minutesToSeconds($bookingCutoff);
    }

    /**
     * Determines whether the slot is still beyond its booking horizon.
     *
     * A maximum advance of zero disables the opening restriction. The exact
     * opening boundary is considered open.
     *
     * @param \DateTimeInterface $slotStart        Appointment start time.
     * @param \DateTimeInterface $now              Time against which to evaluate the slot.
     * @param int                $bookingMaxAdvance Earliest booking time, in minutes before the slot.
     */
    public static function isNotOpen(
        \DateTimeInterface $slotStart,
        \DateTimeInterface $now,
        int $bookingMaxAdvance
    ): bool {
        $maxAdvanceSeconds = self::minutesToSeconds($bookingMaxAdvance);

        return $maxAdvanceSeconds > 0
            && self::secondsUntil($slotStart, $now) > $maxAdvanceSeconds;
    }

    /**
     * Returns the signed number of seconds until an appointment starts.
     */
    private static function secondsUntil(
        \DateTimeInterface $slotStart,
        \DateTimeInterface $now
    ): int {
        return $slotStart->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Converts a non-negative minute limit to seconds.
     */
    private static function minutesToSeconds(int $minutes): int
    {
        return max(0, $minutes) * self::SECONDS_PER_MINUTE;
    }
}
