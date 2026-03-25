<?php

namespace RRZE\Appointment;

use RRZE\Appointment\MailTemplatePost;

defined('ABSPATH') || exit;

class Bookings
{
    const SLOTS_OPTION = 'rrze_appointment_booked_slots';
    const META_OPTION  = 'rrze_appointment_booked_slots_meta';

    /**
     * Gibt alle Buchungen als sortiertes Array zurück, optional gefiltert.
     */
    public static function getAll(array $filter = []): array
    {
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
                'title'        => $meta['title']        ?? '',
                'location'     => $meta['location']     ?? '',
                'person_id'    => (int) ($meta['person_id']    ?? 0),
                'person_name'  => '',
                'booker_name'  => $meta['booker_name']  ?? '',
                'booker_email' => $meta['booker_email'] ?? '',
                'tpl_id'       => (int) ($meta['tpl_id'] ?? 0),
            ];

            if ($booking['person_id'] > 0) {
                $pTitle  = (string) get_post_meta($booking['person_id'], 'person_honorificPrefix', true);
                $pGiven  = (string) get_post_meta($booking['person_id'], 'person_givenName', true);
                $pFamily = (string) get_post_meta($booking['person_id'], 'person_familyName', true);
                $booking['person_name'] = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
            }

            // Filter
            if (!empty($filter['date_from']) && $datePart < $filter['date_from']) continue;
            if (!empty($filter['date_to'])   && $datePart > $filter['date_to'])   continue;
            if (!empty($filter['person_id']) && $booking['person_id'] !== (int) $filter['person_id']) continue;

            $bookings[] = $booking;
        }

        usort($bookings, fn($a, $b) => strcmp($a['slot'], $b['slot']));
        return $bookings;
    }

    /**
     * Storniert eine Buchung: entfernt Slot, löscht Meta, cancelt Cron, sendet Storno-Mail.
     */
    public static function cancel(string $slot): bool
    {
        $slots = (array) get_option(self::SLOTS_OPTION, []);
        if (!in_array($slot, $slots, true)) return false;

        $allMeta = (array) get_option(self::META_OPTION, []);
        $meta    = $allMeta[$slot] ?? [];

        // Cron-Job entfernen
        $timestamp = wp_next_scheduled(Reminder::CRON_HOOK, [$slot]);
        if ($timestamp) wp_unschedule_event($timestamp, Reminder::CRON_HOOK, [$slot]);

        // Storno-Mail senden
        self::sendCancellationMail($slot, $meta);

        // Slot + Meta entfernen
        update_option(self::SLOTS_OPTION, array_values(array_diff($slots, [$slot])), false);
        unset($allMeta[$slot]);
        update_option(self::META_OPTION, $allMeta, false);

        return true;
    }

    private static function sendCancellationMail(string $slot, array $meta): void
    {
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

        $title       = $meta['title']        ?? 'Termin';
        $location    = $meta['location']     ?? '';
        $personId    = (int) ($meta['person_id']    ?? 0);
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
            '[titel]'       => $title,
            '[datum]'       => date_i18n(get_option('date_format'), strtotime($datePart)),
            '[uhrzeit]'     => $startTime . ' – ' . $endTime,
            '[ort]'         => $location ?: '–',
            '[person_name]' => $pName ?: '–',
            '[name]'        => $bookerName ?: '–',
            '[email]'       => $bookerEmail ?: '–',
        ];

        $tpl = $tplId > 0 ? MailTemplatePost::getTemplateForType($tplId, 'cancellation') : null;

        $subject = Settings::renderTemplate($tpl['subject']   ?? __('Stornierung: [titel] am [datum]', 'rrze-appointment'), $vars);
        $plain   = Settings::renderTemplate($tpl['body']      ?? __("Ihr Termin wurde storniert:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]", 'rrze-appointment'), $vars);
        $html    = Settings::renderTemplate($tpl['body_html'] ?? __('<p>Ihr Termin wurde storniert:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr></table>', 'rrze-appointment'), $vars);

        // An Person / Admin
        $toAdmin = '';
        if ($personId > 0) $toAdmin = (string) get_post_meta($personId, 'person_email', true);
        if (!$toAdmin) $toAdmin = get_option('admin_email');
        Settings::sendMail($toAdmin, $subject, $plain, $html);

        if ($bookerEmail) {
            Settings::sendMail($bookerEmail, $subject, $plain, $html);
        }
    }

    /**
     * Gibt alle eindeutigen Personen aus den Buchungen zurück (für Filter-Dropdown).
     */
    public static function getPersonsFromBookings(): array
    {
        $allMeta = (array) get_option(self::META_OPTION, []);
        $persons = [];
        foreach ($allMeta as $meta) {
            $pid = (int) ($meta['person_id'] ?? 0);
            if ($pid > 0 && !isset($persons[$pid])) {
                $pTitle  = (string) get_post_meta($pid, 'person_honorificPrefix', true);
                $pGiven  = (string) get_post_meta($pid, 'person_givenName', true);
                $pFamily = (string) get_post_meta($pid, 'person_familyName', true);
                $persons[$pid] = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily]))) ?: "Person #$pid";
            }
        }
        asort($persons);
        return $persons;
    }
}
