<?php

namespace RRZE\Appointment\Controller;

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Booking\AppointmentBlock;
use RRZE\Appointment\Booking\MonthlyAvailability;

defined('ABSPATH') || exit;

/** Serves one calendar month from a block the current visitor may read. */
final class AvailabilityController
{
    /** Resolves the saved schedule and returns visible times for the requested month. */
    public static function handleRequest(): void
    {
        check_ajax_referer('rrze_appointment_book', 'nonce');
        $month = $_POST['month'] ?? '';
        $blockId = $_POST['block_id'] ?? '';
        $postId = $_POST['post_id'] ?? '';
        if (
            !is_string($month) || !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month)
            || !is_string($blockId) || !preg_match('/^[a-f0-9]{64}$/D', $blockId)
            || !is_scalar($postId) || !ctype_digit((string) $postId) || (int) $postId <= 0
        ) {
            wp_send_json_error(null, 400);
        }

        $post = get_post((int) $postId);
        if (!$post instanceof \WP_Post || !AppointmentBlock::canViewCalendar($post)) {
            wp_send_json_error(null, 403);
        }
        $matches = AppointmentBlock::matchingAttributes($post, $blockId);
        if (!$matches) {
            wp_send_json_error(null, 404);
        }

        try {
            $slots = (new MonthlyAvailability())->forMonth($matches[0], $month);
            wp_send_json_success(['month' => $month, 'slots' => (object) $slots]);
        } catch (AppointmentException $exception) {
            wp_send_json_error(null, 503);
        }
    }
}
