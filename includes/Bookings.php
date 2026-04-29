<?php

namespace RRZE\Appointment;

use RRZE\Appointment\MailTemplatePost;
use RRZE\Appointment\MailTemplate;
use RRZE\Appointment\TokenManager;
use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class Bookings
{
    const SLOTS_OPTION = 'rrze_appointment_booked_slots';
    const META_OPTION  = 'rrze_appointment_booked_slots_meta';

    private static function resolvePersonName(int $personId, array $meta = []): string
    {
        if ($personId <= 0) {
            return trim((string) ($meta['person_name'] ?? ''));
        }

        $parts = array_filter([
            (string) get_post_meta($personId, 'person_honorificPrefix', true),
            (string) get_post_meta($personId, 'person_givenName', true),
            (string) get_post_meta($personId, 'person_familyName', true),
        ]);
        $name = trim(implode(' ', $parts));
        if ($name !== '') {
            return $name;
        }

        // Fallback for instances where custom person meta fields are empty.
        $title = trim((string) get_the_title($personId));
        if ($title !== '') {
            return $title;
        }

        return trim((string) ($meta['person_name'] ?? '')) ?: "Person #$personId";
    }

    public static function getAll(array $filter = []): array
    {
        try {
            $slots   = (array) get_option(self::SLOTS_OPTION, []);
            $allMeta = (array) get_option(self::META_OPTION, []);

            $bookings = [];
            foreach ($slots as $slot) {
                $meta = $allMeta[$slot] ?? [];
                [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');

                $booking = [
                    'slot'         => $slot,
                    'date'         => $datePart,
                    'time'         => $timePart,
                    'title'        => $meta['title']     ?? '',
                    'location'     => $meta['location']  ?? '',
                    'person_id'    => (int) ($meta['person_id'] ?? 0),
                    'person_name'  => '',
                    'booker_name'  => $meta['booker_name']  ?? '',
                    'booker_email' => $meta['booker_email'] ?? '',
                    'tpl_id'       => (int) ($meta['tpl_id'] ?? 0),
                ];

                $booking['person_name'] = self::resolvePersonName($booking['person_id'], $meta);

                if (!empty($filter['date_from']) && $datePart < $filter['date_from']) continue;
                if (!empty($filter['date_to'])   && $datePart > $filter['date_to'])   continue;
                if (!empty($filter['person_id']) && $booking['person_id'] !== (int) $filter['person_id']) continue;

                $bookings[] = $booking;
            }

            usort($bookings, fn($a, $b) => strcmp($a['slot'], $b['slot']));
            return $bookings;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function cancel(string $slot): bool
    {
        try {
            $slots = (array) get_option(self::SLOTS_OPTION, []);
            if (!in_array($slot, $slots, true)) return false;

            $allMeta = (array) get_option(self::META_OPTION, []);
            $meta    = $allMeta[$slot] ?? [];

            $timestamp = wp_next_scheduled(Reminder::CRON_HOOK, [$slot]);
            if ($timestamp) wp_unschedule_event($timestamp, Reminder::CRON_HOOK, [$slot]);

            self::sendCancellationMail($slot, $meta);

            update_option(self::SLOTS_OPTION, array_values(array_diff($slots, [$slot])), false);
            unset($allMeta[$slot]);
            update_option(self::META_OPTION, $allMeta, false);

            // Notify waitlisted bookers who have a later appointment for the same person
            self::notifyWaitlist($slot, $meta, $allMeta);

            return true;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    /**
     * Sends a notification to all bookers who:
     * - have booker_waitlist = true
     * - have a future booking for the same person_id
     * - have a slot that is LATER than the just-cancelled slot
     */
    private static function notifyWaitlist(string $cancelledSlot, array $cancelledMeta, array $allMeta): void
    {
        try {
            $cancelledDate    = explode(' ', $cancelledSlot)[0] ?? '';
            $cancelledPersonId = (int) ($cancelledMeta['person_id'] ?? 0);
            $today            = date('Y-m-d');

            if (!$cancelledDate || $cancelledDate < $today) return;

            foreach ($allMeta as $bookedSlot => $bookedMeta) {
                if (empty($bookedMeta['booker_waitlist'])) continue;

                $bookedPersonId = (int) ($bookedMeta['person_id'] ?? 0);
                if ($bookedPersonId !== $cancelledPersonId) continue;

                $bookedDate = explode(' ', $bookedSlot)[0] ?? '';
                if (!$bookedDate || $bookedDate <= $cancelledDate) continue;
                if ($bookedDate < $today) continue;

                $bookerEmail = $bookedMeta['booker_email'] ?? '';
                if (!$bookerEmail) continue;

                self::sendWaitlistNotification($cancelledSlot, $cancelledMeta, $bookedSlot, $bookedMeta);
            }
        } catch (\Exception $e) {
            // Non-critical — don't break the cancellation flow
        }
    }

    private static function sendWaitlistNotification(string $cancelledSlot, array $cancelledMeta, string $bookedSlot, array $bookedMeta): void
    {
        self::sendWaitlistNotificationStatic($cancelledSlot, $cancelledMeta, $bookedSlot, $bookedMeta);
    }

    public static function sendWaitlistNotificationStatic(string $cancelledSlot, array $newSlotMeta, string $bookedSlot, array $bookedMeta): void
    {
        [$cancelledDate, $cancelledTime] = array_pad(explode(' ', $cancelledSlot, 2), 2, '');
        [$cancelledStart, $cancelledEnd] = array_pad(explode('-', $cancelledTime, 2), 2, '');
        [$bookedDate, $bookedTime]       = array_pad(explode(' ', $bookedSlot, 2), 2, '');
        [$bookedStart, $bookedEnd]       = array_pad(explode('-', $bookedTime, 2), 2, '');

        $bookerEmail = $bookedMeta['booker_email'] ?? '';
        $bookerName  = $bookedMeta['booker_name']  ?? '';
        $title       = $cancelledMeta['title']     ?? $bookedMeta['title'] ?? '';
        $location    = $cancelledMeta['location']  ?? '';

        $personId = (int) ($cancelledMeta['person_id'] ?? 0);
        $pName    = '';
        if ($personId > 0) {
            $pTitle  = (string) get_post_meta($personId, 'person_honorificPrefix', true);
            $pGiven  = (string) get_post_meta($personId, 'person_givenName', true);
            $pFamily = (string) get_post_meta($personId, 'person_familyName', true);
            $pName   = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
        }

        $subject = sprintf(
            __('Earlier appointment available: %s on %s', 'rrze-appointment'),
            $title,
            date_i18n(get_option('date_format'), strtotime($cancelledDate))
        );

        $plain = sprintf(
            __(
                "Hello %s,\n\nAn earlier appointment has become available:\n\nAppointment: %s\nDate: %s\nTime: %s\nLocation: %s\nHost: %s\n\nYour current appointment is on %s at %s.\n\nPlease book the earlier slot directly on the website.",
                'rrze-appointment'
            ),
            $bookerName ?: __('there', 'rrze-appointment'),
            $title,
            date_i18n(get_option('date_format'), strtotime($cancelledDate)),
            $cancelledStart . ' – ' . $cancelledEnd,
            $location ?: '–',
            $pName ?: '–',
            date_i18n(get_option('date_format'), strtotime($bookedDate)),
            $bookedStart . ' – ' . $bookedEnd
        );

        $html = '<p>' . sprintf(__('Hello %s,', 'rrze-appointment'), esc_html($bookerName ?: __('there', 'rrze-appointment'))) . '</p>'
            . '<p>' . __('An earlier appointment has become available:', 'rrze-appointment') . '</p>'
            . '<table>'
            . '<tr><th>' . __('Appointment', 'rrze-appointment') . '</th><td>' . esc_html($title) . '</td></tr>'
            . '<tr><th>' . __('Date', 'rrze-appointment') . '</th><td>' . esc_html(date_i18n(get_option('date_format'), strtotime($cancelledDate))) . '</td></tr>'
            . '<tr><th>' . __('Time', 'rrze-appointment') . '</th><td>' . esc_html($cancelledStart . ' – ' . $cancelledEnd) . '</td></tr>'
            . '<tr><th>' . __('Location', 'rrze-appointment') . '</th><td>' . esc_html($location ?: '–') . '</td></tr>'
            . '<tr><th>' . __('Host', 'rrze-appointment') . '</th><td>' . esc_html($pName ?: '–') . '</td></tr>'
            . '</table>'
            . '<p>' . sprintf(
                __('Your current appointment is on %s at %s.', 'rrze-appointment'),
                esc_html(date_i18n(get_option('date_format'), strtotime($bookedDate))),
                esc_html($bookedStart . ' – ' . $bookedEnd)
            ) . '</p>'
            . '<p>' . __('Please book the earlier slot directly on the website.', 'rrze-appointment') . '</p>';

        Settings::sendMail($bookerEmail, $subject, $plain, MailTemplate::wrap($html, $subject));
    }

    private static function sendCancellationMail(string $slot, array $meta): void
    {
        try {
            [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
            [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

            $title       = $meta['title']    ?? __('Appointment', 'rrze-appointment');
            $location    = $meta['location'] ?? '';
            $personId    = (int) ($meta['person_id'] ?? 0);
            $bookerEmail = $meta['booker_email'] ?? '';
            $bookerName  = $meta['booker_name']  ?? '';
            $tplId       = (int) ($meta['tpl_id'] ?? 0);

            $pName = '';
            if ($personId > 0) {
                $pTitle  = (string) get_post_meta($personId, 'person_honorificPrefix', true);
                $pGiven  = (string) get_post_meta($personId, 'person_givenName', true);
                $pFamily = (string) get_post_meta($personId, 'person_familyName', true);
                $pName   = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
            }

            $vars = [
                '[title]'             => $title,
                '[date]'              => date_i18n(get_option('date_format'), strtotime($datePart)),
                '[time]'              => $startTime . ' – ' . $endTime,
                '[location]'          => $location ?: '–',
                '[person_name]'       => $pName ?: '–',
                '[name]'              => $bookerName ?: '–',
                '[email]'             => $bookerEmail ?: '–',
                '[cancel_link]'       => '',
                '[imprint_link]'      => TokenManager::imprintUrl(),
            ];

            $tpl = $tplId > 0 ? (MailTemplatePost::getTemplateForType($tplId, 'cancellation') ?? []) : [];
            $def = MailTemplatePost::getDefault('cancellation');

            $bodyTpl     = !empty($tpl['body'])      ? $tpl['body']      : $def['body'];
            $bodyHtmlTpl = !empty($tpl['body_html']) ? $tpl['body_html'] : $def['body_html'];

            if (strpos($bodyTpl, '[imprint_link]') === false)    $bodyTpl     .= "\n" . __('Imprint', 'rrze-appointment') . ": [imprint_link]";
            if (strpos($bodyHtmlTpl, '[imprint_link]') === false) $bodyHtmlTpl .= '<p><a href="[imprint_link]">' . __('Imprint', 'rrze-appointment') . '</a></p>';

            $subject = Settings::renderTemplate(!empty($tpl['subject']) ? $tpl['subject'] : $def['subject'], $vars);
            $plain   = Settings::renderTemplate($bodyTpl, $vars);
            $html    = Settings::renderTemplate($bodyHtmlTpl, $vars);

            $toAdmin = sanitize_email((string) ($meta['person_email'] ?? ''));
            if ($toAdmin) {
                Settings::sendMail($toAdmin, $subject, $plain, $html);
            }

            if ($bookerEmail) {
                Settings::sendMail($bookerEmail, $subject, $plain, $html);
            }
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function getPersonsFromBookings(): array
    {
        try {
            $allMeta = (array) get_option(self::META_OPTION, []);
            $persons = [];
            foreach ($allMeta as $meta) {
                $pid = (int) ($meta['person_id'] ?? 0);
                if ($pid > 0 && !isset($persons[$pid])) {
                    $persons[$pid] = self::resolvePersonName($pid, $meta);
                }
            }
            asort($persons);
            return $persons;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
