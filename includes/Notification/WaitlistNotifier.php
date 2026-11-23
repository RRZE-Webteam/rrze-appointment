<?php

namespace RRZE\Appointment\Notification;

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\SlotGenerator;
use RRZE\Appointment\Storage\BookingStorage;

defined('ABSPATH') || exit;

/**
 * Notifies waitlisted bookers when a post update introduces earlier slots.
 */
final class WaitlistNotifier
{
    private const APPOINTMENT_BLOCK = 'rrze/appointment';
    private const PUBLISHED_STATUS = 'publish';
    private const SLOT_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}-\d{2}:\d{2}$/';

    /**
     * Compares the previous and current appointment blocks after a post update.
     *
     * Notification failures are non-critical and must not interrupt post saves.
     */
    public function handlePostUpdated(int $postId, \WP_Post $postAfter, \WP_Post $postBefore): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if (
            $postAfter->post_status !== self::PUBLISHED_STATUS
            || !has_blocks($postAfter->post_content)
        ) {
            return;
        }

        try {
            $today = (string) current_time('Y-m-d');
            $waitlisted = $this->getWaitlistedBookings(
                $this->getOptionArray(Bookings::META_OPTION),
                $today
            );
            if ($waitlisted === []) {
                return;
            }

            $before = $postBefore->post_status === self::PUBLISHED_STATUS
                ? $this->schedulesByPerson(parse_blocks($postBefore->post_content)) : [];
            $after = $this->schedulesByPerson(parse_blocks($postAfter->post_content));
            $booked = $this->getBookedSlotSet();

            foreach ($waitlisted as $personId => $entries) {
                $schedules = $after[$personId] ?? [];
                $latest = $this->getSlotDate(max(array_column($entries, 'slot')));
                $months = [];
                foreach ($schedules as $attributes) {
                    foreach (SlotGenerator::months($attributes, $today) as $month) {
                        $months[$month] = true;
                    }
                }
                ksort($months);
                foreach (array_keys($months) as $month) {
                    $from = max($today, $month . '-01');
                    if ($from > $latest) {
                        break;
                    }
                    $until = min($latest, $month . '-31');
                    $added = array_diff_key(
                        $this->slotsInRange($schedules, $from, $until),
                        $this->slotsInRange($before[$personId] ?? [], $from, $until),
                        $booked
                    );
                    if ($added !== []) {
                        // Every eligible recipient gets the earliest addition for this host.
                        $earliest = min(array_keys($added));
                        $this->notifyWaitlistedBookings($entries, $earliest, $added[$earliest]);
                        break;
                    }
                }
            }
        } catch (\Throwable $exception) {
            // Waitlist notifications are ancillary to the completed post save.
        }
    }

    /**
     * Groups future waitlisted bookings by appointment host.
     *
     * @param array<string, array<string, mixed>> $allMeta Booking metadata keyed by slot.
     * @return array<int, array<int, array{slot: string, meta: array<string, mixed>}>>
     */
    private function getWaitlistedBookings(#[\SensitiveParameter] array $allMeta, #[\SensitiveParameter] string $today): array
    {
        $waitlisted = [];
        foreach ($allMeta as $slot => $meta) {
            if (
                !is_string($slot)
                || !is_array($meta)
                || !$this->isValidSlot($slot)
                || empty($meta['booker_waitlist'])
            ) {
                continue;
            }

            if ($this->getSlotDate($slot) < $today) {
                continue;
            }

            $personId = (int) ($meta['person_id'] ?? 0);
            if ($personId <= 0) {
                continue;
            }

            $waitlisted[$personId][] = ['slot' => $slot, 'meta' => $meta];
        }

        return $waitlisted;
    }

    /**
     * Groups schedule definitions by host before expanding any appointments.
     *
     * @param array $blocks Parsed WordPress blocks.
     * @return array<int, array<int, array<string, mixed>>> Schedules by host ID.
     */
    private function schedulesByPerson(#[\SensitiveParameter] array $blocks): array
    {
        $schedules = [];
        foreach ($blocks as $block) {
            $attributes = $block['attrs'] ?? [];
            $person = (int) ($attributes['personId'] ?? 0);
            if (($block['blockName'] ?? '') === self::APPOINTMENT_BLOCK && $person > 0) {
                $schedules[$person][] = $attributes;
            }
            foreach ($this->schedulesByPerson($block['innerBlocks'] ?? []) as $host => $nested) {
                $schedules[$host] = array_merge($schedules[$host] ?? [], $nested);
            }
        }
        return $schedules;
    }

    /**
     * Collects the host's slots across all blocks for one comparison period.
     *
     * @param array $schedules Schedule definitions for one host.
     * @param string $from Inclusive ISO start date.
     * @param string $until Inclusive ISO end date.
     * @return array<string, array<string, mixed>> Slots and their originating attributes.
     */
    private function slotsInRange(#[\SensitiveParameter] array $schedules, string $from, string $until): array
    {
        $slots = [];
        foreach ($schedules as $attributes) {
            try {
                foreach (SlotGenerator::fromAttributes($attributes, $from, $until) as $slot) {
                    $slots[$slot] = $attributes;
                }
            } catch (\Throwable $exception) {
                // Other blocks can still contain valid earlier appointments.
            }
        }
        return $slots;
    }

    /**
     * Notifies recipients whose existing appointment is later than the earliest addition.
     *
     * @param array<int, array{slot: string, meta: array<string, mixed>}> $entries Host's waitlisted bookings.
     * @param string $earliest Earliest newly available time.
     * @param array<string, mixed> $attributes Originating block attributes.
     */
    private function notifyWaitlistedBookings(
        #[\SensitiveParameter] array $entries,
        string $earliest,
        #[\SensitiveParameter] array $attributes
    ): void {
        foreach ($entries as $entry) {
            if ($earliest >= $entry['slot']) {
                continue;
            }
            try {
                Bookings::sendWaitlistNotificationStatic($earliest, $attributes, $entry['slot'], $entry['meta']);
            } catch (\Throwable $exception) {
                // One failed email must not prevent other waitlist notifications.
            }
        }
    }

    /**
     * Returns confirmed slot identifiers as a lookup set.
     *
     * @return array<string, true>
     */
    private function getBookedSlotSet(): array
    {
        $bookedSlots = [];
        foreach ($this->getOptionArray(Bookings::SLOTS_OPTION) as $slot) {
            if (is_string($slot) && $this->isValidSlot($slot)) {
                $bookedSlots[$slot] = true;
            }
        }

        return $bookedSlots;
    }

    /**
     * Reads an array-valued option without coercing corrupt scalar data.
     *
     * @return array<mixed>
     */
    private function getOptionArray(#[\SensitiveParameter] string $optionName): array
    {
        return BookingStorage::get($optionName);
    }

    /**
     * Determines whether a slot uses the sortable persisted format.
     */
    private function isValidSlot(#[\SensitiveParameter] string $slot): bool
    {
        return preg_match(self::SLOT_PATTERN, $slot) === 1;
    }

    /**
     * Extracts the ISO date prefix from a validated slot.
     */
    private function getSlotDate(#[\SensitiveParameter] string $slot): string
    {
        return substr($slot, 0, 10);
    }
}
