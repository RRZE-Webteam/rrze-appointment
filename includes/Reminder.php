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

            $vars = [
                '[title]'        => $title,
                '[date]'         => date_i18n(get_option('date_format'), strtotime($datePart)),
                '[time]'         => $startTime . ' – ' . $endTime,
                '[location]'     => $location ?: '–',
                '[person_name]'  => trim((string) ($meta['person_name'] ?? '')),
                '[name]'         => $bookerName ?: '–',
                '[email]'        => $bookerEmail ?: '–',
                '[questions]'    => '',
                '[cancel_link]'  => TokenManager::getCancelUrlForSlot($slot),
                '[imprint_link]' => TokenManager::imprintUrl(),
                '[post_link]'    => esc_url_raw($meta['post_link'] ?? home_url('/')),
            ];

            if ($vars['[person_name]'] === '' && $personId > 0) {
                $pTitle  = (string) get_post_meta($personId, 'person_honorificPrefix', true);
                $pGiven  = (string) get_post_meta($personId, 'person_givenName', true);
                $pFamily = (string) get_post_meta($personId, 'person_familyName', true);
                $vars['[person_name]'] = trim(implode(' ', array_filter([$pTitle, $pGiven, $pFamily])));
            }

            $adminMail = $this->renderMail(
                $this->resolveTemplate($tplId, 'reminder_admin'),
                $vars
            );
            $bookerMail = $this->renderMail(
                $this->resolveTemplate($tplId, 'reminder_booker'),
                $vars
            );

            $toAdmin = sanitize_email((string) ($meta['person_email'] ?? ''));
            if ($toAdmin) {
                Settings::sendMail(
                    $toAdmin,
                    $adminMail['subject'],
                    $adminMail['plain'],
                    $adminMail['html'],
                    [],
                    MailTemplate::statusForType('reminder_admin')
                );
            }

            if ($bookerEmail) {
                Settings::sendMail(
                    $bookerEmail,
                    $bookerMail['subject'],
                    $bookerMail['plain'],
                    $bookerMail['html'],
                    [],
                    MailTemplate::statusForType('reminder_booker')
                );
            }
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    /**
     * Resolves a custom reminder template with per-field default fallbacks.
     *
     * @return array{subject: string, body: string, body_html: string}
     */
    private function resolveTemplate(int $templateId, string $type): array
    {
        $custom = $templateId > 0
            ? (MailTemplatePost::getTemplateForType($templateId, $type) ?? [])
            : [];
        $default = MailTemplatePost::getDefault($type);

        return [
            'subject' => !empty($custom['subject']) ? $custom['subject'] : $default['subject'],
            'body' => !empty($custom['body']) ? $custom['body'] : $default['body'],
            'body_html' => !empty($custom['body_html']) ? $custom['body_html'] : $default['body_html'],
        ];
    }

    /**
     * Renders all parts of a reminder before the HTML is placed in the
     * compiled Maizzle layout by Settings::sendMail().
     *
     * @param array{subject: string, body: string, body_html: string} $template
     * @param array<string, string>                                  $variables
     * @return array{subject: string, plain: string, html: string}
     */
    private function renderMail(array $template, array $variables): array
    {
        return [
            'subject' => Settings::renderTemplate($template['subject'], $variables),
            'plain' => Settings::renderTemplate($template['body'], $variables),
            'html' => Settings::renderTemplate($template['body_html'], $variables),
        ];
    }

    public function checkAndSendReminders(): void
    {
        try {
            Bookings::cleanupExpired((int) Settings::get('retention_days'));

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
