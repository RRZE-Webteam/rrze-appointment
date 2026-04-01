<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class SlotGenerator
{
    /**
     * Generates slot value strings from block attributes.
     * Mirrors the JS generateTimeSlots() logic in utils.js.
     * Returns array of slot strings like "YYYY-MM-DD HH:MM-HH:MM".
     */
    public static function fromAttributes(array $attrs): array
    {
        $startTime     = $attrs['startTime']     ?? '09:00';
        $endTime       = $attrs['endTime']       ?? '17:00';
        $duration      = (int) ($attrs['duration']      ?? 30);
        $breakDuration = (int) ($attrs['breakDuration'] ?? 0);
        $dateOverrides = is_array($attrs['dateOverrides'] ?? null) ? $attrs['dateOverrides'] : [];

        if ($duration <= 0 || $duration % 15 !== 0) return [];
        if ($breakDuration < 0 || $breakDuration > 55 || $breakDuration % 5 !== 0) return [];

        $dates = self::getCalendarDates($attrs);
        if (empty($dates)) return [];

        $slots = [];

        foreach ($dates as $dateString) {
            $override      = $dateOverrides[$dateString] ?? [];
            $slotDuration  = isset($override['duration'])      ? (int) $override['duration']      : $duration;
            $pauseMinutes  = isset($override['breakDuration']) ? (int) $override['breakDuration'] : $breakDuration;
            $startMinutes  = self::timeToMinutes($override['startTime'] ?? $startTime);
            $endMinutes    = self::timeToMinutes($override['endTime']   ?? $endTime);

            if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) continue;

            $removedSlots = array_flip(is_array($override['removedSlots'] ?? null) ? $override['removedSlots'] : []);
            $extraSlots   = is_array($override['extraSlots'] ?? null) ? $override['extraSlots'] : [];
            $seen         = [];

            $slotStart = $startMinutes;
            while ($slotStart + $slotDuration <= $endMinutes) {
                $slotEnd = $slotStart + $slotDuration;
                $value   = $dateString . ' ' . self::minutesToTime($slotStart) . '-' . self::minutesToTime($slotEnd);
                if (!isset($removedSlots[$value]) && !isset($seen[$value])) {
                    $slots[]      = $value;
                    $seen[$value] = true;
                }
                $slotStart += $slotDuration + $pauseMinutes;
            }

            foreach ($extraSlots as $entry) {
                $hasPipe    = is_string($entry) && strpos($entry, '|') !== false;
                $extraStart = $hasPipe ? explode('|', $entry)[0] : $entry;
                $extraEnd   = $hasPipe ? explode('|', $entry)[1] : null;
                $esMin      = self::timeToMinutes($extraStart);
                if ($esMin === null) continue;
                $eeMin = $extraEnd ? self::timeToMinutes($extraEnd) : $esMin + $slotDuration;
                if ($eeMin === null || $eeMin > 1440) continue;
                $value = $dateString . ' ' . self::minutesToTime($esMin) . '-' . self::minutesToTime($eeMin);
                if (!isset($removedSlots[$value]) && !isset($seen[$value])) {
                    $slots[]      = $value;
                    $seen[$value] = true;
                }
            }

            if (count($slots) >= 1000) break;
        }

        return $slots;
    }

    private static function getCalendarDates(array $attrs): array
    {
        $selected = array_filter(array_map(
            fn($d) => is_string($d) ? substr($d, 0, 10) : '',
            (array) ($attrs['selectedDates'] ?? [])
        ));
        $selected = array_values(array_unique($selected));
        sort($selected);
        if (!empty($selected)) return $selected;

        $startDate = $attrs['startDate'] ?? '';
        if (!$startDate) return [];
        $endDate = (!empty($attrs['useEndDate']) && !empty($attrs['endDate']))
            ? $attrs['endDate']
            : $startDate;

        return self::getDateRange($startDate, $endDate);
    }

    private static function getDateRange(string $start, string $end): array
    {
        $dates   = [];
        $current = new \DateTime($start);
        $last    = new \DateTime($end);
        while ($current <= $last) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
            if (count($dates) >= 366) break;
        }
        return $dates;
    }

    private static function timeToMinutes(string $time): ?int
    {
        $parts = explode(':', $time);
        if (count($parts) < 2) return null;
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) return null;
        return $h * 60 + $m;
    }

    private static function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
