<?php

namespace RRZE\Appointment\Booking;

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Configuration\PluginSettings;
use RRZE\Appointment\Mail\MailTemplate;
use RRZE\Appointment\Mail\MailTemplatePost;
use RRZE\Appointment\Mail\Mailer;
use RRZE\Appointment\Notification\Reminder;
use RRZE\Appointment\Storage\BookingStorage;
use RRZE\Appointment\Storage\BookingScheduler;

defined('ABSPATH') || exit;

/**
 * Provides access to persisted bookings and their lifecycle operations.
 *
 * Booked slots are stored as strings in {@see self::SLOTS_OPTION}. Metadata for
 * each slot is stored in {@see self::META_OPTION}, keyed by the same slot
 * string. A slot uses the sortable format `Y-m-d H:i-H:i`.
 */
final class Bookings
{
    /** Option containing the list of booked slot strings. */
    public const SLOTS_OPTION = 'rrze_appointment_booked_slots';

    /** Option containing booking metadata keyed by slot string. */
    public const META_OPTION = 'rrze_appointment_booked_slots_meta';

    /** Metadata flag preventing permanent disclosure in the administration area. */
    public const ADMIN_ANONYMIZED_META_KEY = 'admin_anonymized';

    /** Maximum number of characters accepted for a cancellation reason. */
    public const MAX_CANCELLATION_REASON_LENGTH = 2000;

    private const MAX_RETENTION_DAYS = 3650;
    private const WAITLIST_LOCK_TTL = 5 * MINUTE_IN_SECONDS;
    private const WAITLIST_NOTIFIED_SLOTS_KEY = 'waitlist_notified_slots';
    private const WAITLIST_LOCK_PREFIX = 'rrze_appointment_waitlist_lock_';
    private const CANCELLATION_TEMPLATE_TYPE = 'cancellation';
    private const WAITLIST_TEMPLATE_TYPE = 'waitlist_earlier_slot';

