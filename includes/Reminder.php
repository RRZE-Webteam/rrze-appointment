<?php

namespace RRZE\Appointment;

use RRZE\Appointment\MailTemplatePost;

defined('ABSPATH') || exit;

class Reminder
{
    const CRON_HOOK = 'rrze_appointment_send_reminder';
    const META_OPTION = 'rrze_appointment_booked_slots_meta'; // slot => [title, location, person_id, booker_email]

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'sendReminder'], 10, 1);

        if (!wp_next_scheduled('rrze_appointment_daily_check')) {
            wp_schedule_event(strtotime('today 07:00:00'), 'daily', 'rrze_appointment_daily_check');
        }
        add_action('rrze_appointment_daily_check', [$this, 'checkAndSendReminders']);
    }

    /**
     * Wird nach jeder Buchung aufgerufen, um den Cron-Job zu planen.
     */
    public static function scheduleForSlot(string $slot, array $meta): void
    {
        $days = (int) Settings::get('reminder_days');
        if ($days < 1) {
            return;
        }

        // Slot-Format: "YYYY-MM-DD HH:MM-HH:MM"
        $datePart = explode(' ', $slot)[0] ?? '';
        if (!$datePart) {
            return;
        }

        $tz          = wp_timezone();
        $reminderDate = new \DateTime($datePart . ' 08:00:00', $tz);
        $reminderDate->modify("-{$days} days");

        // Nicht in der Vergangenheit planen
        if ($reminderDate->getTimestamp() <= time()) {
            return;
        }

        // Meta speichern
        $allMeta = get_option(self::META_OPTION, []);
        $allMeta[$slot] = $meta;
        update_option(self::META_OPTION, $allMeta, false);

        wp_schedule_single_event($reminderDate->getTimestamp(), self::CRON_HOOK, [$slot]);
    }

    /**
     * Sendet die Erinnerungsmail für einen einzelnen Slot.
     */
    public function sendReminder(string $slot): void
    {
        $days = (int) Settings::get('reminder_days');
        if ($days < 1) {
            return;
        }

        $allMeta = get_option(self::META_OPTION, []);
        $meta    = $allMeta[$slot] ?? null;
        if (!$meta) {
            return;
        }

        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

        $title        = $meta['title'] ?? 'Termin';
        $location     = $meta['location'] ?? '';
        $personId     = (int) ($meta['person_id'] ?? 0);
        $bookerEmail  = $meta['booker_email'] ?? '';
        $bookerName   = $meta['booker_name'] ?? '';
        $tplId        = (int) ($meta['tpl_id'] ?? 0);
        $tplAdmin     = $tplId > 0 ? MailTemplatePost::getTemplateForType($tplId, 'reminder_admin')  : null;
        $tplBooker    = $tplId > 0 ? MailTemplatePost::getTemplateForType($tplId, 'reminder_booker') : null;

        $dateFormatted = date_i18n(get_option('date_format'), strtotime($datePart));

        $vars = [
            '[titel]'       => $title,
            '[datum]'       => $dateFormatted,
            '[uhrzeit]'     => $startTime . ' – ' . $endTime,
            '[ort]'         => $location ?: '–',
            '[person_name]' => '',
            '[name]'        => $bookerName ?: '–',
            '[email]'       => $bookerEmail ?: '–',
        ];

        if ($personId > 0) {
            $pTitle  = (string) get_post_meta($personId, 'person_honorificPrefix', true);
            $pGiven  = (string) get_post_meta($personId, 'person_givenName', true);
            $pFamily = (string) get_post_meta($personId, 'person_familyName', true);
            $vars['[person_name]'] = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
        }

        $tplAdmin  = $tplId  > 0 ? MailTemplatePost::getTemplateForType($tplId,  'reminder_admin')  : null;
        $tplBooker = $tplId > 0 ? MailTemplatePost::getTemplateForType($tplId, 'reminder_booker') : null;

        $subject        = Settings::renderTemplate($tplAdmin['subject']   ?? (string) Settings::get('reminder_subject'),          $vars);
        $body           = Settings::renderTemplate($tplAdmin['body']      ?? (string) Settings::get('reminder_body'),              $vars);
        $bodyHtml       = Settings::renderTemplate($tplAdmin['body_html'] ?? (string) Settings::get('reminder_body_html'),         $vars);
        $bodyBooker     = Settings::renderTemplate($tplBooker['subject']  ?? (string) Settings::get('reminder_body_booker'),       $vars);
        $bodyBookerHtml = Settings::renderTemplate($tplBooker['body_html']?? (string) Settings::get('reminder_body_booker_html'),  $vars);

        // An Admin / Person senden
        $personEmail = '';
        if ($personId > 0) {
            $personEmail = (string) get_post_meta($personId, 'person_email', true);
        }
        $toAdmin = $personEmail ?: get_option('admin_email');
        Settings::sendMail($toAdmin, $subject, $body, $bodyHtml);

        // An Buchenden senden
        if ($bookerEmail) {
            Settings::sendMail($bookerEmail, $subject, $bodyBooker, $bodyBookerHtml);
        }
    }

    /**
     * Täglicher Check als Fallback (falls WP-Cron-Einzeljobs verloren gehen).
     */
    public function checkAndSendReminders(): void
    {
        $days = (int) Settings::get('reminder_days');
        if ($days < 1) {
            return;
        }

        $allMeta    = get_option(self::META_OPTION, []);
        $targetDate = date('Y-m-d', strtotime("+{$days} days"));

        foreach ($allMeta as $slot => $meta) {
            $datePart = explode(' ', $slot)[0] ?? '';
            if ($datePart === $targetDate) {
                $this->sendReminder($slot);
            }
        }
    }
}
