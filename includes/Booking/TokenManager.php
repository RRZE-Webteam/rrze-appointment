<?php

namespace RRZE\Appointment\Booking;

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Notification\BookingOpeningNotifier;
use RRZE\Appointment\Storage\BookingStorage;
use RRZE\Appointment\Storage\BookingScheduler;

defined('ABSPATH') || exit;

/**
 * Creates, validates, migrates, and removes appointment action tokens.
 */
final class TokenManager
{
    public const PENDING_OPTION = 'rrze_appointment_pending_slots';
    public const CANCEL_OPTION = 'rrze_appointment_cancel_tokens';
    public const PENDING_TTL = 1800;
    public const PENDING_EXPIRY_HOOK = 'rrze_appointment_expire_pending';
    public const EXPIRY_SCHEDULE_DELAY = 60;

    private const TOKEN_TYPE_BOOKED = 'booked';
    private const TOKEN_TYPE_PENDING = 'pending';
    private const CONFIRM_QUERY_KEY = 'rrze_appt_confirm';
    private const CANCEL_QUERY_KEY = 'rrze_appt_cancel';
    private const WAITLIST_OPT_OUT_QUERY_KEY = 'rrze_appt_waitlist_optout';
    private const IMPRINT_PAGE_SLUG = 'impressum';