    /**
     * Permanently removes completed bookings after their retention period.
     *
     * This data-retention operation deliberately bypasses cancellation emails
     * and waitlist notifications.
     *
     * @return int Number of removed slots.
     * @throws AppointmentException When persisted booking data cannot be processed.
     */
    public static function cleanupExpired(int $retentionDays): int
    {
        try {
            $retentionDays = min(self::MAX_RETENTION_DAYS, max(0, $retentionDays));
            $slots = self::getStoredSlots();
            $allMeta = self::getStoredMetadata();
            $expiredSlots = self::findExpiredSlots($slots, $allMeta, $retentionDays);

            if ($expiredSlots === []) {
                return 0;
            }

            self::removeExpiredBookings($expiredSlots, $slots, $allMeta);
            self::removeExpiredTokens($expiredSlots);

            return count($expiredSlots);
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Returns current bookings or retained past bookings, filtered by date or person.
     *
     * Current bookings include appointments that have not ended yet. The past
     * view requires a positive retention period and excludes expired records,
     * even if the scheduled cleanup has not run yet. Past bookings are sorted
     * newest first; current bookings are sorted earliest first.
     * Dates use `Y-m-d` and both date boundaries are inclusive.
     *
     * @param array{view?: string, date_from?: string, date_to?: string, person_id?: int|string} $filter
     * @return array<int, array{
     *     slot: string,
     *     date: string,
     *     time: string,
     *     title: mixed,
     *     location: mixed,
     *     person_id: int,
     *     person_name: string,
     *     booker_name: mixed,
     *     booker_email: mixed,
     *     tpl_id: int,
     *     admin_anonymized: bool
     * }>
     * @throws AppointmentException When persisted booking data cannot be processed.
     */
    public static function getAll(#[\SensitiveParameter] array $filter = []): array
    {
        try {
            $showPast = ($filter['view'] ?? 'current') === 'past';
            $retentionDays = min(self::MAX_RETENTION_DAYS, max(0, (int) PluginSettings::get('retention_days')));
            if ($showPast && $retentionDays === 0) {
                return [];
            }

            $now = current_datetime();
            $allMeta = self::getStoredMetadata();
            $bookings = [];
            $hideAllDetails = (bool) PluginSettings::get('sensitive_mode_enabled');

            foreach (self::getStoredSlots() as $slot) {
                if (!is_string($slot)) {
                    continue;
                }

                $slotEnd = self::getSlotEnd($slot);
                if ($slotEnd === null || ($slotEnd <= $now) !== $showPast) {
                    continue;
                }
                if ($showPast && $slotEnd->modify("+{$retentionDays} days") <= $now) {
                    continue;
                }

                $booking = self::buildBooking(
                    $slot,
                    self::getSlotMetadata($allMeta, $slot),
                    $hideAllDetails
                );
                if (self::matchesFilter($booking, $filter)) {
                    $bookings[] = $booking;
                }
            }

            $sortDirection = $showPast ? -1 : 1;
            usort(
                $bookings,
                static fn(array $first, array $second): int => $sortDirection * strcmp($first['slot'], $second['slot'])
            );

            return $bookings;
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Cancels a booking and notifies the booker, host, and eligible waitlist.
     *
     * @return bool False when the requested slot is not booked.
     * @throws AppointmentException When the cancellation cannot be completed.
     */
    public static function cancel(#[\SensitiveParameter] string $slot, #[\SensitiveParameter] string $reason = ''): bool
    {
        try {
            $slots = self::getStoredSlots();
            if (!in_array($slot, $slots, true)) {
                return false;
            }

            $allMeta = self::getStoredMetadata();
            $meta = self::getSlotMetadata($allMeta, $slot);

            BookingScheduler::clear(Reminder::CRON_HOOK, $slot);
            self::sendCancellationMail($slot, $meta, $reason);
            $remainingMeta = self::removeStoredBooking($slot, $slots, $allMeta);
            self::notifyWaitlist($slot, $meta, $remainingMeta);

            return true;
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Removes one booking from the slot and metadata options.
     *
     * @param array<int, mixed>    $slots   Stored slot values.
     * @param array<string, mixed> $allMeta Stored metadata keyed by slot.
     * @return array<string, mixed> Remaining booking metadata.
     */
    private static function removeStoredBooking(#[\SensitiveParameter] string $slot, #[\SensitiveParameter] array $slots, #[\SensitiveParameter] array $allMeta): array
    {
        $remainingSlots = array_values(array_filter(
            $slots,
            static fn($bookedSlot): bool => $bookedSlot !== $slot
        ));
        BookingStorage::set(self::SLOTS_OPTION, $remainingSlots);

        unset($allMeta[$slot]);
        BookingStorage::set(self::META_OPTION, $allMeta);

        return $allMeta;
    }

    /**
     * Sends an earlier-slot notification once per distinct available slot.
     *
     * The notification history is stored in the existing booking metadata, so
     * older bookings need no migration. A short-lived option lock prevents
     * duplicate messages from concurrent requests.
     *
     * @param array<string, mixed> $newSlotMeta Metadata for the available slot.
     * @param array<string, mixed> $bookedMeta  Metadata for the current booking.
     * @return bool Whether an email was sent and recorded.
     * @throws \Exception When a dependency throws while preparing or sending the email.
     */
    public static function sendWaitlistNotificationStatic(
        #[\SensitiveParameter] string $availableSlot,
        #[\SensitiveParameter] array $newSlotMeta,
        #[\SensitiveParameter] string $bookedSlot,
        #[\SensitiveParameter] array $bookedMeta
    ): bool {
        $bookedMeta = self::mergeCurrentBookingMetadata($bookedSlot, $bookedMeta);
        if (!self::shouldSendWaitlistNotification($availableSlot, $bookedSlot, $bookedMeta)) {
            return false;
        }

        $lockOption = self::acquireWaitlistNotificationLock($bookedSlot);
        if ($lockOption === '') {
            return false;
        }

        try {
            $allMeta = self::getStoredMetadata();
            if (!isset($allMeta[$bookedSlot]) || !is_array($allMeta[$bookedSlot])) {
                return false;
            }

            $currentMeta = $allMeta[$bookedSlot];
            $bookedMeta = array_merge($bookedMeta, $currentMeta);
            if (!self::shouldSendWaitlistNotification($availableSlot, $bookedSlot, $bookedMeta)) {
                return false;
            }

            $variables = self::buildWaitlistTemplateVariables(
                $availableSlot,
                $newSlotMeta,
                $bookedSlot,
                $bookedMeta
            );
            $template = self::getMailTemplate(
                (int) ($bookedMeta['tpl_id'] ?? 0),
                self::WAITLIST_TEMPLATE_TYPE
            );
            $template['body'] = self::ensurePlainTemplateLink(
                $template['body'],
                '[imprint_link]',
                __('Imprint', 'rrze-appointment')
            );
            $template['body_html'] = self::ensureHtmlTemplateLink(
                $template['body_html'],
                '[imprint_link]',
                __('Imprint', 'rrze-appointment')
            );
            $template['body'] = self::ensurePlainTemplateLink(
                $template['body'],
                '[waitlist_optout_link]',
                __('Stop earlier appointment notifications', 'rrze-appointment')
            );
            $template['body_html'] = self::ensureHtmlTemplateLink(
                $template['body_html'],
                '[waitlist_optout_link]',
                __('Stop earlier appointment notifications', 'rrze-appointment')
            );

            if (!self::sendRenderedMail((string) $variables['[email]'], $template, $variables)) {
                return false;
            }

            self::rememberWaitlistNotification($bookedSlot, $availableSlot);
            return true;
        } finally {
            delete_option($lockOption);
        }
    }

    /**
     * Disables earlier-slot notifications without changing the booking.
     *
     * @return bool False when the booking does not exist.
     */
    public static function disableWaitlistNotifications(#[\SensitiveParameter] string $slot): bool
    {
        return self::setWaitlistNotifications($slot, false);
    }

    /**
     * Re-enables earlier-slot notifications for an existing booking.
     *
     * @return bool False when the booking does not exist.
     */
    public static function enableWaitlistNotifications(#[\SensitiveParameter] string $slot): bool
    {
        return self::setWaitlistNotifications($slot, true);
    }

    /**
     * Returns the hosts referenced by bookings, sorted by display name.
     *
     * @return array<int, string> Person names keyed by post ID.
     * @throws AppointmentException When persisted booking data cannot be processed.
     */
    public static function getPersonsFromBookings(): array
    {
        try {
            if ((bool) PluginSettings::get('sensitive_mode_enabled')) {
                return [];
            }

            $persons = [];

            foreach (self::getStoredMetadata() as $meta) {
                if (
                    !is_array($meta)
                    || !empty($meta[self::ADMIN_ANONYMIZED_META_KEY])
                ) {
                    continue;
                }

                $personId = (int) ($meta['person_id'] ?? 0);
                if ($personId > 0 && !isset($persons[$personId])) {
                    $persons[$personId] = self::resolvePersonName($personId, $meta);
                }
            }

            asort($persons);
            return $persons;
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Finds slots whose end time plus retention period has elapsed.
     *
     * @param array<int, mixed>            $slots
     * @param array<string, mixed>         $allMeta
     * @return array<string, true> Expired slots represented as a lookup set.
     */
    private static function findExpiredSlots(#[\SensitiveParameter] array $slots, #[\SensitiveParameter] array $allMeta, int $retentionDays): array
    {
        $slotNames = array_filter(
            array_merge($slots, array_keys($allMeta)),
            'is_string'
        );
        $knownSlots = array_unique($slotNames);
        $now = current_datetime();
        $expiredSlots = [];

        foreach ($knownSlots as $slot) {
            $slotEnd = self::getSlotEnd($slot);
            if ($slotEnd !== null && $slotEnd->modify("+{$retentionDays} days") <= $now) {
                $expiredSlots[$slot] = true;
            }
        }

        return $expiredSlots;
    }

    /**
     * Removes expired slots, metadata, reminders, and notification locks.
     *
     * @param array<string, true>  $expiredSlots
     * @param array<int, mixed>    $slots
     * @param array<string, mixed> $allMeta
     */
    private static function removeExpiredBookings(#[\SensitiveParameter] array $expiredSlots, #[\SensitiveParameter] array $slots, #[\SensitiveParameter] array $allMeta): void
    {
        $remainingSlots = array_values(array_filter(
            $slots,
            static fn($slot): bool => !is_string($slot) || !isset($expiredSlots[$slot])
        ));
        BookingStorage::set(self::SLOTS_OPTION, $remainingSlots);

        foreach ($expiredSlots as $slot => $_unused) {
            unset($allMeta[$slot]);
            BookingScheduler::clear(Reminder::CRON_HOOK, $slot);
            delete_option(self::getWaitlistLockOption($slot));
        }

        BookingStorage::set(self::META_OPTION, $allMeta);
    }

    /**
     * Removes cancellation and pending tokens associated with expired slots.
     *
     * @param array<string, true> $expiredSlots
     */
    private static function removeExpiredTokens(#[\SensitiveParameter] array $expiredSlots): void
    {
        $tokens = BookingStorage::get(TokenManager::CANCEL_OPTION);
        foreach ($tokens as $token => $entry) {
            $slot = is_array($entry) ? ($entry['slot'] ?? '') : $entry;
            if (is_string($slot) && isset($expiredSlots[$slot])) {
                unset($tokens[$token]);
            }
        }
        BookingStorage::set(TokenManager::CANCEL_OPTION, $tokens);

        $pending = BookingStorage::get(TokenManager::PENDING_OPTION);
        foreach ($pending as $token => $entry) {
            $slot = is_array($entry) ? ($entry['slot'] ?? '') : '';
            if (is_string($slot) && isset($expiredSlots[$slot])) {
                unset($pending[$token]);
                BookingScheduler::clear(TokenManager::PENDING_EXPIRY_HOOK, $token);
            }
        }
        BookingStorage::set(TokenManager::PENDING_OPTION, $pending);
    }

    /**
     * Builds the normalized representation returned by {@see self::getAll()}.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function buildBooking(#[\SensitiveParameter] string $slot, #[\SensitiveParameter] array $meta, bool $hideDetails): array
    {
        $parts = self::parseSlot($slot);
        $isAnonymized = $hideDetails || !empty($meta[self::ADMIN_ANONYMIZED_META_KEY]);
        $personId = $isAnonymized ? 0 : (int) ($meta['person_id'] ?? 0);

        return [
            'slot' => $slot,
            'date' => $parts['date'] ?? '',
            'time' => $parts === null ? '' : $parts['start'] . '-' . $parts['end'],
            'title' => $meta['title'] ?? '',
            'location' => $isAnonymized ? '' : ($meta['location'] ?? ''),
            'person_id' => $personId,
            'person_name' => $isAnonymized ? '' : self::resolvePersonName($personId, $meta),
            'booker_name' => $isAnonymized ? '' : ($meta['booker_name'] ?? ''),
            'booker_email' => $isAnonymized ? '' : ($meta['booker_email'] ?? ''),
            'tpl_id' => $isAnonymized ? 0 : (int) ($meta['tpl_id'] ?? 0),
            'admin_anonymized' => $isAnonymized,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $filter
     */
    private static function matchesFilter(#[\SensitiveParameter] array $booking, #[\SensitiveParameter] array $filter): bool
    {
        if (!empty($filter['date_from']) && $booking['date'] < $filter['date_from']) {
            return false;
        }
        if (!empty($filter['date_to']) && $booking['date'] > $filter['date_to']) {
            return false;
        }
        if (!empty($filter['person_id']) && $booking['person_id'] !== (int) $filter['person_id']) {
            return false;
        }

        return true;
    }

    /**
     * Notifies waitlisted bookers with a later booking for the same host.
     *
     * Notification failures are intentionally non-critical and must not roll
     * back a completed cancellation.
     *
     * @param array<string, mixed> $cancelledMeta
     * @param array<string, mixed> $allMeta Remaining metadata after cancellation.
     */
    private static function notifyWaitlist(#[\SensitiveParameter] string $cancelledSlot, #[\SensitiveParameter] array $cancelledMeta, #[\SensitiveParameter] array $allMeta): void
    {
        try {
            $cancelledDate = self::getSlotDate($cancelledSlot);
            $cancelledPersonId = (int) ($cancelledMeta['person_id'] ?? 0);
            $today = current_time('Y-m-d');

            if ($cancelledDate === '' || $cancelledDate < $today) {
                return;
            }

            foreach ($allMeta as $bookedSlot => $bookedMeta) {
                if (
                    !is_string($bookedSlot)
                    || !is_array($bookedMeta)
                    || !self::isEligibleWaitlistBooking(
                        $bookedSlot,
                        $bookedMeta,
                        $cancelledSlot,
                        $cancelledPersonId,
                        $today
                    )
                ) {
                    continue;
                }

                self::sendWaitlistNotificationStatic(
                    $cancelledSlot,
                    $cancelledMeta,
                    $bookedSlot,
                    $bookedMeta
                );
            }
        } catch (\Exception) {
            // A waitlist email must not prevent an otherwise valid cancellation.
        }
    }

    /** @param array<string, mixed> $bookedMeta */
    private static function isEligibleWaitlistBooking(
        #[\SensitiveParameter] string $bookedSlot,
        #[\SensitiveParameter] array $bookedMeta,
        #[\SensitiveParameter] string $availableSlot,
        int $personId,
        #[\SensitiveParameter] string $today
    ): bool {
        return !empty($bookedMeta['booker_waitlist'])
            && (int) ($bookedMeta['person_id'] ?? 0) === $personId
            && $availableSlot < $bookedSlot
            && self::getSlotDate($bookedSlot) >= $today
            && sanitize_email((string) ($bookedMeta['booker_email'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $bookedMeta
     */
    private static function shouldSendWaitlistNotification(
        #[\SensitiveParameter] string $availableSlot,
        #[\SensitiveParameter] string $bookedSlot,
        #[\SensitiveParameter] array $bookedMeta
    ): bool {
        if (empty($bookedMeta['booker_waitlist'])) {
            return false;
        }
        if (sanitize_email((string) ($bookedMeta['booker_email'] ?? '')) === '') {
            return false;
        }

        // The slot format is lexicographically sortable.
        if ($availableSlot === '' || $availableSlot >= $bookedSlot) {
            return false;
        }

        return !in_array($availableSlot, self::getWaitlistNotifiedSlots($bookedMeta), true);
    }

    /** @param array<string, mixed> $fallbackMeta */
    private static function mergeCurrentBookingMetadata(#[\SensitiveParameter] string $slot, #[\SensitiveParameter] array $fallbackMeta): array
    {
        $currentMeta = self::getSlotMetadata(self::getStoredMetadata(), $slot);
        return $currentMeta === [] ? $fallbackMeta : array_merge($fallbackMeta, $currentMeta);
    }

    /**
     * Acquires the short-lived lock used to deduplicate waitlist emails.
     *
     * @return string Lock option name, or an empty string when already locked.
     */
    private static function acquireWaitlistNotificationLock(#[\SensitiveParameter] string $bookedSlot): string
    {
        $option = self::getWaitlistLockOption($bookedSlot);
        $now = time();

        if (add_option($option, $now, '', false)) {
            return $option;
        }

        $createdAt = (int) get_option($option, 0);
        if ($createdAt > 0 && $createdAt < $now - self::WAITLIST_LOCK_TTL) {
            delete_option($option);
            if (add_option($option, $now, '', false)) {
                return $option;
            }
        }

        return '';
    }

    /**
     * Returns the lock option name for a booked slot.
     */
    private static function getWaitlistLockOption(#[\SensitiveParameter] string $slot): string
    {
        return self::WAITLIST_LOCK_PREFIX . BookingStorage::lockReference('waitlist', $slot);
    }

    /**
     * Records that an available slot was already sent to a waitlisted booker.
     */
    private static function rememberWaitlistNotification(#[\SensitiveParameter] string $bookedSlot, #[\SensitiveParameter] string $availableSlot): void
    {
        $allMeta = self::getStoredMetadata();
        if (!isset($allMeta[$bookedSlot]) || !is_array($allMeta[$bookedSlot])) {
            return;
        }

        $notifiedSlots = self::getWaitlistNotifiedSlots($allMeta[$bookedSlot]);
        if (in_array($availableSlot, $notifiedSlots, true)) {
            return;
        }

        $notifiedSlots[] = $availableSlot;
        $allMeta[$bookedSlot][self::WAITLIST_NOTIFIED_SLOTS_KEY] = $notifiedSlots;
        unset($allMeta[$bookedSlot]['waitlist_notified_slot']);
        BookingStorage::set(self::META_OPTION, $allMeta);
    }

    /**
     * Reads current and legacy notification history from booking metadata.
     *
     * @param array<string, mixed> $bookedMeta
     * @return array<int, string>
     */
    private static function getWaitlistNotifiedSlots(#[\SensitiveParameter] array $bookedMeta): array
    {
        $notifiedSlots = $bookedMeta[self::WAITLIST_NOTIFIED_SLOTS_KEY] ?? [];
        if (!is_array($notifiedSlots)) {
            $notifiedSlots = [];
        }

        $legacyNotifiedSlot = (string) ($bookedMeta['waitlist_notified_slot'] ?? '');
        if ($legacyNotifiedSlot !== '') {
            $notifiedSlots[] = $legacyNotifiedSlot;
        }

        return array_values(array_unique(array_filter($notifiedSlots, 'is_string')));
    }

    /**
     * Updates the earlier-slot notification preference for one booking.
     */
    private static function setWaitlistNotifications(#[\SensitiveParameter] string $slot, bool $enabled): bool
    {
        try {
            $allMeta = self::getStoredMetadata();
            if (!isset($allMeta[$slot]) || !is_array($allMeta[$slot])) {
                return false;
            }

            $currentValue = isset($allMeta[$slot]['booker_waitlist'])
                ? (bool) $allMeta[$slot]['booker_waitlist']
                : null;
            if ($currentValue !== $enabled) {
                $allMeta[$slot]['booker_waitlist'] = $enabled;
                BookingStorage::set(self::META_OPTION, $allMeta);
            }

            return true;
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function sendCancellationMail(#[\SensitiveParameter] string $slot, #[\SensitiveParameter] array $meta, #[\SensitiveParameter] string $reason): void
    {
        try {
            $parts = self::parseSlot($slot) ?? ['date' => '', 'start' => '', 'end' => ''];
            $personId = (int) ($meta['person_id'] ?? 0);
            $bookerEmail = (string) ($meta['booker_email'] ?? '');
            $reason = (bool) PluginSettings::get('cancellation_reason_enabled')
                ? self::normalizeCancellationReason($reason)
                : '';
            $variables = [
                '[title]' => $meta['title'] ?? __('Appointment', 'rrze-appointment'),
                '[date]' => self::formatDate($parts['date']),
                '[time]' => self::formatTimeRange($parts['start'], $parts['end']),
                '[location]' => ($meta['location'] ?? '') ?: '–',
                '[person_name]' => self::resolvePersonName($personId, $meta),
                '[name]' => ($meta['booker_name'] ?? '') ?: '–',
                '[email]' => $bookerEmail ?: '–',
                '[questions]' => '',
                '[cancel_link]' => '',
                '[imprint_link]' => TokenManager::imprintUrl(),
                '[post_link]' => esc_url_raw($meta['post_link'] ?? home_url('/')),
                '[cancellation_reason]' => $reason === ''
                    ? ''
                    : __('Reason for cancellation', 'rrze-appointment') . ': ' . $reason,
            ];
            $htmlVariables = array_merge($variables, [
                '[cancellation_reason]' => $reason === ''
                    ? ''
                    : '<h2 style="margin:28px 0 8px;color:#1f2937;font-size:20px;line-height:28px;">'
                        . esc_html__('Reason for cancellation', 'rrze-appointment')
                        . '</h2><p style="margin:0;">'
                        . nl2br(esc_html($reason))
                        . '</p>',
            ]);

            $template = self::getMailTemplate(
                (int) ($meta['tpl_id'] ?? 0),
                self::CANCELLATION_TEMPLATE_TYPE
            );
            $template['body'] = self::ensureTemplatePlaceholder(
                $template['body'],
                '[cancellation_reason]',
                "\n\n"
            );
            $template['body_html'] = self::ensureTemplatePlaceholder(
                $template['body_html'],
                '[cancellation_reason]'
            );
            $template['body'] = self::ensurePlainTemplateLink(
                $template['body'],
                '[imprint_link]',
                __('Imprint', 'rrze-appointment'),
                "\n"
            );
            $template['body_html'] = self::ensureHtmlTemplateLink(
                $template['body_html'],
                '[imprint_link]',
                __('Imprint', 'rrze-appointment')
            );

            $recipients = [
                sanitize_email((string) ($meta['person_email'] ?? '')),
                $bookerEmail,
            ];
            foreach ($recipients as $recipient) {
                if ($recipient === '') {
                    continue;
                }

                self::sendRenderedMail(
                    $recipient,
                    $template,
                    $variables,
                    MailTemplate::STATUS_DANGER,
                    $htmlVariables
                );
            }
        } catch (\Throwable $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * @param array<string, mixed> $newSlotMeta
     * @param array<string, mixed> $bookedMeta
     * @return array<string, mixed>
     */
    private static function buildWaitlistTemplateVariables(
        #[\SensitiveParameter] string $availableSlot,
        #[\SensitiveParameter] array $newSlotMeta,
        #[\SensitiveParameter] string $bookedSlot,
        #[\SensitiveParameter] array $bookedMeta
    ): array {
        $available = self::parseSlot($availableSlot) ?? ['date' => '', 'start' => '', 'end' => ''];
        $booked = self::parseSlot($bookedSlot) ?? ['date' => '', 'start' => '', 'end' => ''];
        $personId = (int) ($newSlotMeta['person_id'] ?? 0);
        $personName = self::resolvePersonName($personId, array_merge($bookedMeta, $newSlotMeta));
        $bookerEmail = sanitize_email((string) ($bookedMeta['booker_email'] ?? ''));

        return [
            '[title]' => $newSlotMeta['title'] ?? $bookedMeta['title'] ?? '',
            '[date]' => self::formatDate($available['date']),
            '[time]' => self::formatTimeRange($available['start'], $available['end']),
            '[current_date]' => self::formatDate($booked['date']),
            '[current_time]' => self::formatTimeRange($booked['start'], $booked['end']),
            '[location]' => ($newSlotMeta['location'] ?? '') ?: '–',
            '[person_name]' => $personName ?: '–',
            '[name]' => ($bookedMeta['booker_name'] ?? '') ?: __('there', 'rrze-appointment'),
            '[email]' => $bookerEmail ?: '–',
            '[questions]' => '',
            '[imprint_link]' => TokenManager::imprintUrl(),
            '[post_link]' => esc_url_raw($bookedMeta['post_link'] ?? home_url('/')),
            '[waitlist_optout_link]' => TokenManager::getWaitlistOptOutUrlForSlot($bookedSlot),
        ];
    }

    /**
     * Returns a custom template merged with its defaults.
     *
     * @return array{subject: string, body: string, body_html: string}
     */
    private static function getMailTemplate(int $templateId, #[\SensitiveParameter] string $type): array
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
     * Appends a required placeholder link to a plain-text template.
     */
    private static function ensurePlainTemplateLink(
        #[\SensitiveParameter] string $template,
        #[\SensitiveParameter] string $placeholder,
        #[\SensitiveParameter] string $label,
        #[\SensitiveParameter] string $separator = "\n\n"
    ): string {
        if (strpos($template, $placeholder) !== false) {
            return $template;
        }

        return $template . $separator . $label . ': ' . $placeholder;
    }

    /**
     * Appends a placeholder when an older custom template does not contain it.
     */
    private static function ensureTemplatePlaceholder(
        #[\SensitiveParameter] string $template,
        #[\SensitiveParameter] string $placeholder,
        #[\SensitiveParameter] string $separator = ''
    ): string {
        if (strpos($template, $placeholder) !== false) {
            return $template;
        }

        return $template . $separator . $placeholder;
    }

    /**
     * Sanitizes and limits a user-provided cancellation reason.
     */
    private static function normalizeCancellationReason(#[\SensitiveParameter] string $reason): string
    {
        $reason = sanitize_textarea_field($reason);
        if (function_exists('mb_substr')) {
            return mb_substr($reason, 0, self::MAX_CANCELLATION_REASON_LENGTH);
        }

        return substr($reason, 0, self::MAX_CANCELLATION_REASON_LENGTH);
    }

    /**
     * Appends a required placeholder link to an HTML template.
     */
    private static function ensureHtmlTemplateLink(
        #[\SensitiveParameter] string $template,
        #[\SensitiveParameter] string $placeholder,
        #[\SensitiveParameter] string $label
    ): string {
        if (strpos($template, $placeholder) !== false) {
            return $template;
        }

        return $template . '<p><a href="' . $placeholder . '">' . $label . '</a></p>';
    }

    /**
     * @param array{subject: string, body: string, body_html: string} $template
     * @param array<string, mixed> $variables
     */
    private static function sendRenderedMail(
        #[\SensitiveParameter] string $recipient,
        #[\SensitiveParameter] array $template,
        #[\SensitiveParameter] array $variables,
        #[\SensitiveParameter] string $status = MailTemplate::STATUS_NEUTRAL,
        #[\SensitiveParameter] ?array $htmlVariables = null
    ): bool {
        $subject = Mailer::render($template['subject'], $variables);
        $plain = Mailer::render($template['body'], $variables);
        $html = Mailer::render($template['body_html'], $htmlVariables ?? $variables);

        return Mailer::send($recipient, $subject, $plain, $html, [], $status);
    }

    /**
     * Resolves the host name from stored metadata, person post fields, or a
     * stable fallback suitable for the administration screen and emails.
     *
     * @param array<string, mixed> $meta
     */
    private static function resolvePersonName(int $personId, #[\SensitiveParameter] array $meta = []): string
    {
        $storedName = trim((string) ($meta['person_name'] ?? ''));
        if ($storedName !== '') {
            return $storedName;
        }

        if ($personId <= 0) {
            $storedEmail = sanitize_email((string) ($meta['person_email'] ?? ''));
            return $storedEmail !== '' ? $storedEmail : '–';
        }

        $name = trim(implode(' ', array_filter([
            (string) get_post_meta($personId, 'person_honorificPrefix', true),
            (string) get_post_meta($personId, 'person_givenName', true),
            (string) get_post_meta($personId, 'person_familyName', true),
        ])));
        if ($name !== '') {
            return $name;
        }

        $title = trim((string) get_the_title($personId));
        return $title !== '' ? $title : "Person #{$personId}";
    }

    /**
     * Parses the ending time of a slot in the site timezone.
     */
    private static function getSlotEnd(#[\SensitiveParameter] string $slot): ?\DateTimeImmutable
    {
        $parts = self::parseSlot($slot);
        return $parts === null ? null : self::createDateTime($parts['date'], $parts['end']);
    }

    /**
     * Returns the date component of a slot, or an empty string when malformed.
     */
    private static function getSlotDate(#[\SensitiveParameter] string $slot): string
    {
        $parts = self::parseSlot($slot);
        return $parts['date'] ?? '';
    }

    /**
     * @return array{date: string, start: string, end: string}|null
     */
    private static function parseSlot(#[\SensitiveParameter] string $slot): ?array
    {
        [$date, $timeRange] = array_pad(explode(' ', $slot, 2), 2, '');
        [$start, $end] = array_pad(explode('-', $timeRange, 2), 2, '');

        if ($date === '' || $start === '' || $end === '') {
            return null;
        }

        return ['date' => $date, 'start' => $start, 'end' => $end];
    }

    /**
     * Creates a strictly validated date-time in the site timezone.
     */
    private static function createDateTime(#[\SensitiveParameter] string $date, #[\SensitiveParameter] string $time): ?\DateTimeImmutable
    {
        $value = $date . ' ' . $time;
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, wp_timezone());

        if ($dateTime === false || $dateTime->format('Y-m-d H:i') !== $value) {
            return null;
        }

        return $dateTime;
    }

    /**
     * Formats an ISO date using the site's configured date format.
     */
    private static function formatDate(#[\SensitiveParameter] string $date): string
    {
        return date_i18n(get_option('date_format'), strtotime($date));
    }

    /**
     * Formats a human-readable appointment time range.
     */
    private static function formatTimeRange(#[\SensitiveParameter] string $start, #[\SensitiveParameter] string $end): string
    {
        return $start . ' – ' . $end;
    }

    /**
     * Returns raw persisted slot values.
     *
     * @return array<int, mixed>
     */
    private static function getStoredSlots(): array
    {
        return BookingStorage::get(self::SLOTS_OPTION);
    }

    /**
     * Returns raw persisted booking metadata.
     *
     * @return array<string, mixed>
     */
    private static function getStoredMetadata(): array
    {
        return BookingStorage::get(self::META_OPTION);
    }

    /**
     * @param array<string, mixed> $allMeta
     * @return array<string, mixed>
     */
    private static function getSlotMetadata(#[\SensitiveParameter] array $allMeta, #[\SensitiveParameter] string $slot): array
    {
        $meta = $allMeta[$slot] ?? [];
        return is_array($meta) ? $meta : [];
    }

    /**
     * Converts internal exceptions to the plugin's public exception type.
     */
    private static function createException(\Throwable $exception): AppointmentException
    {
        return AppointmentException::fromThrowable($exception);
    }
}
