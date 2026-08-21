<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

/**
 * Schedules and sends appointment reminder emails.
 */
final class Reminder
{
    public const CRON_HOOK = 'rrze_appointment_send_reminder';

    private const DAILY_CHECK_HOOK = 'rrze_appointment_daily_check';
    private const DAILY_CHECK_HOUR = 7;
    private const REMINDER_HOUR = 8;
    private const ADMIN_TEMPLATE_TYPE = 'reminder_admin';
    private const BOOKER_TEMPLATE_TYPE = 'reminder_booker';

    /**
     * Registers reminder hooks and ensures the daily fallback check exists.
     */
    public function register(): void
    {
        try {
            add_action(self::CRON_HOOK, [$this, 'sendReminder'], 10, 1);

            if (!wp_next_scheduled(self::DAILY_CHECK_HOOK)) {
                wp_schedule_event(
                    $this->getNextDailyCheckTimestamp(),
                    'daily',
                    self::DAILY_CHECK_HOOK
                );
            }
            add_action(self::DAILY_CHECK_HOOK, [$this, 'checkAndSendReminders']);
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Stores confirmed metadata and schedules a slot-specific reminder.
     *
     * @param array<string, mixed> $meta Confirmed booking metadata.
     */
    public static function scheduleForSlot(string $slot, array $meta): void
    {
        try {
            $allMeta = (array) get_option(Bookings::META_OPTION, []);
            $allMeta[$slot] = $meta;
            update_option(Bookings::META_OPTION, $allMeta, false);

            $days = (int) Settings::get('reminder_days');
            if ($days < 1) {
                return;
            }

            $reminderTimestamp = self::getReminderTimestamp($slot, $days);
            if ($reminderTimestamp === null || $reminderTimestamp <= time()) {
                return;
            }

            wp_schedule_single_event($reminderTimestamp, self::CRON_HOOK, [$slot]);
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Sends host and booker reminders for a confirmed slot.
     */
    public function sendReminder(string $slot): void
    {
        try {
            $days = (int) Settings::get('reminder_days');
            if ($days < 1) {
                return;
            }

            $meta = $this->getBookingMeta($slot);
            if ($meta === []) {
                return;
            }

            $templateId = (int) ($meta['tpl_id'] ?? 0);
            $variables = $this->buildTemplateVariables($slot, $meta);

            $adminMail = $this->renderMail(
                $this->resolveTemplate($templateId, self::ADMIN_TEMPLATE_TYPE),
                $variables
            );
            $bookerMail = $this->renderMail(
                $this->resolveTemplate($templateId, self::BOOKER_TEMPLATE_TYPE),
                $variables
            );

            $this->sendRenderedMail(
                sanitize_email((string) ($meta['person_email'] ?? '')),
                $adminMail,
                self::ADMIN_TEMPLATE_TYPE
            );
            $this->sendRenderedMail(
                sanitize_email((string) ($meta['booker_email'] ?? '')),
                $bookerMail,
                self::BOOKER_TEMPLATE_TYPE
            );
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Returns stored metadata for a confirmed slot.
     *
     * @return array<string, mixed>
     */
    private function getBookingMeta(string $slot): array
    {
        $allMeta = (array) get_option(Bookings::META_OPTION, []);
        return is_array($allMeta[$slot] ?? null) ? $allMeta[$slot] : [];
    }

    /**
     * Builds placeholder values shared by host and booker reminders.
     *
     * @param array<string, mixed> $meta Confirmed booking metadata.
     * @return array<string, string>
     */
    private function buildTemplateVariables(string $slot, array $meta): array
    {
        [$datePart, $startTime, $endTime] = self::parseSlot($slot);
        $personName = sanitize_text_field((string) ($meta['person_name'] ?? ''));
        $personId = (int) ($meta['person_id'] ?? 0);
        if ($personName === '' && $personId > 0) {
            $personName = $this->getPersonName($personId);
        }

        $bookerEmail = sanitize_email((string) ($meta['booker_email'] ?? ''));

        return [
            '[title]' => sanitize_text_field(
                (string) ($meta['title'] ?? __('Appointment', 'rrze-appointment'))
            ),
            '[date]' => date_i18n((string) get_option('date_format'), strtotime($datePart)),
            '[time]' => $startTime . ' – ' . $endTime,
            '[location]' => sanitize_text_field((string) ($meta['location'] ?? '')) ?: '–',
            '[person_name]' => $personName,
            '[name]' => sanitize_text_field((string) ($meta['booker_name'] ?? '')) ?: '–',
            '[email]' => $bookerEmail ?: '–',
            '[questions]' => '',
            '[cancel_link]' => TokenManager::getCancelUrlForSlot($slot),
            '[imprint_link]' => TokenManager::imprintUrl(),
            '[post_link]' => esc_url_raw((string) ($meta['post_link'] ?? home_url('/'))),
        ];
    }

    /**
     * Builds a host display name from the related person post.
     */
    private function getPersonName(int $personId): string
    {
        $parts = [
            get_post_meta($personId, 'person_honorificPrefix', true),
            get_post_meta($personId, 'person_givenName', true),
            get_post_meta($personId, 'person_familyName', true),
        ];
        $parts = array_map(
            static fn ($part): string => sanitize_text_field((string) $part),
            $parts
        );

        return trim(implode(' ', array_filter($parts)));
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
        $htmlVariables = array_map(
            static fn (string $value): string => esc_html($value),
            $variables
        );

        return [
            'subject' => Settings::renderTemplate($template['subject'], $variables),
            'plain' => Settings::renderTemplate($template['body'], $variables),
            'html' => Settings::renderTemplate($template['body_html'], $htmlVariables),
        ];
    }

    /**
     * Sends a rendered reminder when the recipient is valid.
     *
     * @param array{subject: string, plain: string, html: string} $mail Rendered mail parts.
     */
    private function sendRenderedMail(string $recipient, array $mail, string $templateType): void
    {
        if ($recipient === '') {
            return;
        }

        Settings::sendMail(
            $recipient,
            $mail['subject'],
            $mail['plain'],
            $mail['html'],
            [],
            MailTemplate::statusForType($templateType)
        );
    }

    public function checkAndSendReminders(): void
    {
        try {
            Bookings::cleanupExpired((int) Settings::get('retention_days'));

            $days = (int) Settings::get('reminder_days');
            if ($days < 1) {
                return;
            }

            $allMeta = (array) get_option(Bookings::META_OPTION, []);
            $targetDate = current_datetime()->modify("+{$days} days")->format('Y-m-d');

            foreach ($allMeta as $slot => $meta) {
                if (!is_string($slot) || !is_array($meta)) {
                    continue;
                }

                [$datePart] = self::parseSlot($slot);
                if ($datePart === $targetDate) {
                    $this->sendReminder($slot);
                }
            }
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Returns the next site-local daily fallback check timestamp.
     */
    private function getNextDailyCheckTimestamp(): int
    {
        $now = current_datetime();
        $nextCheck = $now->setTime(self::DAILY_CHECK_HOUR, 0);
        if ($nextCheck <= $now) {
            $nextCheck = $nextCheck->modify('+1 day');
        }

        return $nextCheck->getTimestamp();
    }

    /**
     * Calculates a site-local reminder timestamp for a stored slot.
     */
    private static function getReminderTimestamp(string $slot, int $days): ?int
    {
        [$datePart] = self::parseSlot($slot);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $datePart, wp_timezone());
        if (!$date || $date->format('Y-m-d') !== $datePart) {
            return null;
        }

        return $date
            ->setTime(self::REMINDER_HOUR, 0)
            ->modify("-{$days} days")
            ->getTimestamp();
    }

    /**
     * Splits a stored slot into its date, start time, and end time.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function parseSlot(string $slot): array
    {
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

        return [$datePart, $startTime, $endTime];
    }

    /**
     * Converts infrastructure errors to the plugin's shared exception type.
     */
    private static function createException(\Exception $exception): CustomException
    {
        if ($exception instanceof CustomException) {
            return $exception;
        }

        return new CustomException($exception->getMessage(), (int) $exception->getCode(), null);
    }
}