    /**
     * Expires reservations and orphaned cancellation tokens during background cleanup.
     * Valid pre-upgrade tokens retain their original shape until a business write.
     */
    public static function cleanupPendingState(): void
    {
        try {
            $now = time();
            $pending = self::getOptionArray(self::PENDING_OPTION);
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            $remaining = array_filter($pending, static fn($entry): bool => self::isValidPendingEntry($entry, $now));
            $remainingTokens = $tokens;
            foreach ($tokens as $token => $entry) {
                if (is_array($entry) && ($entry['type'] ?? '') === self::TOKEN_TYPE_PENDING
                    && self::pendingTokenForCancellation($entry, $remaining, $now) === null) {
                    unset($remainingTokens[$token]);
                }
            }
            if ($remaining === $pending && $remainingTokens === $tokens) {
                return;
            }
            BookingStorage::setMany([self::PENDING_OPTION => $remaining, self::CANCEL_OPTION => $remainingTokens]);
            foreach (array_diff_key($pending, $remaining) as $token => $_entry) {
                BookingScheduler::clear(self::PENDING_EXPIRY_HOOK, (string) $token);
            }
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Reserves a free slot and issues both links in one commit under the site lock.
     *
     * The reservation is persisted before the scheduler clears old events and ensures
     * the new expiry. A scheduler error can therefore follow a successful reservation.
     *
     * @param string $slot Canonical slot identifier to reserve.
     * @param array<string, mixed> $meta Pending booking metadata.
     * @param string|null $openingToken Optional opening subscription consumed in the same commit.
     * @return string Confirmation token for the new reservation.
     * @throws AppointmentException When the slot or opening link is unavailable, or storage or scheduling fails.
     */
    public static function createPending(
        #[\SensitiveParameter] string $slot,
        #[\SensitiveParameter] array $meta,
        #[\SensitiveParameter] ?string $openingToken = null
    ): string
    {
        try {
            $now = time();
            $booked = self::getOptionArray(Bookings::SLOTS_OPTION);
            $confirmedMeta = self::getOptionArray(Bookings::META_OPTION);
            $pending = self::getOptionArray(self::PENDING_OPTION);
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            if ($slot === '' || in_array($slot, $booked, true) || array_key_exists($slot, $confirmedMeta)) {
                throw new AppointmentException(__('This appointment is no longer available.', 'rrze-appointment'));
            }
            $expired = [];
            foreach ($pending as $oldToken => $entry) {
                if (!is_array($entry) || ($entry['slot'] ?? null) !== $slot) {
                    continue;
                }
                if (self::isValidPendingEntry($entry, $now)) {
                    throw new AppointmentException(__('This appointment is no longer available.', 'rrze-appointment'));
                }
                $expired[] = (string) $oldToken;
                unset($pending[$oldToken]);
            }
            $tokens = self::removeSlotTokens($tokens, $slot, $expired);
            $token = wp_generate_uuid4();
            $cancelToken = self::generateToken($token);
            $expires = $now + self::PENDING_TTL;
            $pending[$token] = [
                'slot'    => $slot,
                'meta'    => $meta,
                'expires' => $expires,
                'cancel_token' => $cancelToken,
            ];
            $tokens[$cancelToken] = [
                'type' => self::TOKEN_TYPE_PENDING,
                'pending_token' => $token,
                'expires' => $expires,
            ];
            $updates = [self::PENDING_OPTION => $pending, self::CANCEL_OPTION => $tokens];
            if ($openingToken !== null) {
                $openings = self::getOptionArray(BookingOpeningNotifier::OPTION);
                $opening = $openings[$openingToken] ?? null;
                if (!is_array($opening) || ($opening['slot'] ?? null) !== $slot
                    || $now < (int) ($opening['opens_at'] ?? 0) || $now >= (int) ($opening['closes_at'] ?? 0)) {
                    throw new AppointmentException(__('This booking notification link is invalid or has expired.', 'rrze-appointment'));
                }
                unset($openings[$openingToken]);
                $updates[BookingOpeningNotifier::OPTION] = $openings;
            }
            BookingStorage::setMany($updates);
            foreach ($expired as $oldToken) {
                BookingScheduler::clear(self::PENDING_EXPIRY_HOOK, $oldToken);
            }
            if ($openingToken !== null) {
                BookingScheduler::clear(BookingOpeningNotifier::CRON_HOOK, $openingToken);
            }
            BookingScheduler::schedule(
                $expires + self::EXPIRY_SCHEDULE_DELAY,
                self::PENDING_EXPIRY_HOOK,
                $token
            );
            return $token;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Reads and consumes a valid pending booking.
     *
     * @deprecated Use completePending() to atomically persist a confirmation.
     * @return array<string, mixed>|null
     */
    public static function confirmPending(#[\SensitiveParameter] string $token): ?array
    {
        try {
            if (!self::getPending($token)) {
                return null;
            }

            return self::deletePending($token);
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Confirms a reservation with its slot, metadata and cancellation token atomically.
     *
     * Scheduling and mail delivery belong after this operation. No question answers
     * are accepted here: callers pass only the confirmed-metadata allowlist.
     *
     * @param string $token Existing confirmation token, including pre-upgrade tokens.
     * @param array<string, mixed> $meta Validated, persistable confirmed metadata.
     * @return array{slot: string, meta: array<string, mixed>}|null Null for an invalid/expired token.
     * @throws AppointmentException When the booking cannot be committed as a whole.
     */
    public static function completePending(#[\SensitiveParameter] string $token, #[\SensitiveParameter] array $meta): ?array
    {
        try {
            $entry = self::getPending($token);
            if ($entry === null) {
                return null;
            }
            $slot = (string) $entry['slot'];
            $slots = BookingStorage::get(Bookings::SLOTS_OPTION);
            $allMeta = BookingStorage::get(Bookings::META_OPTION);
            if (in_array($slot, $slots, true) || isset($allMeta[$slot])) {
                throw new AppointmentException('This appointment is already booked.');
            }
            $pending = self::getOptionArray(self::PENDING_OPTION);
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            foreach ($pending as $otherToken => $other) {
                if ((string) $otherToken !== $token && self::isValidPendingEntry($other)
                    && $other['slot'] === $slot) {
                    throw new AppointmentException('Conflicting reservations for this appointment must be resolved.');
                }
            }
            $tokens = self::removeSlotTokens($tokens, $slot, [$token]);
            $cancelToken = self::generateToken($slot);
            $tokens[$cancelToken] = ['slot' => $slot, 'type' => self::TOKEN_TYPE_BOOKED];
            $meta['cancel_token'] = $cancelToken;
            $allMeta[$slot] = $meta;
            $slots[] = $slot;
            unset($pending[$token]);
            BookingStorage::setMany([
                Bookings::SLOTS_OPTION => $slots,
                Bookings::META_OPTION => $allMeta,
                self::CANCEL_OPTION => $tokens,
                self::PENDING_OPTION => $pending,
            ]);
            return ['slot' => $slot, 'meta' => $meta];
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Retires previous cancellation rights before a slot is assigned or confirmed.
     *
     * @param array<string,mixed> $tokens Cancellation entries from both stores.
     * @param list<string> $pendingTokens Confirmation tokens being consumed or expired.
     * @return array<string,mixed> Cancellation tokens belonging to other appointments.
     */
    private static function removeSlotTokens(
        #[\SensitiveParameter] array $tokens,
        #[\SensitiveParameter] string $slot,
        #[\SensitiveParameter] array $pendingTokens
    ): array {
        foreach ($tokens as $cancel => $entry) {
            $linkedSlot = is_array($entry) ? ($entry['slot'] ?? null) : $entry;
            $linkedPending = is_array($entry) ? ($entry['pending_token'] ?? null) : null;
            if ($linkedSlot === $slot || in_array($linkedPending, $pendingTokens, true)) {
                unset($tokens[$cancel]);
            }
        }
        return $tokens;
    }

    /**
     * Reads a valid pending booking without consuming its confirmation token.
     *
     * @return array<string, mixed>|null
     */
    public static function getPending(#[\SensitiveParameter] string $token): ?array
    {
        try {
            if ($token === '') {
                return null;
            }

            $pending = self::getOptionArray(self::PENDING_OPTION);
            $entry = $pending[$token] ?? null;
            return self::isValidPendingEntry($entry) ? $entry : null;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Removes a pending booking when its scheduled expiry hook runs.
     */
    public static function expirePending(#[\SensitiveParameter] string $token): void
    {
        try {
            $entry = self::getOptionArray(self::PENDING_OPTION)[$token] ?? null;
            if (is_array($entry) && self::isExpired($entry, time())) {
                self::deletePending($token);
            } elseif (self::isValidPendingEntry($entry)) {
                BookingScheduler::schedule((int) $entry['expires'] + self::EXPIRY_SCHEDULE_DELAY, self::PENDING_EXPIRY_HOOK, $token);
            }
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Removes a pending booking and every linked cancellation token.
     *
     * @return array<string, mixed>|null Removed pending entry.
     */
    public static function deletePending(#[\SensitiveParameter] string $token): ?array
    {
        try {
            if ($token === '') {
                return null;
            }

            $pending = self::getOptionArray(self::PENDING_OPTION);
            $entry   = $pending[$token] ?? null;
            $slot = is_array($entry) ? self::normalizeString($entry['slot'] ?? '') : '';

            if ($entry === null) {
                return null;
            }
            unset($pending[$token]);

            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            foreach ($tokens as $cancelToken => $cancelEntry) {
                if (
                    !is_array($cancelEntry)
                    || ($cancelEntry['type'] ?? '') !== self::TOKEN_TYPE_PENDING
                ) {
                    continue;
                }

                $linkedPendingToken = self::normalizeString($cancelEntry['pending_token'] ?? '');
                $legacySlot = self::normalizeString($cancelEntry['slot'] ?? '');
                if (
                    $linkedPendingToken === $token
                    || ($linkedPendingToken === '' && $slot !== '' && $legacySlot === $slot)
                ) {
                    unset($tokens[$cancelToken]);
                }
            }
            BookingStorage::setMany([self::PENDING_OPTION => $pending, self::CANCEL_OPTION => $tokens]);
            BookingScheduler::clear(self::PENDING_EXPIRY_HOOK, $token);

            return is_array($entry) ? $entry : null;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Returns slots currently reserved by valid pending bookings.
     *
     * @return array<int, string>
     */
    public static function getPendingSlots(): array
    {
        try {
            $slots = [];
            $now = time();
            foreach (self::getOptionArray(self::PENDING_OPTION) as $entry) {
                if (self::isValidPendingEntry($entry, $now)) {
                    $slots[] = (string) $entry['slot'];
                }
            }
            return $slots;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Creates and stores a cancellation token for a confirmed booking.
     */
    public static function createCancelToken(#[\SensitiveParameter] string $slot): string
    {
        try {
            if (!in_array($slot, self::getOptionArray(Bookings::SLOTS_OPTION), true)) {
                throw new AppointmentException('Booking not found.');
            }
            $allMeta = self::getOptionArray(Bookings::META_OPTION);
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            $token = self::generateToken($slot);
            $tokens[$token] = ['slot' => $slot, 'type' => self::TOKEN_TYPE_BOOKED];
            if (isset($allMeta[$slot]) && is_array($allMeta[$slot])) {
                $allMeta[$slot]['cancel_token'] = $token;
            }
            BookingStorage::setMany([self::CANCEL_OPTION => $tokens, Bookings::META_OPTION => $allMeta]);

            return $token;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Reuses a reservation's issued cancellation link, including slot-based legacy links.
     * Issues a link atomically for older reservations lacking one.
     */
    public static function createPendingCancelToken(#[\SensitiveParameter] string $pendingToken): string
    {
        try {
            $pending = self::getOptionArray(self::PENDING_OPTION);
            $entry   = $pending[$pendingToken] ?? null;
            $now = time();
            if (!self::isValidPendingEntry($entry, $now)) {
                throw new AppointmentException('Pending booking not found.');
            }

            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            $issued = self::normalizeString($entry['cancel_token'] ?? '');
            if ($issued !== '' && is_array($tokens[$issued] ?? null)
                && self::pendingTokenForCancellation($tokens[$issued], $pending, $now) === $pendingToken) {
                return $issued;
            }
            foreach ($tokens as $existing => $cancelEntry) {
                if (is_array($cancelEntry) && self::pendingTokenForCancellation($cancelEntry, $pending, $now) === $pendingToken) {
                    return (string) $existing;
                }
            }
            $token = self::generateToken($pendingToken);
            $tokens[$token] = [
                'type'          => self::TOKEN_TYPE_PENDING,
                'pending_token' => $pendingToken,
                'expires'       => (int) $entry['expires'],
            ];
            $pending[$pendingToken]['cancel_token'] = $token;
            BookingStorage::setMany([self::PENDING_OPTION => $pending, self::CANCEL_OPTION => $tokens]);

            return $token;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Returns a stable cancellation URL for a confirmed booking slot.
     */
    public static function getCancelUrlForSlot(#[\SensitiveParameter] string $slot): string
    {
        try {
            $allMeta = self::getOptionArray(Bookings::META_OPTION);
            $slotMeta = $allMeta[$slot] ?? null;
            $token = is_array($slotMeta)
                ? self::normalizeString($slotMeta['cancel_token'] ?? '')
                : '';
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            if ($token !== '' && self::bookedCancellationSlot($tokens[$token] ?? null) === $slot) {
                return self::cancelUrl($token);
            }
            foreach ($tokens as $existing => $cancelEntry) {
                if (self::bookedCancellationSlot($cancelEntry) === $slot) {
                    return self::cancelUrl((string) $existing);
                }
            }
            return self::cancelUrl(self::createCancelToken($slot));
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Returns a stable waitlist opt-out URL for a confirmed booking slot.
     */
    public static function getWaitlistOptOutUrlForSlot(#[\SensitiveParameter] string $slot): string
    {
        try {
            $allMeta = self::getOptionArray(Bookings::META_OPTION);
            if (!isset($allMeta[$slot]) || !is_array($allMeta[$slot])) {
                throw new \RuntimeException('Booking not found.');
            }

            $token = self::normalizeString($allMeta[$slot]['waitlist_optout_token'] ?? '');
            if ($token === '') {
                $token = self::generateToken($slot, 'nonce');
                $allMeta[$slot]['waitlist_optout_token'] = $token;
                BookingStorage::set(Bookings::META_OPTION, $allMeta);
            }

            return self::waitlistOptOutUrl($token);
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Resolves a waitlist opt-out token to its confirmed booking slot.
     */
    public static function validateWaitlistOptOutToken(#[\SensitiveParameter] string $token): ?string
    {
        try {
            if ($token === '') {
                return null;
            }

            $allMeta = self::getOptionArray(Bookings::META_OPTION);
            foreach ($allMeta as $slot => $meta) {
                if (!is_array($meta)) {
                    continue;
                }

                $storedToken = self::normalizeString($meta['waitlist_optout_token'] ?? '');
                if ($storedToken !== '' && hash_equals($storedToken, $token)) {
                    return (string) $slot;
                }
            }

            return null;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Validates legacy, confirmed, and pending cancellation tokens.
     *
     * @return array<string, mixed>|null
     */
    public static function validateCancelToken(#[\SensitiveParameter] string $token): ?array
    {
        try {
            if ($token === '') {
                return null;
            }

            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            $entry  = $tokens[$token] ?? null;
            $slot = self::bookedCancellationSlot($entry);
            if ($slot !== '') {
                return in_array($slot, self::getOptionArray(Bookings::SLOTS_OPTION), true)
                    ? ['slot' => $slot, 'type' => self::TOKEN_TYPE_BOOKED]
                    : null;
            }
            if (!is_array($entry)) {
                return null;
            }
            $pending = self::getOptionArray(self::PENDING_OPTION);
            $pendingToken = self::pendingTokenForCancellation($entry, $pending, time());
            if ($pendingToken === null) {
                return null;
            }
            $pendingEntry = $pending[$pendingToken];
            return [
                'type'          => self::TOKEN_TYPE_PENDING,
                'pending_token' => $pendingToken,
                'slot'          => self::normalizeString($pendingEntry['slot'] ?? ''),
                'expires'       => (int) $pendingEntry['expires'],
            ];
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Returns the slot of a booked cancellation token in either historical representation.
     *
     * @param mixed $entry Stored token entry.
     */
    private static function bookedCancellationSlot(#[\SensitiveParameter] mixed $entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }
        return is_array($entry) && ($entry['type'] ?? self::TOKEN_TYPE_BOOKED) === self::TOKEN_TYPE_BOOKED
            ? self::normalizeString($entry['slot'] ?? '')
            : '';
    }

    /**
     * Resolves a valid pending cancellation reference without modifying the two old options.
     *
     * @param array<string, mixed> $entry Cancellation entry, possibly containing only a slot.
     * @param array<string, mixed> $pending Current reservations from both storage formats.
     * @return string|null Confirmation token, or null for an unresolvable or expired link.
     */
    private static function pendingTokenForCancellation(
        #[\SensitiveParameter] array $entry,
        #[\SensitiveParameter] array $pending,
        int $now
    ): ?string {
        if (($entry['type'] ?? null) !== self::TOKEN_TYPE_PENDING
            || (isset($entry['expires']) && self::isExpired($entry, $now))) {
            return null;
        }
        $linked = self::normalizeString($entry['pending_token'] ?? '');
        if ($linked !== '') {
            return self::isValidPendingEntry($pending[$linked] ?? null, $now) ? $linked : null;
        }
        $slot = self::normalizeString($entry['slot'] ?? '');
        $found = null;
        foreach ($pending as $token => $candidate) {
            if (self::isValidPendingEntry($candidate, $now) && $candidate['slot'] === $slot) {
                if ($found !== null) {
                    throw new AppointmentException('Conflicting reservations for this appointment must be resolved.');
                }
                $found = (string) $token;
            }
        }
        return $found;
    }

    /**
     * Removes a cancellation token after it has been consumed.
     */
    public static function deleteCancelToken(#[\SensitiveParameter] string $token): void
    {
        try {
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            unset($tokens[$token]);
            BookingStorage::set(self::CANCEL_OPTION, $tokens);
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Builds a public booking-confirmation URL.
     */
    public static function confirmUrl(#[\SensitiveParameter] string $token): string
    {
        return self::actionUrl(self::CONFIRM_QUERY_KEY, $token);
    }

    /**
     * Builds a public booking-cancellation URL.
     */
    public static function cancelUrl(#[\SensitiveParameter] string $token): string
    {
        return self::actionUrl(self::CANCEL_QUERY_KEY, $token);
    }

    /**
     * Builds a public waitlist-preference URL.
     */
    public static function waitlistOptOutUrl(#[\SensitiveParameter] string $token): string
    {
        return self::actionUrl(self::WAITLIST_OPT_OUT_QUERY_KEY, $token);
    }

    /**
     * Resolves the site's legal imprint URL with a local fallback.
     */
    public static function imprintUrl(): string
    {
        try {
            if (class_exists('\RRZE\Legal\TOS\Endpoint')) {
                return \RRZE\Legal\TOS\Endpoint::endpointUrl('imprint');
            }
            $page = get_page_by_path(self::IMPRINT_PAGE_SLUG);
            return $page
                ? get_permalink($page->ID)
                : home_url('/' . self::IMPRINT_PAGE_SLUG . '/');
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Reads an array-valued option without coercing corrupt scalar data.
     *
     * @return array<mixed>
     */
    private static function getOptionArray(#[\SensitiveParameter] string $optionName): array
    {
        return BookingStorage::get($optionName);
    }

    /**
     * Determines whether a pending entry has reached its expiry timestamp.
     *
     * @param array<string, mixed> $entry Stored token entry.
     */
    private static function isExpired(#[\SensitiveParameter] array $entry, ?int $now = null): bool
    {
        $expires = (int) ($entry['expires'] ?? 0);
        return $expires <= 0 || ($now ?? time()) >= $expires;
    }

    /**
     * Validates the minimum shape required for a pending booking.
     *
     * @param mixed $entry Stored option value.
     */
    private static function isValidPendingEntry($entry, ?int $now = null): bool
    {
        return is_array($entry)
            && self::normalizeString($entry['slot'] ?? '') !== ''
            && is_array($entry['meta'] ?? null)
            && !self::isExpired($entry, $now);
    }

    /**
     * Generates a non-predictable token scoped by WordPress salts.
     */
    private static function generateToken(#[\SensitiveParameter] string $context, #[\SensitiveParameter] string $scheme = 'auth'): string
    {
        $entropy = implode('|', [
            $context,
            wp_salt($scheme),
            (string) microtime(true),
            wp_generate_uuid4(),
        ]);

        return wp_hash($entropy, $scheme);
    }

    /**
     * Builds a public action URL containing an opaque token.
     */
    private static function actionUrl(#[\SensitiveParameter] string $queryKey, #[\SensitiveParameter] string $token): string
    {
        return add_query_arg($queryKey, $token, home_url('/'));
    }

    /**
     * Preserves domain exceptions and wraps unexpected integration failures.
     */
    private static function wrapException(\Throwable $exception): AppointmentException
    {
        return AppointmentException::fromThrowable($exception);
    }

    /**
     * Normalizes scalar stored values without accepting nested data.
     *
     * @param mixed $value Stored value.
     */
    private static function normalizeString($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
