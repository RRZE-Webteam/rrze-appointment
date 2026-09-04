<?php

namespace RRZE\Appointment\Notification;

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\SlotGenerator;

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

            $previousSlotsByPerson = [];
            if (
                $postBefore->post_status === self::PUBLISHED_STATUS
                && has_blocks($postBefore->post_content)
            ) {
                $previousSlotsByPerson = $this->collectAppointmentSlots($postBefore->post_content);
            }
            $currentSlotsByPerson = $this->collectAppointmentSlots($postAfter->post_content);
            $bookedSlots = $this->getBookedSlotSet();

            foreach ($waitlisted as $personId => $entries) {
                $currentSlots = $currentSlotsByPerson[$personId] ?? [];
                if ($currentSlots === []) {
                    continue;
                }

                $addedSlots = $this->getAddedAvailableSlots(
                    $currentSlots,
                    $previousSlotsByPerson[$personId] ?? [],
                    $bookedSlots,
                    $today
                );
                if ($addedSlots === []) {
                    continue;
                }

                $this->notifyWaitlistedBookings($entries, $addedSlots);
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
    private function getWaitlistedBookings(array $allMeta, string $today): array
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
     * Collects generated appointment slots from all blocks in post content.
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function collectAppointmentSlots(string $postContent): array
    {
        $slotsByPerson = [];
        $blocks = parse_blocks($postContent);
        if (is_array($blocks)) {
            $this->collectAppointmentSlotsFromBlocks($blocks, $slotsByPerson);
        }

        return $slotsByPerson;
    }

    /**
     * Recursively traverses appointment and nested blocks.
     *
     * @param array<int, array<string, mixed>>                $blocks        Parsed blocks.
     * @param array<int, array<string, array<string, mixed>>> $slotsByPerson Collected slots.
     */
    private function collectAppointmentSlotsFromBlocks(array $blocks, array &$slotsByPerson): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['blockName'] ?? '') === self::APPOINTMENT_BLOCK) {
                $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $personId = (int) ($attributes['personId'] ?? 0);
                if ($personId > 0) {
                    try {
                        $generatedSlots = SlotGenerator::fromAttributes($attributes);
                    } catch (\Throwable $exception) {
                        $generatedSlots = [];
                    }

                    foreach ($generatedSlots as $slot) {
                        if (is_string($slot) && $this->isValidSlot($slot)) {
                            $slotsByPerson[$personId][$slot] = $attributes;
                        }
                    }
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collectAppointmentSlotsFromBlocks($block['innerBlocks'], $slotsByPerson);
            }
        }
    }

    /**
     * Returns newly introduced, unbooked, non-past slots.
     *
     * @param array<string, array<string, mixed>> $currentSlots
     * @param array<string, array<string, mixed>> $previousSlots
     * @param array<string, true>                 $bookedSlots
     * @return array<string, array<string, mixed>>
     */
    private function getAddedAvailableSlots(
        array $currentSlots,
        array $previousSlots,
        array $bookedSlots,
        string $today
    ): array {
        $availableSlots = [];
        foreach (array_diff_key($currentSlots, $previousSlots) as $slot => $attributes) {
            if (!isset($bookedSlots[$slot]) && $this->getSlotDate($slot) >= $today) {
                $availableSlots[$slot] = $attributes;
            }
        }

        return $availableSlots;
    }

    /**
     * Sends each waitlisted booking the earliest newly available earlier slot.
     *
     * @param array<int, array{slot: string, meta: array<string, mixed>}> $entries
     * @param array<string, array<string, mixed>>                         $addedSlots
     */
    private function notifyWaitlistedBookings(array $entries, array $addedSlots): void
    {
        foreach ($entries as $entry) {
            $earliestSlot = $this->findEarliestSlotBefore(array_keys($addedSlots), $entry['slot']);
            if ($earliestSlot === null) {
                continue;
            }

            try {
                Bookings::sendWaitlistNotificationStatic(
                    $earliestSlot,
                    $addedSlots[$earliestSlot],
                    $entry['slot'],
                    $entry['meta']
                );
            } catch (\Throwable $exception) {
                // One failed email must not prevent other waitlist notifications.
            }
        }
    }

    /**
     * Finds the earliest candidate that sorts before an existing booking.
     *
     * @param array<int, string> $slots
     */
    private function findEarliestSlotBefore(array $slots, string $bookedSlot): ?string
    {
        $earliestSlot = null;
        foreach ($slots as $slot) {
            if ($slot < $bookedSlot && ($earliestSlot === null || $slot < $earliestSlot)) {
                $earliestSlot = $slot;
            }
        }

        return $earliestSlot;
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
    private function getOptionArray(string $optionName): array
    {
        $value = get_option($optionName, []);
        return is_array($value) ? $value : [];
    }

    /**
     * Determines whether a slot uses the sortable persisted format.
     */
    private function isValidSlot(string $slot): bool
    {
        return preg_match(self::SLOT_PATTERN, $slot) === 1;
    }

    /**
     * Extracts the ISO date prefix from a validated slot.
     */
    private function getSlotDate(string $slot): string
    {
        return substr($slot, 0, 10);
    }
}
