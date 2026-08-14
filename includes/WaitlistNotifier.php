<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Notifies waitlisted bookers when a post update introduces earlier slots.
 */
final class WaitlistNotifier
{
    /**
     * Compares the previous and current appointment blocks after a post update.
     */
    public function handlePostUpdated(int $postId, \WP_Post $postAfter, \WP_Post $postBefore): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if ($postAfter->post_status !== 'publish' || !has_blocks($postAfter->post_content)) {
            return;
        }

        $today = current_time('Y-m-d');
        $allMeta = (array) get_option(Bookings::META_OPTION, []);
        $bookedSet = array_flip((array) get_option(Bookings::SLOTS_OPTION, []));
        $waitlisted = $this->getWaitlistedBookings($allMeta, $today);
        if ($waitlisted === []) {
            return;
        }

        $previousSlotsByPerson = [];
        if ($postBefore->post_status === 'publish' && has_blocks($postBefore->post_content)) {
            $previousSlotsByPerson = $this->collectAppointmentSlots($postBefore->post_content);
        }
        $currentSlotsByPerson = $this->collectAppointmentSlots($postAfter->post_content);

        foreach ($waitlisted as $personId => $entries) {
            if (empty($currentSlotsByPerson[$personId])) {
                continue;
            }

            $addedSlots = array_diff_key(
                $currentSlotsByPerson[$personId],
                $previousSlotsByPerson[$personId] ?? []
            );
            $addedSlots = array_filter(
                $addedSlots,
                static fn($attributes, $slot): bool => !isset($bookedSet[$slot])
                    && explode(' ', $slot)[0] >= $today,
                ARRAY_FILTER_USE_BOTH
            );

            foreach ($entries as $entry) {
                $earlierSlots = array_filter(
                    array_keys($addedSlots),
                    static fn($slot): bool => $slot < $entry['slot']
                );
                if ($earlierSlots === []) {
                    continue;
                }

                $earliest = (string) min($earlierSlots);
                Bookings::sendWaitlistNotificationStatic(
                    $earliest,
                    $addedSlots[$earliest],
                    $entry['slot'],
                    $entry['meta']
                );
            }
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
            if (empty($meta['booker_waitlist'])) {
                continue;
            }
            $date = explode(' ', $slot)[0] ?? '';
            if ($date < $today) {
                continue;
            }

            $personId = (int) ($meta['person_id'] ?? 0);
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
        $this->collectAppointmentSlotsFromBlocks(parse_blocks($postContent), $slotsByPerson);
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
            if (($block['blockName'] ?? '') === 'rrze/appointment') {
                $attributes = $block['attrs'] ?? [];
                $personId = (int) ($attributes['personId'] ?? 0);
                foreach (SlotGenerator::fromAttributes($attributes) as $slot) {
                    $slotsByPerson[$personId][$slot] = $attributes;
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collectAppointmentSlotsFromBlocks($block['innerBlocks'], $slotsByPerson);
            }
        }
    }
}
