<?php

namespace RRZE\Appointment\Notification;

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Configuration\PluginSettings;
use RRZE\Appointment\Mail\MailTemplate;
use RRZE\Appointment\Mail\MailTemplatePost;
use RRZE\Appointment\Mail\Mailer;
use RRZE\Appointment\Storage\BookingStorage;
use RRZE\Appointment\Storage\BookingScheduler;

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

    /** Registers both callbacks and repairs the fallback on sites with existing booking data. */
    public function register(): void
    {
        add_action(self::CRON_HOOK, [BookingScheduler::class, 'sendReminder'], 10, 1);
        add_action(self::DAILY_CHECK_HOOK, [$this, 'runDaily']);
        self::ensureDailyCheck();
    }

    /**
     * Schedules retention and the reminder fallback on this site's first booking or next active startup.
     *
     * @param bool $afterWrite Whether a successful business commit already established local data.
     */
    public static function ensureDailyCheck(bool $afterWrite = false): void
    {
        try {
            if (!wp_next_scheduled(self::DAILY_CHECK_HOOK) && ($afterWrite || BookingStorage::hasStoredData())) {
                wp_schedule_event(self::getNextDailyCheckTimestamp(), 'daily', self::DAILY_CHECK_HOOK);
            }
        } catch (\Throwable) {
            // The next startup with stored data repairs an unavailable schedule.
        }
    }

    /**
     * Stores confirmed metadata and schedules a slot-specific reminder.
     *
     * @param array<string, mixed> $meta Confirmed booking metadata.
     */
    public static function scheduleForSlot(#[\SensitiveParameter] string $slot, #[\SensitiveParameter] array $meta): void
    {
        try {
            $allMeta = BookingStorage::get(Bookings::META_OPTION);
            $allMeta[$slot] = $meta;
            BookingStorage::set(Bookings::META_OPTION, $allMeta);
            self::schedulePersistedSlot($slot);
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Schedules reminders after a committed confirmation without replacing metadata.
     *
     * @param string $slot Confirmed slot whose metadata and cancellation token already exist.
     * @throws AppointmentException When a reminder cannot be scheduled.
     */
    public static function schedulePersistedSlot(#[\SensitiveParameter] string $slot): void
    {
        try {
            if (BookingStorage::recordId('booking', $slot) !== null) {
                // Its reminders were committed with the booking and already have a stable ID.
                return;
            }
            foreach (array_unique(self::plannedTimes($slot)) as $timestamp) {
                if ($timestamp > time()) {
                    BookingScheduler::schedule($timestamp, self::CRON_HOOK, $slot);
                }
            }
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Resolves the configured local-clock due time for each enabled recipient role.
     *
     * @return array<string,int> UNIX timestamps keyed by booker or host.
     */
    public static function plannedTimes(#[\SensitiveParameter] string $slot): array
    {
        $times = [];
        foreach (['booker', 'host'] as $role) {
            $days = (int) PluginSettings::get('reminder_' . $role . '_days');
            if ($days > 0) {
                $time = self::getReminderTimestamp($slot, $days);
                if ($time !== null) {
                    $times[$role] = $time;
                }
            }
        }
        return $times;
    }

    /** Returns the site-local date used by both eligibility checks and delivery receipts. */
    public static function today(): string
    {
        return current_datetime()->format('Y-m-d');
    }

    /** Sends the reminders due for an older single-event or daily callback. */
    public function sendReminder(#[\SensitiveParameter] string $slot): void
    {
        if (BookingStorage::recordId('booking', $slot) !== null) {
            BookingScheduler::sendReminder($slot);
            return;
        }
        foreach (['host', 'booker'] as $role) {
            if ($this->sendToRecipient($slot, $role)) {
                BookingScheduler::acknowledgeLegacyReminder($slot, $role);
            }
        }
    }

    /**
     * Sends to one recipient when eligible according to today's date and current settings.
     *
     * @param string $slot Confirmed slot.
     * @param 'host'|'booker' $role Recipient of the reminder.
     * @return bool True when the mail transport accepts the message. False when no reminder is due
     *              or the recipient has no usable email address.
     * @throws AppointmentException When the role is unsupported or the reminder cannot be prepared or sent.
     */
    public function sendToRecipient(#[\SensitiveParameter] string $slot, string $role): bool
    {
        try {
            $templateType = match ($role) {
                'host' => self::ADMIN_TEMPLATE_TYPE,
                'booker' => self::BOOKER_TEMPLATE_TYPE,
                default => throw new AppointmentException('Unsupported reminder recipient.'),
            };
            if (!self::isReminderDue($slot, (int) PluginSettings::get('reminder_' . $role . '_days'))) {
                return false;
            }
            $meta = $this->getBookingMeta($slot);
            $recipient = sanitize_email((string) ($meta[$role === 'host' ? 'person_email' : 'booker_email'] ?? ''));
            if ($recipient === '') {
                return false;
            }
            $mail = $this->renderMail(
                $this->resolveTemplate((int) ($meta['tpl_id'] ?? 0), $templateType),
                $this->buildTemplateVariables($slot, $meta)
            );
            $this->sendRenderedMail($recipient, $mail, $templateType);
            return true;
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Returns stored metadata for a confirmed slot.
     *
     * @return array<string, mixed>
     */
    private function getBookingMeta(#[\SensitiveParameter] string $slot): array
    {
        $allMeta = BookingStorage::get(Bookings::META_OPTION);
        return is_array($allMeta[$slot] ?? null) ? $allMeta[$slot] : [];
    }

    /**
     * Builds placeholder values shared by host and booker reminders.
     *
     * @param array<string, mixed> $meta Confirmed booking metadata.
     * @return array<string, string>
     */
    private function buildTemplateVariables(#[\SensitiveParameter] string $slot, #[\SensitiveParameter] array $meta): array
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
    private function resolveTemplate(int $templateId, #[\SensitiveParameter] string $type): array
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
     * compiled Maizzle layout by Mailer::send().
     *
     * @param array{subject: string, body: string, body_html: string} $template
     * @param array<string, string>                                  $variables
     * @return array{subject: string, plain: string, html: string}
     */
    private function renderMail(#[\SensitiveParameter] array $template, #[\SensitiveParameter] array $variables): array
    {
        $htmlVariables = array_map(
            static fn (string $value): string => esc_html($value),
            $variables
        );

        return [
            'subject' => Mailer::render($template['subject'], $variables),
            'plain' => Mailer::render($template['body'], $variables),
            'html' => Mailer::render($template['body_html'], $htmlVariables),
        ];
    }

    /**
     * Sends the prepared reminder to the recipient selected by sendToRecipient().
     *
     * @param array{subject: string, plain: string, html: string} $mail Rendered mail parts.
     */
    private function sendRenderedMail(#[\SensitiveParameter] string $recipient, #[\SensitiveParameter] array $mail, #[\SensitiveParameter] string $templateType): void
    {
        Mailer::send(
            $recipient,
            $mail['subject'],
            $mail['plain'],
            $mail['html'],
            [],
            MailTemplate::statusForType($templateType)
        );
    }

    /** Runs site-local housekeeping and lets the cron runner continue other hooks on a storage error. */
    public function runDaily(): void
    {
        try {
            BookingStorage::pruneExpiredLocks();
            TokenManager::cleanupPendingState();
            BookingOpeningNotifier::cleanup();
            $this->checkAndSendReminders();
        } catch (AppointmentException) {
            // Storage diagnostics remain available; durable jobs keep their separate generic wake.
        } finally {
            BookingStorage::release();
        }
    }

    /** Cleans expired bookings and sends reminders due on the current site date. */
    public function checkAndSendReminders(): void
    {
        try {
            Bookings::cleanupExpired((int) PluginSettings::get('retention_days'));

            if (
                (int) PluginSettings::get('reminder_booker_days') < 1
                && (int) PluginSettings::get('reminder_host_days') < 1
            ) {
                return;
            }

            $allMeta = BookingStorage::get(Bookings::META_OPTION);

            foreach ($allMeta as $slot => $meta) {
                if (!is_string($slot) || !is_array($meta)) {
                    continue;
                }

                if (!BookingScheduler::dailyReminder($slot)) {
                    $this->sendReminder($slot);
                }
            }
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Checks the current recipient setting, including for previously scheduled events.
     */
    private static function isReminderDue(#[\SensitiveParameter] string $slot, int $days): bool
    {
        if ($days < 1) {
            return false;
        }

        [$datePart] = self::parseSlot($slot);
        return $datePart === current_datetime()->modify("+{$days} days")->format('Y-m-d');
    }

    /**
     * Returns the next site-local daily fallback check timestamp.
     */
    private static function getNextDailyCheckTimestamp(): int
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
    private static function getReminderTimestamp(#[\SensitiveParameter] string $slot, int $days): ?int
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
    private static function parseSlot(#[\SensitiveParameter] string $slot): array
    {
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

        return [$datePart, $startTime, $endTime];
    }

    /**
     * Converts infrastructure errors to the plugin's shared exception type.
     */
    private static function createException(#[\SensitiveParameter] \Throwable $exception): AppointmentException
    {
        return AppointmentException::fromThrowable($exception);
    }
}
