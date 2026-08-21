<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

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

    private const TOKEN_TYPE_BOOKED = 'booked';
    private const TOKEN_TYPE_PENDING = 'pending';
    private const CONFIRM_QUERY_KEY = 'rrze_appt_confirm';
    private const CANCEL_QUERY_KEY = 'rrze_appt_cancel';
    private const WAITLIST_OPT_OUT_QUERY_KEY = 'rrze_appt_waitlist_optout';
    private const IMPRINT_PAGE_SLUG = 'impressum';
    private const EXPIRY_SCHEDULE_DELAY = 60;

    /**
     * Removes expired pending requests and normalizes legacy cancellation
     * tokens so booking metadata only exists in the pending option.
     */
    public static function cleanupPendingState(): void
    {
        try {
            $now            = time();
            $pending        = self::getOptionArray(self::PENDING_OPTION);
            $tokens         = self::getOptionArray(self::CANCEL_OPTION);
            $pendingChanged = false;
            $tokensChanged  = false;
            $pendingBySlot  = [];

            foreach ($pending as $pendingToken => $entry) {
                if (!self::isValidPendingEntry($entry, $now)) {
                    unset($pending[$pendingToken]);
                    wp_clear_scheduled_hook(self::PENDING_EXPIRY_HOOK, [$pendingToken]);
                    $pendingChanged = true;
                    continue;
                }

                $pendingBySlot[self::normalizeString($entry['slot'])] = (string) $pendingToken;
            }

            foreach ($tokens as $cancelToken => $entry) {
                if (!is_array($entry) || ($entry['type'] ?? '') !== self::TOKEN_TYPE_PENDING) {
                    continue;
                }

                $pendingToken = self::normalizeString($entry['pending_token'] ?? '');
                if ($pendingToken === '') {
                    // Legacy entries stored slot and full metadata. Link them
                    // to the remaining pending request and discard the copy.
                    $legacySlot = self::normalizeString($entry['slot'] ?? '');
                    $pendingToken = $pendingBySlot[$legacySlot] ?? '';
                }

                if ($pendingToken === '' || !isset($pending[$pendingToken])) {
                    unset($tokens[$cancelToken]);
                    $tokensChanged = true;
                    continue;
                }

                $normalized = [
                    'type'          => self::TOKEN_TYPE_PENDING,
                    'pending_token' => $pendingToken,
                    'expires'       => (int) $pending[$pendingToken]['expires'],
                ];
                if ($entry !== $normalized) {
                    $tokens[$cancelToken] = $normalized;
                    $tokensChanged = true;
                }

                if (($pending[$pendingToken]['cancel_token'] ?? '') !== $cancelToken) {
                    $pending[$pendingToken]['cancel_token'] = $cancelToken;
                    $pendingChanged = true;
                }
            }

            foreach ($pending as $pendingToken => $entry) {
                $cancelToken = self::normalizeString($entry['cancel_token'] ?? '');
                if ($cancelToken !== '' && !isset($tokens[$cancelToken])) {
                    unset($pending[$pendingToken]['cancel_token']);
                    $pendingChanged = true;
                }
            }

            if ($pendingChanged) {
                update_option(self::PENDING_OPTION, $pending, false);
            }
            if ($tokensChanged) {
                update_option(self::CANCEL_OPTION, $tokens, false);
            }
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Stores a short-lived booking request and schedules its cleanup.
     *
     * @param array<string, mixed> $meta Pending booking metadata.
     */
    public static function createPending(string $slot, array $meta): string
    {
        try {
            self::cleanupPendingState();

            $token   = wp_generate_uuid4();
            $expires = time() + self::PENDING_TTL;
            $pending = self::getOptionArray(self::PENDING_OPTION);
            $pending[$token] = [
                'slot'    => $slot,
                'meta'    => $meta,
                'expires' => $expires,
            ];
            update_option(self::PENDING_OPTION, $pending, false);
            wp_schedule_single_event(
                $expires + self::EXPIRY_SCHEDULE_DELAY,
                self::PENDING_EXPIRY_HOOK,
                [$token]
            );
            return $token;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Reads and consumes a valid pending booking.
     *
     * @return array<string, mixed>|null
     */
    public static function confirmPending(string $token): ?array
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
     * Reads a valid pending booking without consuming its confirmation token.
     *
     * @return array<string, mixed>|null
     */
    public static function getPending(string $token): ?array
    {
        try {
            if ($token === '') {
                return null;
            }

            $pending = self::getOptionArray(self::PENDING_OPTION);
            $entry = $pending[$token] ?? null;
            if (!self::isValidPendingEntry($entry)) {
                self::deletePending($token);
                return null;
            }

            return $entry;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Removes a pending booking when its scheduled expiry hook runs.
     */
    public static function expirePending(string $token): void
    {
        try {
            self::deletePending($token);
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Removes a pending booking and every linked cancellation token.
     *
     * @return array<string, mixed>|null Removed pending entry.
     */
    public static function deletePending(string $token): ?array
    {
        try {
            if ($token === '') {
                return null;
            }

            $pending = self::getOptionArray(self::PENDING_OPTION);
            $entry   = $pending[$token] ?? null;
            $slot = is_array($entry) ? self::normalizeString($entry['slot'] ?? '') : '';

            if (isset($pending[$token])) {
                unset($pending[$token]);
                update_option(self::PENDING_OPTION, $pending, false);
            }
            wp_clear_scheduled_hook(self::PENDING_EXPIRY_HOOK, [$token]);

            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            $changed = false;
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
                    $changed = true;
                }
            }
            if ($changed) {
                update_option(self::CANCEL_OPTION, $tokens, false);
            }

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
            self::cleanupPendingState();
            $pending = self::getOptionArray(self::PENDING_OPTION);
            return array_values(array_filter(array_map(
                static fn($entry): string => is_array($entry)
                    ? self::normalizeString($entry['slot'] ?? '')
                    : '',
                $pending
            )));
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Creates and stores a cancellation token for a confirmed booking.
     */
    public static function createCancelToken(string $slot): string
    {
        try {
            $token = self::generateToken($slot);
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            $tokens[$token] = ['slot' => $slot, 'type' => self::TOKEN_TYPE_BOOKED];
            update_option(self::CANCEL_OPTION, $tokens, false);

            $allMeta = self::getOptionArray(Bookings::META_OPTION);
            if (isset($allMeta[$slot]) && is_array($allMeta[$slot])) {
                $allMeta[$slot]['cancel_token'] = $token;
                update_option(Bookings::META_OPTION, $allMeta, false);
            }

            return $token;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Creates a cancellation token linked to a pending booking.
     */
    public static function createPendingCancelToken(string $pendingToken): string
    {
        try {
            self::cleanupPendingState();

            $pending = self::getOptionArray(self::PENDING_OPTION);
            $entry   = $pending[$pendingToken] ?? null;
            if (!self::isValidPendingEntry($entry)) {
                self::deletePending($pendingToken);
                throw new \RuntimeException('Pending booking not found.');
            }

            $token = self::generateToken($pendingToken);
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            $tokens[$token] = [
                'type'          => self::TOKEN_TYPE_PENDING,
                'pending_token' => $pendingToken,
                'expires'       => (int) $entry['expires'],
            ];
            update_option(self::CANCEL_OPTION, $tokens, false);

            $pending[$pendingToken]['cancel_token'] = $token;
            update_option(self::PENDING_OPTION, $pending, false);

            return $token;
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Returns a stable cancellation URL for a confirmed booking slot.
     */
    public static function getCancelUrlForSlot(string $slot): string
    {
        try {
            $allMeta = self::getOptionArray(Bookings::META_OPTION);
            $slotMeta = $allMeta[$slot] ?? null;
            $token = is_array($slotMeta)
                ? self::normalizeString($slotMeta['cancel_token'] ?? '')
                : '';
            if ($token === '') {
                $token = self::createCancelToken($slot);
            }
            return self::cancelUrl($token);
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Returns a stable waitlist opt-out URL for a confirmed booking slot.
     */
    public static function getWaitlistOptOutUrlForSlot(string $slot): string
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
                update_option(Bookings::META_OPTION, $allMeta, false);
            }

            return self::waitlistOptOutUrl($token);
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Resolves a waitlist opt-out token to its confirmed booking slot.
     */
    public static function validateWaitlistOptOutToken(string $token): ?string
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
    public static function validateCancelToken(string $token): ?array
    {
        try {
            self::cleanupPendingState();

            if ($token === '') {
                return null;
            }

            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            $entry  = $tokens[$token] ?? null;
            if (is_string($entry)) {
                return $entry === '' ? null : ['slot' => $entry, 'type' => self::TOKEN_TYPE_BOOKED];
            }
            if (!is_array($entry)) {
                return null;
            }
            if (($entry['type'] ?? '') !== self::TOKEN_TYPE_PENDING) {
                $slot = self::normalizeString($entry['slot'] ?? '');
                return $slot === '' ? null : ['slot' => $slot, 'type' => self::TOKEN_TYPE_BOOKED];
            }

            $pendingToken = self::normalizeString($entry['pending_token'] ?? '');
            $pending = self::getOptionArray(self::PENDING_OPTION);
            $pendingEntry = $pending[$pendingToken] ?? null;
            $now = time();
            if (
                !self::isValidPendingEntry($pendingEntry, $now)
                || self::isExpired($entry, $now)
            ) {
                self::deletePending($pendingToken);
                self::deleteCancelToken($token);
                return null;
            }

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
     * Removes a cancellation token after it has been consumed.
     */
    public static function deleteCancelToken(string $token): void
    {
        try {
            $tokens = self::getOptionArray(self::CANCEL_OPTION);
            unset($tokens[$token]);
            update_option(self::CANCEL_OPTION, $tokens, false);
        } catch (\Throwable $exception) {
            throw self::wrapException($exception);
        }
    }

    /**
     * Builds a public booking-confirmation URL.
     */
    public static function confirmUrl(string $token): string
    {
        return self::actionUrl(self::CONFIRM_QUERY_KEY, $token);
    }

    /**
     * Builds a public booking-cancellation URL.
     */
    public static function cancelUrl(string $token): string
    {
        return self::actionUrl(self::CANCEL_QUERY_KEY, $token);
    }

    /**
     * Builds a public waitlist-preference URL.
     */
    public static function waitlistOptOutUrl(string $token): string
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
    private static function getOptionArray(string $optionName): array
    {
        $value = get_option($optionName, []);
        return is_array($value) ? $value : [];
    }

    /**
     * Determines whether a pending entry has reached its expiry timestamp.
     *
     * @param array<string, mixed> $entry Stored token entry.
     */
    private static function isExpired(array $entry, ?int $now = null): bool
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
    private static function generateToken(string $context, string $scheme = 'auth'): string
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
    private static function actionUrl(string $queryKey, string $token): string
    {
        return add_query_arg($queryKey, $token, home_url('/'));
    }

    /**
     * Preserves domain exceptions and wraps unexpected integration failures.
     */
    private static function wrapException(\Throwable $exception): CustomException
    {
        if ($exception instanceof CustomException) {
            return $exception;
        }

        return new CustomException($exception->getMessage(), (int) $exception->getCode(), null);
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
