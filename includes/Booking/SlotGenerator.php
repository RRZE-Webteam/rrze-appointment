<?php

namespace RRZE\Appointment\Booking;

defined('ABSPATH') || exit;

/**
 * Expands current and legacy appointment schedules into future slot identifiers.
 * Booking window limits and reservations are checked by the callers.
 *
 * @phpstan-type AvailabilityWindow array{
 *     date: string, startTime: mixed, endTime: mixed, duration: mixed, breakDuration: int,
 *     removedSlots: array, extraSlots: array
 * }
 */
class SlotGenerator
{
    /**
     * Generates future slots for the published schedule, mirroring generateTimeSlots() in TypeScript.
     *
     * @param array<string, mixed> $attrs Saved appointment block attributes.
     * @param string $from Inclusive first ISO date.
     * @param string $until Inclusive last ISO date.
     * @return string[] Unique, sorted slots in the format "Y-m-d HH:MM-HH:MM".
     */
    public static function fromAttributes(array $attrs, string $from = '', string $until = '9999-12-31'): array
    {
        return array_keys(self::timestamps($attrs, $from, $until));
    }

    /**
     * Lists candidate months without expanding individual time slots.
     *
     * @param array<string, mixed> $attrs Saved block attributes.
     * @param string $from Inclusive first ISO date.
     * @return string[] Sorted YYYY-MM values.
     */
    public static function months(array $attrs, string $from): array
    {
        $months = [];
        foreach (self::getAvailabilityWindows($attrs, [], $from, '9999-12-31') as $window) {
            $months[substr($window['date'], 0, 7)] = true;
        }
        ksort($months);
        return array_keys($months);
    }

    /**
     * Generates canonical slot identifiers and their absolute start times for an inclusive date range.
     *
     * @param array<string, mixed> $attrs Saved block attributes.
     * @param string $from Inclusive first ISO date.
     * @param string $until Inclusive last ISO date.
     * @return array<string, int> Sorted slot identifiers mapped to Unix timestamps.
     */
    public static function timestamps(array $attrs, string $from, string $until): array
    {
        $dateOverrides = is_array($attrs['dateOverrides'] ?? null)
            ? $attrs['dateOverrides']
            : [];
        $windows = self::getAvailabilityWindows($attrs, $dateOverrides, $from, $until);

        $slots = [];
        $processedExtraDates = [];
        $nowTs = current_time('timestamp', true);
        $timezone = wp_timezone();

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
                $timestamp = self::slotStartTimestamp($date, $start, $timezone);
                if ($timestamp > $nowTs && !isset($removedSlots[$value])) {
                    $slots[$value] = $timestamp;
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
                $timestamp = self::slotStartTimestamp($date, $start, $timezone);
                if ($timestamp > $nowTs && !isset($removedSlots[$value])) {
                    $slots[$value] = $timestamp;
                }
            }
        }

