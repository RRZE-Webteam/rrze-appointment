<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class SlotGenerator
{
    /**
     * Generates slot value strings from block attributes.
     * Mirrors the TypeScript generateTimeSlots() logic.
     *
     * @return string[]
     */
    public static function fromAttributes(array $attrs): array
    {
        $dateOverrides = is_array($attrs['dateOverrides'] ?? null)
            ? $attrs['dateOverrides']
            : [];
        $windows = self::getAvailabilityWindows($attrs, $dateOverrides);
        if (empty($windows)) {
            return [];
        }

        $slots = [];
        $seen = [];
        $processedExtraDates = [];
        $nowTs = current_time('timestamp');

        foreach ($windows as $window) {
            $date = $window['date'];
            $duration = self::positiveWholeMinutes($window['duration']);
            $breakDuration = (int) $window['breakDuration'];
            $startMinutes = self::timeToMinutes($window['startTime']);
            $endMinutes = self::timeToMinutes($window['endTime']);

            if ($duration === null) {
                continue;
            }
            if ($breakDuration < 0 || $breakDuration > 55 || $breakDuration % 5 !== 0) {
                continue;
            }
            if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
                continue;
            }

            $removedSlots = array_flip($window['removedSlots']);
            $slotStart = $startMinutes;
            while ($slotStart + $duration <= $endMinutes) {
                $slotEnd = $slotStart + $duration;
                $start = self::minutesToTime($slotStart);
                $value = $date . ' ' . $start . '-' . self::minutesToTime($slotEnd);
                if (
                    self::slotStartTimestamp($date, $start) > $nowTs
                    && !isset($removedSlots[$value])
                    && !isset($seen[$value])
                ) {
                    $slots[] = $value;
                    $seen[$value] = true;
                }
                $slotStart += $duration + $breakDuration;
            }

            if (isset($processedExtraDates[$date])) {
                continue;
            }
            $processedExtraDates[$date] = true;
            foreach ($window['extraSlots'] as $entry) {
                $hasPipe = is_string($entry) && strpos($entry, '|') !== false;
                $parts = $hasPipe ? explode('|', $entry, 2) : [$entry];
                $extraStart = $parts[0] ?? '';
                $extraEnd = $parts[1] ?? null;
                $extraStartMinutes = is_string($extraStart)
                    ? self::timeToMinutes($extraStart)
                    : null;
                if ($extraStartMinutes === null) {
                    continue;
                }
                $extraEndMinutes = is_string($extraEnd)
                    ? self::timeToMinutes($extraEnd)
                    : $extraStartMinutes + $duration;
                if ($extraEndMinutes === null || $extraEndMinutes > 1440) {
                    continue;
                }

                $start = self::minutesToTime($extraStartMinutes);
                $value = $date . ' ' . $start . '-' . self::minutesToTime($extraEndMinutes);
                if (
                    self::slotStartTimestamp($date, $start) > $nowTs
                    && !isset($removedSlots[$value])
                    && !isset($seen[$value])
                ) {
                    $slots[] = $value;
                    $seen[$value] = true;
                }
            }

            if (count($slots) >= 1000) {
                break;
            }
        }

        sort($slots);
        return array_slice($slots, 0, 1000);
    }

    private static function getAvailabilityWindows(array $attrs, array $dateOverrides): array
    {
        if (array_key_exists('availabilities', $attrs) && is_array($attrs['availabilities'])) {
            $windows = [];
            foreach ($attrs['availabilities'] as $entry) {
                if (!is_array($entry) || !self::isDate($entry['date'] ?? null)) {
                    continue;
                }

                $date = $entry['date'];
                $recurrence = is_array($entry['recurrence'] ?? null)
                    ? $entry['recurrence']
                    : [];
                $dates = [$date];
                if (!empty($recurrence['freq']) && is_array($recurrence['dates'] ?? null)) {
                    $dates = $recurrence['dates'];
                }
                $dates = self::normalizeDates($dates);
                $excludedDates = array_flip(self::normalizeDates($recurrence['excludedDates'] ?? []));

                foreach ($dates as $occurrenceDate) {
                    if (isset($excludedDates[$occurrenceDate])) {
                        continue;
                    }
                    $override = is_array($dateOverrides[$occurrenceDate] ?? null)
                        ? $dateOverrides[$occurrenceDate]
                        : [];
                    $windows[] = [
                        'date' => $occurrenceDate,
                        'startTime' => is_string($entry['startTime'] ?? null)
                            ? $entry['startTime']
                            : '09:00',
                        'endTime' => is_string($entry['endTime'] ?? null)
                            ? $entry['endTime']
                            : '17:00',
                        'duration' => $entry['duration'] ?? 30,
                        'breakDuration' => (int) ($entry['breakDuration'] ?? 0),
                        'removedSlots' => is_array($override['removedSlots'] ?? null)
                            ? $override['removedSlots']
                            : [],
                        'extraSlots' => is_array($override['extraSlots'] ?? null)
                            ? $override['extraSlots']
                            : [],
                    ];
                }
            }
            return $windows;
        }

        $startTime = $attrs['startTime'] ?? '09:00';
        $endTime = $attrs['endTime'] ?? '17:00';
        $duration = $attrs['duration'] ?? 30;
        $breakDuration = (int) ($attrs['breakDuration'] ?? 0);
        $windows = [];

        foreach (self::getCalendarDates($attrs) as $date) {
            $recurrenceAnchor = self::getRecurrenceAnchor($attrs, $date);
            $seriesOverride = (
                $recurrenceAnchor !== ''
                && is_array($dateOverrides[$recurrenceAnchor] ?? null)
            ) ? $dateOverrides[$recurrenceAnchor] : [];
            $dateOverride = is_array($dateOverrides[$date] ?? null)
                ? $dateOverrides[$date]
                : [];
            $override = array_merge($seriesOverride, $dateOverride);
            $windows[] = [
                'date' => $date,
                'startTime' => $override['startTime'] ?? $startTime,
                'endTime' => $override['endTime'] ?? $endTime,
                'duration' => isset($override['duration'])
                    ? $override['duration']
                    : $duration,
                'breakDuration' => isset($override['breakDuration'])
                    ? (int) $override['breakDuration']
                    : $breakDuration,
                'removedSlots' => is_array($override['removedSlots'] ?? null)
                    ? $override['removedSlots']
                    : [],
                'extraSlots' => is_array($override['extraSlots'] ?? null)
                    ? $override['extraSlots']
                    : [],
            ];
        }

        return $windows;
    }

    private static function getRecurrenceAnchor(array $attrs, string $date): string
    {
        $rules = is_array($attrs['recurrences'] ?? null) ? $attrs['recurrences'] : [];
        foreach ($rules as $anchor => $rule) {
            $ruleDates = is_array($rule['dates'] ?? null) ? $rule['dates'] : [];
            if (in_array($date, $ruleDates, true)) {
                return (string) $anchor;
            }
        }

        $legacyRule = is_array($attrs['recurrence'] ?? null) ? $attrs['recurrence'] : [];
        $legacyDates = is_array($legacyRule['dates'] ?? null) ? $legacyRule['dates'] : [];
        if (!in_array($date, $legacyDates, true)) {
            return '';
        }

        return (string) ($legacyRule['anchor'] ?? ($legacyDates[0] ?? ''));
    }

    private static function getCalendarDates(array $attrs): array
    {
        $selected = self::normalizeDates($attrs['selectedDates'] ?? []);
        if (!empty($selected)) {
            return $selected;
        }

        $startDate = $attrs['startDate'] ?? '';
        if (!self::isDate($startDate)) {
            return [];
        }
        $endDate = (!empty($attrs['useEndDate']) && self::isDate($attrs['endDate'] ?? null))
            ? $attrs['endDate']
            : $startDate;

        return self::getDateRange($startDate, $endDate);
    }

    private static function normalizeDates($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $dates = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $date = substr($value, 0, 10);
                if (self::isDate($date)) {
                    $dates[$date] = true;
                }
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        return $dates;
    }

    private static function isDate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function getDateRange(string $start, string $end): array
    {
        $dates = [];
        $current = new \DateTime($start);
        $last = new \DateTime($end);
        while ($current <= $last) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
            if (count($dates) >= 366) {
                break;
            }
        }
        return $dates;
    }

    private static function timeToMinutes(string $time): ?int
    {
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return null;
        }
        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }
        return $hours * 60 + $minutes;
    }

    private static function positiveWholeMinutes($value): ?int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        $minutes = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $minutes === false ? null : $minutes;
    }

    private static function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private static function slotStartTimestamp(string $date, string $time): int
    {
        $dateTime = \DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time, wp_timezone());
        return $dateTime ? $dateTime->getTimestamp() : 0;
    }
}
