<?php

namespace RRZE\Appointment\Booking;

use RRZE\Appointment\Storage\BookingStorage;

defined('ABSPATH') || exit;

/** Supplies the same month data to the initial page and subsequent calendar requests. */
final class MonthlyAvailability
{
    private array $reserved;
    private \DateTimeImmutable $now;

    /** Takes one snapshot of site time and reservations for this read. */
    public function __construct()
    {
        $this->now = current_datetime();
        $this->reserved = array_fill_keys(array_merge(
            BookingStorage::get(Bookings::SLOTS_OPTION),
            TokenManager::getPendingSlots()
        ), true);
    }

    /**
     * Selects the first month with displayable times and indexes later candidate months.
     *
     * @param array<string, mixed> $attributes Prepared block schedule.
     * @param bool $preview Embed later months when previewing unsaved post content.
     * @param string $requestedMonth Month selected by a page link for this block instance.
     * @return array{months: string[], slots: array<string, int>, previewMonths: array, links: array} Calendar data and page navigation.
     */
    public function initial(array $attributes, bool $preview = false, string $requestedMonth = ''): array
    {
        $months = SlotGenerator::months($attributes, $this->now->format('Y-m-d'));
        $selected = array_search($requestedMonth, $months, true);
        $candidates = $selected === false ? $months : [$selected => $requestedMonth];
        $initial = ['months' => [], 'slots' => [], 'previewMonths' => [], 'links' => []];
        foreach ($candidates as $index => $month) {
            $slots = $this->forMonth($attributes, $month);
            if ($initial['months'] === []) {
                // An explicitly selected month keeps its links even after the last time is booked.
                if ($slots === [] && $selected === false) {
                    continue;
                }
                $initial['months'] = $selected === false ? array_slice($months, $index) : [$month];
                $initial['slots'] = $slots;
                $initial['links'] = ['previous' => $months[$index - 1] ?? '', 'next' => $months[$index + 1] ?? ''];
                if (!$preview) {
                    break;
                }
            } else {
                $initial['previewMonths'][$month] = $slots;
            }
        }
        return $initial;
    }

    /**
     * Returns visible future slots, including slots whose booking window opens later.
     *
     * @param array<string, mixed> $attributes Prepared block schedule.
     * @param string $month Requested calendar month in YYYY-MM format.
     * @return array<string, int> Sorted canonical slot values mapped to Unix start timestamps.
     */
    public function forMonth(array $attributes, string $month): array
    {
        $slots = SlotGenerator::timestamps($attributes, $month . '-01', $month . '-31');
        $cutoff = (int) ($attributes['bookingCutoff'] ?? 0);
        $weekdaysOnly = !empty($attributes['hideWeekends']) && empty($attributes['showSlotsAsList']);
        foreach ($slots as $value => $timestamp) {
            $start = $this->now->setTimestamp($timestamp);
            if (
                isset($this->reserved[$value])
                || ($weekdaysOnly && (int) $start->format('N') > 5)
                || BookingWindow::isClosed($start, $this->now, $cutoff)
            ) {
                unset($slots[$value]);
            }
        }
        return $slots;
    }
}
