<?php

namespace RRZE\Appointment\Notification;

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Mail\MailTemplate;
use RRZE\Appointment\Mail\MailTemplatePost;
use RRZE\Appointment\Mail\Mailer;

defined('ABSPATH') || exit;

/**
 * Stores advance-booking notifications and turns their links into pending bookings.
 */
final class BookingOpeningNotifier
{
    /** Option containing subscriptions keyed by their private claim token. */
    public const OPTION = 'rrze_appointment_booking_opening_notifications';

    /** Cron hook used to deliver one opening notification. */
    public const CRON_HOOK = 'rrze_appointment_booking_opened';

    private const RETRY_SECONDS = 15 * MINUTE_IN_SECONDS;
    private const CLAIM_LOCK_TTL = 5 * MINUTE_IN_SECONDS;
    private const CLAIM_LOCK_PREFIX = 'rrze_appointment_opening_claim_';
    private const CLAIM_QUERY_KEY = 'rrze_appt_opening';
    private const STATUS_QUERY_KEY = 'rrze_appt_opening_registered';
    private const TEMPLATE_TYPE = 'booking_opening_notification';

    /**
     * Registers one email address for a slot and schedules its opening email.
     *
     * @param array<string, mixed> $meta Booking metadata captured from the published block.
     * @return array{token: string, statusToken: string, created: bool}
     */
    public static function subscribe(
        string $slot,
        array $meta,
        int $opensAt,
        int $closesAt
    ): array {
        try {
            self::cleanup();
            $entries = self::getEntries();
            $email = sanitize_email((string) ($meta['booker_email'] ?? ''));

            $existingToken = self::findSubscriptionToken($entries, $slot, $email);
            if ($existingToken !== null) {
                return self::reuseSubscription($entries, $existingToken, $opensAt);
            }

            return self::createSubscription($entries, $slot, $meta, $opensAt, $closesAt);
        } catch (\Exception $exception) {
            throw new AppointmentException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Finds an existing subscription for the same slot and email address.
     *
     * @param array<string, array<string, mixed>> $entries Stored subscriptions.
     */
    private static function findSubscriptionToken(
        array $entries,
        string $slot,
        string $email
    ): ?string {
        foreach ($entries as $token => $entry) {
            if (
                is_array($entry)
                && hash_equals((string) ($entry['slot'] ?? ''), $slot)
                && hash_equals((string) ($entry['meta']['booker_email'] ?? ''), $email)
            ) {
                return (string) $token;
            }
        }

        return null;
    }

    /**
     * Reuses an existing subscription and restores legacy status tokens if needed.
     *
     * @param array<string, array<string, mixed>> $entries Stored subscriptions.
     * @return array{token: string, statusToken: string, created: bool}
     */
    private static function reuseSubscription(array $entries, string $token, int $opensAt): array
    {
        $entry = $entries[$token];
        $statusToken = (string) ($entry['status_token'] ?? '');
        if ($statusToken === '') {
            $statusToken = wp_generate_uuid4();
            $entries[$token]['status_token'] = $statusToken;
            update_option(self::OPTION, $entries, false);
        }

        self::schedule($token, (int) ($entry['opens_at'] ?? $opensAt));

        return [
            'token' => $token,
            'statusToken' => $statusToken,
            'created' => false,
        ];
    }

    /**
     * Persists and schedules a new opening subscription.
     *
     * @param array<string, array<string, mixed>> $entries Stored subscriptions.
     * @param array<string, mixed>                $meta    Validated booking metadata.
     * @return array{token: string, statusToken: string, created: bool}
     */
    private static function createSubscription(
        array $entries,
        string $slot,
        array $meta,
        int $opensAt,
        int $closesAt
    ): array {
        $token = wp_generate_uuid4();
        $statusToken = wp_generate_uuid4();
        $entries[$token] = [
            'slot' => $slot,
            'meta' => $meta,
            'status_token' => $statusToken,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'created_at' => time(),
            'notified_at' => 0,
        ];
        update_option(self::OPTION, $entries, false);
        self::schedule($token, $opensAt);

        return [
            'token' => $token,
            'statusToken' => $statusToken,
            'created' => true,
        ];
    }

    /**
     * Sends the opening email for a subscription when its booking window opens.
     */
    public static function notify(string $token): void
    {
        try {
            $entries = self::getEntries();
            $entry = $entries[$token] ?? null;
            if (!is_array($entry) || !empty($entry['notified_at'])) {
                return;
            }

            $now = time();
            $opensAt = (int) ($entry['opens_at'] ?? 0);
            $closesAt = (int) ($entry['closes_at'] ?? 0);
            if ($closesAt <= $now) {
                self::delete($token);
                return;
            }
            if ($opensAt > $now) {
                self::schedule($token, $opensAt);
                return;
            }

            $slot = (string) ($entry['slot'] ?? '');
            if (self::isBooked($slot)) {
                self::delete($token);
                return;
            }
            if (self::isPending($slot)) {
                self::scheduleRetry($token, $now, $closesAt);
                return;
            }

            if (!self::sendOpeningMail($token, $entry)) {
                self::scheduleRetry($token, $now, $closesAt);
                return;
            }

            self::markNotified($token, $now);
        } catch (\Exception $exception) {
            throw new AppointmentException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Determines whether a slot is already booked.
     */
    private static function isBooked(string $slot): bool
    {
        return in_array($slot, (array) get_option(Bookings::SLOTS_OPTION, []), true);
    }

    /**
     * Determines whether a slot is waiting for booking confirmation.
     */
    private static function isPending(string $slot): bool
    {
        return in_array($slot, TokenManager::getPendingSlots(), true);
    }

    /**
     * Schedules another delivery attempt without crossing the booking cutoff.
     */
    private static function scheduleRetry(string $token, int $now, int $closesAt): void
    {
        self::schedule($token, min($closesAt, $now + self::RETRY_SECONDS));
    }

    /**
     * Marks a subscription as successfully notified if it still exists.
     */
    private static function markNotified(string $token, int $notifiedAt): void
    {
        $entries = self::getEntries();
        if (!isset($entries[$token]) || !is_array($entries[$token])) {
            return;
        }

        $entries[$token]['notified_at'] = $notifiedAt;
        update_option(self::OPTION, $entries, false);
    }

    /**
     * Claims a notification link and creates the regular pending booking.
     *
     * @return string|\WP_Error Confirmation URL or a public-safe error.
     */
    public static function claim(string $token)
    {
        try {
            self::cleanup();
            $entries = self::getEntries();
            $entry = $entries[$token] ?? null;
            if (!is_array($entry)) {
                return new \WP_Error(
                    'rrze_appointment_opening_invalid',
                    __('This booking notification link is invalid or has expired.', 'rrze-appointment')
                );
            }

            $now = time();
            if ($now < (int) ($entry['opens_at'] ?? 0)) {
                return new \WP_Error(
                    'rrze_appointment_opening_too_early',
                    __('This appointment is not open for booking yet.', 'rrze-appointment')
                );
            }
            if ($now >= (int) ($entry['closes_at'] ?? 0)) {
                self::delete($token);
                return new \WP_Error(
                    'rrze_appointment_opening_closed',
                    __('This appointment can no longer be booked.', 'rrze-appointment')
                );
            }

            $slot = (string) ($entry['slot'] ?? '');
            $lockOption = self::getClaimLockOption($slot);
            if (!self::acquireClaimLock($lockOption, $now)) {
                return new \WP_Error(
                    'rrze_appointment_opening_busy',
                    __('This appointment is currently being requested. Please try again shortly.', 'rrze-appointment')
                );
            }

            try {
                if (self::isBooked($slot) || self::isPending($slot)) {
                    return new \WP_Error(
                        'rrze_appointment_opening_unavailable',
                        __('This appointment is no longer available.', 'rrze-appointment')
                    );
                }

                $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
                $pendingToken = TokenManager::createPending($slot, $meta);
                self::delete($token);
                return TokenManager::confirmUrl($pendingToken);
            } finally {
                delete_option($lockOption);
            }
        } catch (\Exception $exception) {
            throw new AppointmentException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Returns the option name used to serialize claims for one slot.
     */
    private static function getClaimLockOption(string $slot): string
    {
        return self::CLAIM_LOCK_PREFIX . md5($slot);
    }

    /**
     * Acquires a claim lock, replacing it only when it has expired.
     */
    private static function acquireClaimLock(string $lockOption, int $now): bool
    {
        if (add_option($lockOption, $now, '', false)) {
            return true;
        }
        if ((int) get_option($lockOption, 0) >= $now - self::CLAIM_LOCK_TTL) {
            return false;
        }

        delete_option($lockOption);

        return add_option($lockOption, $now, '', false);
    }

    /**
     * Reads a subscription for its public registration status page.
     *
     * @return array<string, mixed>|null
     */
    public static function getSubscriptionByStatusToken(string $statusToken): ?array
    {
        self::cleanup();
        foreach (self::getEntries() as $entry) {
            if (
                is_array($entry)
                && ($entry['status_token'] ?? '') !== ''
                && hash_equals((string) $entry['status_token'], $statusToken)
            ) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Builds the public success-page URL for a subscription.
     */
    public static function registrationUrl(string $statusToken): string
    {
        return add_query_arg(self::STATUS_QUERY_KEY, $statusToken, home_url('/'));
    }

    /**
     * Removes subscriptions after their latest booking time.
     */
    public static function cleanup(): void
    {
        $entries = self::getEntries();
        $changed = false;
        $now = time();

        foreach ($entries as $token => $entry) {
            if (!is_array($entry) || $now >= (int) ($entry['closes_at'] ?? 0)) {
                unset($entries[$token]);
                wp_clear_scheduled_hook(self::CRON_HOOK, [(string) $token]);
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::OPTION, $entries, false);
        }
    }

    /**
     * Deletes one subscription and its scheduled delivery.
     */
    private static function delete(string $token): void
    {
        $entries = self::getEntries();
        if (isset($entries[$token])) {
            unset($entries[$token]);
            update_option(self::OPTION, $entries, false);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK, [$token]);
    }

    /**
     * Schedules a subscription once, normalizing past timestamps.
     */
    private static function schedule(string $token, int $timestamp): void
    {
        $now = time();
        if ($timestamp <= $now) {
            $timestamp = $now + 1;
        }
        if (!wp_next_scheduled(self::CRON_HOOK, [$token])) {
            wp_schedule_single_event($timestamp, self::CRON_HOOK, [$token]);
        }
    }

    /**
     * Returns all stored subscriptions keyed by claim token.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function getEntries(): array
    {
        return (array) get_option(self::OPTION, []);
    }

    /**
     * @param array<string, mixed> $entry Stored subscription.
     */
    private static function sendOpeningMail(string $token, array $entry): bool
    {
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $variables = self::getOpeningMailVariables(
            (string) ($entry['slot'] ?? ''),
            $token,
            $meta
        );
        [$template, $default] = self::getOpeningMailTemplates((int) ($meta['tpl_id'] ?? 0));
        $subject = Mailer::render(
            !empty($template['subject']) ? $template['subject'] : $default['subject'],
            $variables
        );
        $plain = !empty($template['body']) ? $template['body'] : $default['body'];
        $html = !empty($template['body_html']) ? $template['body_html'] : $default['body_html'];
        [$plain, $html] = self::ensureRequiredMailLinks($plain, $html);

        return Mailer::send(
            sanitize_email((string) ($meta['booker_email'] ?? '')),
            $subject,
            Mailer::render($plain, $variables),
            Mailer::render($html, $variables),
            [],
            MailTemplate::STATUS_WARNING
        );
    }

    /**
     * Builds placeholder values for an opening-notification email.
     *
     * @param array<string, mixed> $meta Stored subscription metadata.
     * @return array<string, string>
     */
    private static function getOpeningMailVariables(string $slot, string $token, array $meta): array
    {
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');
        $bookingUrl = add_query_arg(self::CLAIM_QUERY_KEY, $token, home_url('/'));

        return [
            '[title]' => (string) ($meta['title'] ?? ''),
            '[date]' => date_i18n(get_option('date_format'), strtotime($datePart)),
            '[time]' => $startTime . ' – ' . $endTime,
            '[location]' => (string) (($meta['location'] ?? '') ?: '–'),
            '[person_name]' => (string) (($meta['person_name'] ?? '') ?: '–'),
            '[name]' => (string) (($meta['booker_name'] ?? '') ?: '–'),
            '[email]' => (string) (($meta['booker_email'] ?? '') ?: '–'),
            '[booking_link]' => esc_url_raw($bookingUrl),
            '[imprint_link]' => TokenManager::imprintUrl(),
            '[post_link]' => esc_url_raw((string) ($meta['post_link'] ?? home_url('/'))),
        ];
    }

    /**
     * Returns the custom and default opening-notification templates.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private static function getOpeningMailTemplates(int $templateId): array
    {
        $template = $templateId > 0
            ? (MailTemplatePost::getTemplateForType($templateId, self::TEMPLATE_TYPE) ?? [])
            : [];

        return [$template, MailTemplatePost::getDefault(self::TEMPLATE_TYPE)];
    }

    /**
     * Ensures custom templates retain their required booking and imprint links.
     *
     * @return array{0: string, 1: string}
     */
    private static function ensureRequiredMailLinks(string $plain, string $html): array
    {
        if (strpos($plain, '[booking_link]') === false) {
            $plain .= "\n\n" . __('Book appointment', 'rrze-appointment') . ': [booking_link]';
        }
        if (strpos($plain, '[imprint_link]') === false) {
            $plain .= "\n" . __('Imprint', 'rrze-appointment') . ': [imprint_link]';
        }
        if (strpos($html, '[booking_link]') === false) {
            $html .= MailTemplate::actionButton(
                '[booking_link]',
                __('Book appointment', 'rrze-appointment')
            );
        }
        if (strpos($html, '[imprint_link]') === false) {
            $html .= '<p><a href="[imprint_link]">'
                . __('Imprint', 'rrze-appointment')
                . '</a></p>';
        }

        return [$plain, $html];
    }
}