        ksort($slots);
        return $slots;
    }

    /**
     * Selects the window format from the saved block. An empty availability list is authoritative.
     *
     * @param array<string, mixed> $attrs Saved appointment block attributes.
     * @param array<string, mixed> $dateOverrides Per-date settings keyed by calendar date.
     * @param string $from Inclusive first ISO date.
     * @param string $until Inclusive last ISO date.
     * @return iterable<int, AvailabilityWindow> Windows with saved timing values and per-date slot edits.
     */
    private static function getAvailabilityWindows(array $attrs, array $dateOverrides, string $from, string $until): iterable
    {
        if (array_key_exists('availabilities', $attrs) && is_array($attrs['availabilities'])) {
            return self::getWindowsFromEntries($attrs['availabilities'], $dateOverrides, $from, $until);
        }

        return self::getLegacyWindows($attrs, $dateOverrides, $from, $until);
    }

    /**
     * Expands each entry into windows for its included dates, applying extra and removed slots by date.
     *
     * @param array<array-key, mixed> $entries Saved availability entries with optional recurrence dates.
     * @param array<string, mixed> $dateOverrides Slot edits keyed by occurrence date.
     * @param string $from Inclusive first ISO date.
     * @param string $until Inclusive last ISO date.
     * @return iterable<int, AvailabilityWindow> One window per included occurrence of each entry.
     */
    private static function getWindowsFromEntries(array $entries, array $dateOverrides, string $from, string $until): iterable
    {
        // Legacy manual times without an end inherit the first valid window's duration.
        // Use the editor's canonical entry order even for unsorted saved attributes.
        $entries = array_filter($entries, 'is_array');
        usort($entries, static function (array $left, array $right): int {
            foreach (['date', 'startTime', 'id', 'endTime'] as $field) {
                $comparison = strcmp(
                    is_string($left[$field] ?? null) ? $left[$field] : '',
                    is_string($right[$field] ?? null) ? $right[$field] : ''
                );
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return (int) ($left['duration'] ?? 30) <=> (int) ($right['duration'] ?? 30);
        });
        foreach ($entries as $entry) {
            if (!self::isDate($entry['date'] ?? null)) {
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
                if ($occurrenceDate < $from || $occurrenceDate > $until || isset($excludedDates[$occurrenceDate])) {
                    continue;
                }
                $override = is_array($dateOverrides[$occurrenceDate] ?? null)
                    ? $dateOverrides[$occurrenceDate]
                    : [];
                yield [
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
    }

    /**
     * Builds windows from legacy calendar dates. Day-specific settings override matching series settings.
     *
     * @param array<string, mixed> $attrs Legacy calendar and timing attributes.
     * @param array<string, mixed> $dateOverrides Settings keyed by series anchor or calendar date.
     * @param string $from Inclusive first ISO date.
     * @param string $until Inclusive last ISO date.
     * @return iterable<int, AvailabilityWindow> One window per calendar date.
     */
    private static function getLegacyWindows(array $attrs, array $dateOverrides, string $from, string $until): iterable
    {
        $startTime = $attrs['startTime'] ?? '09:00';
        $endTime = $attrs['endTime'] ?? '17:00';
        $duration = $attrs['duration'] ?? 30;
        $breakDuration = (int) ($attrs['breakDuration'] ?? 0);

        foreach (self::getCalendarDates($attrs) as $date) {
            if ($date < $from || $date > $until) {
                continue;
            }
            $recurrenceAnchor = self::getRecurrenceAnchor($attrs, $date);
            $seriesOverride = (
                $recurrenceAnchor !== ''
                && is_array($dateOverrides[$recurrenceAnchor] ?? null)
            ) ? $dateOverrides[$recurrenceAnchor] : [];
            $dateOverride = is_array($dateOverrides[$date] ?? null)
                ? $dateOverrides[$date]
                : [];
            $override = array_merge($seriesOverride, $dateOverride);
            yield [
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

    }

    /**
     * Finds the legacy series whose settings apply to an occurrence.
     *
     * @param array<string, mixed> $attrs Legacy attributes with multiple rules or a single recurrence.
     * @param string $date Occurrence date in Y-m-d format.
     * @return string Series anchor date, or an empty string when the date belongs to no series.
     */
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

    /**
     * Uses the selected legacy dates, falling back to the configured start and end dates.
     *
     * @param array<string, mixed> $attrs Legacy calendar attributes.
     * @return string[] Calendar dates in Y-m-d format.
     */
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

    /**
     * Reads the first ten characters of each date string and drops duplicates and invalid dates.
     *
     * @param mixed $values Candidate date list from saved attributes.
     * @return string[] Sorted calendar dates in Y-m-d format, or an empty list for a non-array input.
     */
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

    /**
     * Checks that a value represents an existing calendar day in the exact Y-m-d format.
     *
     * @param mixed $value Candidate date from saved attributes.
     */
    private static function isDate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Expands an inclusive legacy date range to a maximum of 366 days.
     *
     * @param string $start First calendar date in Y-m-d format.
     * @param string $end Last calendar date in Y-m-d format.
     * @return string[] Consecutive dates, or an empty list when the end precedes the start.
     */
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

    /**
     * Converts the hour and minute components of a saved time to minutes since midnight.
     *
     * @param string $time Saved time with hour and minute separated by a colon.
     * @return int|null Null when the separator is missing or a component's integer value is outside the clock range.
     */
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

    /**
     * Validates the saved slot duration before it is used to advance through a window.
     *
     * @param mixed $value Duration supplied by the saved schedule.
     * @return int|null A positive whole number of minutes, or null when validation fails.
     */
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

    /**
     * Formats minutes since midnight for a slot identifier, including 24:00 at the end of the day.
     */
    private static function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Resolves a slot start in the WordPress site timezone.
     *
     * @param string $date Calendar date in Y-m-d format.
     * @param string $time Local start time in HH:MM format.
     * @param \DateTimeZone $timezone WordPress site timezone.
     * @return int Unix timestamp, or zero if the date and time cannot be parsed.
     */
    private static function slotStartTimestamp(string $date, string $time, \DateTimeZone $timezone): int
    {
        $dateTime = \DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $timezone);
        return $dateTime ? $dateTime->getTimestamp() : 0;
    }
}
