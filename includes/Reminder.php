<?php

namespace RRZE\Appointment;

use RRZE\Appointment\MailTemplatePost;
use RRZE\Appointment\TokenManager;
use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class Reminder
{
    const CRON_HOOK   = 'rrze_appointment_send_reminder';
    const META_OPTION = 'rrze_appointment_booked_slots_meta';

    public function register(): void
    {
        try {
            add_action(self::CRON_HOOK, [$this, 'sendReminder'], 10, 1);

            if (!wp_next_scheduled('rrze_appointment_daily_check')) {
                wp_schedule_event(strtotime('today 07:00:00'), 'daily', 'rrze_appointment_daily_check');
            }
            add_action('rrze_appointment_daily_check', [$this, 'checkAndSendReminders']);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function scheduleForSlot(string $slot, array $meta): void
    {
        try {
            $allMeta        = get_option(self::META_OPTION, []);
            $allMeta[$slot] = $meta;
            update_option(self::META_OPTION, $allMeta, false);

            $days = (int) Settings::get('reminder_days');
            if ($days < 1) return;

            $datePart = explode(' ', $slot)[0] ?? '';
            if (!$datePart) return;

            $tz           = wp_timezone();
            $reminderDate = new \DateTime($datePart . ' 08:00:00', $tz);
            $reminderDate->modify("-{$days} days");

            if ($reminderDate->getTimestamp() <= time()) return;

            wp_schedule_single_event($reminderDate->getTimestamp(), self::CRON_HOOK, [$slot]);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public function sendReminder(string $slot): void
    {
        try {
            $days = (int) Settings::get('reminder_days');
            if ($days < 1) return;

            $allMeta = get_option(self::META_OPTION, []);
            $meta    = $allMeta[$slot] ?? null;
            if (!$meta) return;

            [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
            [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

            $title       = $meta['title']    ?? __('Appointment', 'rrze-appointment');
            $location    = $meta['location'] ?? '';
            $personId    = (int) ($meta['person_id'] ?? 0);
            $bookerEmail = $meta['booker_email'] ?? '';
            $bookerName  = $meta['booker_name']  ?? '';
            $tplId       = (int) ($meta['tpl_id'] ?? 0);

            $tplAdmin  = $tplId > 0 ? MailTemplatePost::getTemplateForType($tplId, 'reminder_admin')  : [];
            $tplBooker = $tplId > 0 ? MailTemplatePost::getTemplateForType($tplId, 'reminder_booker') : [];
            $defAdmin  = MailTemplatePost::getDefault('reminder_admin');
            $defBooker = MailTemplatePost::getDefault('reminder_booker');

            $vars = [
                '[title]'        => $title,
                '[date]'         => date_i18n(get_option('date_format'), strtotime($datePart)),
                '[time]'         => $startTime . ' – ' . $endTime,
                '[location]'     => $location ?: '–',
                '[person_name]'  => '',
                '[name]'         => $bookerName ?: '–',
                '[email]'        => $bookerEmail ?: '–',
                '[cancel_link]'  => TokenManager::getCancelUrlForSlot($slot),
                '[imprint_link]' => TokenManager::imprintUrl(),
            ];

            if ($personId > 0) {
                $pTitle  = (string) get_post_meta($personId, 'person_honorificPrefix', true);
                $pGiven  = (string) get_post_meta($personId, 'person_givenName', true);
                $pFamily = (string) get_post_meta($personId, 'person_familyName', true);
                $vars['[person_name]'] = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
            }

            $subject        = Settings::renderTemplate(!empty($tplAdmin['subject'])    ? $tplAdmin['subject']    : $defAdmin['subject'],    $vars);
            $body           = Settings::renderTemplate(!empty($tplAdmin['body'])       ? $tplAdmin['body']       : $defAdmin['body'],       $vars);
            $bodyHtml       = Settings::renderTemplate(!empty($tplAdmin['body_html'])  ? $tplAdmin['body_html']  : $defAdmin['body_html'],  $vars);
            $bodyBooker     = Settings::renderTemplate(!empty($tplBooker['body'])      ? $tplBooker['body']      : $defBooker['body'],      $vars);
            $bodyBookerHtml = Settings::renderTemplate(!empty($tplBooker['body_html']) ? $tplBooker['body_html'] : $defBooker['body_html'], $vars);

            $personEmail = $personId > 0 ? (string) get_post_meta($personId, 'person_email', true) : '';
            $toAdmin     = $personEmail ?: get_option('admin_email');
            Settings::sendMail($toAdmin, $subject, $body, $bodyHtml);

            if ($bookerEmail) {
                Settings::sendMail($bookerEmail, $subject, $bodyBooker, $bodyBookerHtml);
            }
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public function checkAndSendReminders(): void
    {
        try {
            $days = (int) Settings::get('reminder_days');
            if ($days < 1) return;

            $allMeta    = get_option(self::META_OPTION, []);
            $targetDate = date('Y-m-d', strtotime("+{$days} days"));

            foreach ($allMeta as $slot => $meta) {
                $datePart = explode(' ', $slot)[0] ?? '';
                if ($datePart === $targetDate) {
                    $this->sendReminder($slot);
                }
            }
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
