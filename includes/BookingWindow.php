<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

final class BookingWindow
{
    private const SECONDS_PER_MINUTE = 60;

    public static function isClosed(
        \DateTimeInterface $slotStart,
        \DateTimeInterface $now,
        int $bookingCutoff
    ): bool {
        $cutoff = max(0, $bookingCutoff);
        return $slotStart->getTimestamp() - $now->getTimestamp()
            <= $cutoff * self::SECONDS_PER_MINUTE;
    }

    public static function isNotOpen(
        \DateTimeInterface $slotStart,
        \DateTimeInterface $now,
        int $bookingMaxAdvance
    ): bool {
        $maxAdvance = max(0, $bookingMaxAdvance);
        return $maxAdvance > 0
            && $slotStart->getTimestamp() - $now->getTimestamp()
                > $maxAdvance * self::SECONDS_PER_MINUTE;
    }
}
