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
     * @return array{months: string[], slots: array<string, int>, previewMonths: array} Calendar bootstrap data.
     */
    public function initial(array $attributes, bool $preview = false): array
    {
        $months = SlotGenerator::months($attributes, $this->now->format('Y-m-d'));
        $initial = ['months' => [], 'slots' => [], 'previewMonths' => []];
        foreach ($months as $index => $month) {
            $slots = $this->forMonth($attributes, $month);
            if ($initial['months'] === []) {
                if ($slots === []) {
                    continue;
                }
                $initial['months'] = array_slice($months, $index);
                $initial['slots'] = $slots;
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
