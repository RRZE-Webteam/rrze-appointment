<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

/**
 * Stores advance-booking notifications and turns their links into pending bookings.
 */
final class BookingOpeningNotifier
{
    public const OPTION = 'rrze_appointment_booking_opening_notifications';
    public const CRON_HOOK = 'rrze_appointment_booking_opened';

    private const RETRY_SECONDS = 15 * MINUTE_IN_SECONDS;
    private const CLAIM_LOCK_TTL = 5 * MINUTE_IN_SECONDS;
    private const CLAIM_LOCK_PREFIX = 'rrze_appointment_opening_claim_';

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

            foreach ($entries as $token => $entry) {
                if (
                    is_array($entry)
                    && hash_equals((string) ($entry['slot'] ?? ''), $slot)
                    && hash_equals((string) ($entry['meta']['booker_email'] ?? ''), $email)
                ) {
                    $statusToken = (string) ($entry['status_token'] ?? '');
                    if ($statusToken === '') {
                        $statusToken = wp_generate_uuid4();
                        $entries[$token]['status_token'] = $statusToken;
                        update_option(self::OPTION, $entries, false);
                    }
                    self::schedule((string) $token, (int) ($entry['opens_at'] ?? $opensAt));
                    return [
                        'token' => (string) $token,
                        'statusToken' => $statusToken,
                        'created' => false,
                    ];
                }
            }

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
        } catch (\Exception $exception) {
            throw new CustomException($exception->getMessage(), $exception->getCode(), null);
        }
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
            if (in_array($slot, (array) get_option(Bookings::SLOTS_OPTION, []), true)) {
                self::delete($token);
                return;
            }
            if (in_array($slot, TokenManager::getPendingSlots(), true)) {
                self::schedule($token, min($closesAt, $now + self::RETRY_SECONDS));
                return;
            }

            if (!self::sendOpeningMail($token, $entry)) {
                self::schedule($token, min($closesAt, $now + self::RETRY_SECONDS));
                return;
            }

            $entries = self::getEntries();
            if (isset($entries[$token]) && is_array($entries[$token])) {
                $entries[$token]['notified_at'] = $now;
                update_option(self::OPTION, $entries, false);
            }
        } catch (\Exception $exception) {
            throw new CustomException($exception->getMessage(), $exception->getCode(), null);
        }
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
            $lockOption = self::CLAIM_LOCK_PREFIX . md5($slot);
            $lockCreated = add_option($lockOption, $now, '', false);
            if (!$lockCreated && (int) get_option($lockOption, 0) < $now - self::CLAIM_LOCK_TTL) {
                delete_option($lockOption);
                $lockCreated = add_option($lockOption, $now, '', false);
            }
            if (!$lockCreated) {
                return new \WP_Error(
                    'rrze_appointment_opening_busy',
                    __('This appointment is currently being requested. Please try again shortly.', 'rrze-appointment')
                );
            }

            try {
                if (
                    in_array($slot, (array) get_option(Bookings::SLOTS_OPTION, []), true)
                    || in_array($slot, TokenManager::getPendingSlots(), true)
                ) {
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
            throw new CustomException($exception->getMessage(), $exception->getCode(), null);
        }
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

    public static function registrationUrl(string $statusToken): string
    {
        return add_query_arg('rrze_appt_opening_registered', $statusToken, home_url('/'));
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

    private static function delete(string $token): void
    {
        $entries = self::getEntries();
        if (isset($entries[$token])) {
            unset($entries[$token]);
            update_option(self::OPTION, $entries, false);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK, [$token]);
    }

    private static function schedule(string $token, int $timestamp): void
    {
        if ($timestamp <= time()) {
            $timestamp = time() + 1;
        }
        if (!wp_next_scheduled(self::CRON_HOOK, [$token])) {
            wp_schedule_single_event($timestamp, self::CRON_HOOK, [$token]);
        }
    }

    /**
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
        $slot = (string) ($entry['slot'] ?? '');
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');
        $bookingUrl = add_query_arg('rrze_appt_opening', $token, home_url('/'));
        $variables = [
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

        $templateId = (int) ($meta['tpl_id'] ?? 0);
        $template = $templateId > 0
            ? (MailTemplatePost::getTemplateForType($templateId, 'booking_opening_notification') ?? [])
            : [];
        $default = MailTemplatePost::getDefault('booking_opening_notification');
        $subject = Settings::renderTemplate(
            !empty($template['subject']) ? $template['subject'] : $default['subject'],
            $variables
        );
        $plain = !empty($template['body']) ? $template['body'] : $default['body'];
        $html = !empty($template['body_html']) ? $template['body_html'] : $default['body_html'];

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

        return Settings::sendMail(
            sanitize_email((string) ($meta['booker_email'] ?? '')),
            $subject,
            Settings::renderTemplate($plain, $variables),
            Settings::renderTemplate($html, $variables),
            [],
            MailTemplate::STATUS_WARNING
        );
    }
}
