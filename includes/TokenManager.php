<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class TokenManager
{
    const PENDING_OPTION = 'rrze_appointment_pending_slots';
    const CANCEL_OPTION  = 'rrze_appointment_cancel_tokens';
    const PENDING_TTL    = 1800;
    const PENDING_EXPIRY_HOOK = 'rrze_appointment_expire_pending';

    /**
     * Removes expired pending requests and normalizes legacy cancellation
     * tokens so booking metadata only exists in the pending option.
     */
    public static function cleanupPendingState(): void
    {
        try {
            $now            = time();
            $pending        = (array) get_option(self::PENDING_OPTION, []);
            $tokens         = (array) get_option(self::CANCEL_OPTION, []);
            $pendingChanged = false;
            $tokensChanged  = false;
            $pendingBySlot  = [];

            foreach ($pending as $pendingToken => $entry) {
                if (
                    !is_array($entry)
                    || empty($entry['slot'])
                    || empty($entry['expires'])
                    || $now > (int) $entry['expires']
                ) {
                    unset($pending[$pendingToken]);
                    wp_clear_scheduled_hook(self::PENDING_EXPIRY_HOOK, [$pendingToken]);
                    $pendingChanged = true;
                    continue;
                }

                $pendingBySlot[(string) $entry['slot']] = (string) $pendingToken;
            }

            foreach ($tokens as $cancelToken => $entry) {
                if (!is_array($entry) || ($entry['type'] ?? '') !== 'pending') {
                    continue;
                }

                $pendingToken = (string) ($entry['pending_token'] ?? '');
                if ($pendingToken === '') {
                    // Legacy entries stored slot and full metadata. Link them
                    // to the remaining pending request and discard the copy.
                    $legacySlot   = (string) ($entry['slot'] ?? '');
                    $pendingToken = $pendingBySlot[$legacySlot] ?? '';
                }

                if ($pendingToken === '' || !isset($pending[$pendingToken])) {
                    unset($tokens[$cancelToken]);
                    $tokensChanged = true;
                    continue;
                }

                $normalized = [
                    'type'          => 'pending',
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
                $cancelToken = (string) ($entry['cancel_token'] ?? '');
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
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function createPending(string $slot, array $meta): string
    {
        try {
            self::cleanupPendingState();

            $token   = wp_generate_uuid4();
            $expires = time() + self::PENDING_TTL;
            $pending = (array) get_option(self::PENDING_OPTION, []);
            $pending[$token] = [
                'slot'    => $slot,
                'meta'    => $meta,
                'expires' => $expires,
            ];
            update_option(self::PENDING_OPTION, $pending, false);
            wp_schedule_single_event($expires + 60, self::PENDING_EXPIRY_HOOK, [$token]);
            return $token;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function confirmPending(string $token): ?array
    {
        try {
            $pending = (array) get_option(self::PENDING_OPTION, []);
            if (!isset($pending[$token])) return null;

            $entry = $pending[$token];
            if (!is_array($entry) || time() > (int) ($entry['expires'] ?? 0)) {
                self::deletePending($token);
                return null;
            }

            return self::deletePending($token);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function expirePending(string $token): void
    {
        try {
            self::deletePending($token);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function deletePending(string $token): ?array
    {
        try {
            if ($token === '') {
                return null;
            }

            $pending = (array) get_option(self::PENDING_OPTION, []);
            $entry   = $pending[$token] ?? null;
            $slot    = is_array($entry) ? (string) ($entry['slot'] ?? '') : '';

            if (isset($pending[$token])) {
                unset($pending[$token]);
                update_option(self::PENDING_OPTION, $pending, false);
            }
            wp_clear_scheduled_hook(self::PENDING_EXPIRY_HOOK, [$token]);

            $tokens = (array) get_option(self::CANCEL_OPTION, []);
            $changed = false;
            foreach ($tokens as $cancelToken => $cancelEntry) {
                if (!is_array($cancelEntry) || ($cancelEntry['type'] ?? '') !== 'pending') {
                    continue;
                }

                $linkedPendingToken = (string) ($cancelEntry['pending_token'] ?? '');
                $legacySlot         = (string) ($cancelEntry['slot'] ?? '');
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
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function getPendingSlots(): array
    {
        try {
            self::cleanupPendingState();
            $pending = (array) get_option(self::PENDING_OPTION, []);
            return array_values(array_filter(array_map(
                static fn($entry): string => is_array($entry) ? (string) ($entry['slot'] ?? '') : '',
                $pending
            )));
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function createCancelToken(string $slot): string
    {
        try {
            $token  = wp_hash($slot . wp_salt() . time());
            $tokens = (array) get_option(self::CANCEL_OPTION, []);
            $tokens[$token] = ['slot' => $slot, 'type' => 'booked'];
            update_option(self::CANCEL_OPTION, $tokens, false);

            $allMeta = (array) get_option('rrze_appointment_booked_slots_meta', []);
            if (isset($allMeta[$slot])) {
                $allMeta[$slot]['cancel_token'] = $token;
                update_option('rrze_appointment_booked_slots_meta', $allMeta, false);
            }

            return $token;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function createPendingCancelToken(string $pendingToken): string
    {
        try {
            self::cleanupPendingState();

            $pending = (array) get_option(self::PENDING_OPTION, []);
            $entry   = $pending[$pendingToken] ?? null;
            if (!is_array($entry) || time() > (int) ($entry['expires'] ?? 0)) {
                self::deletePending($pendingToken);
                throw new \RuntimeException('Pending booking not found.');
            }

            $token  = wp_hash($pendingToken . wp_salt() . microtime());
            $tokens = (array) get_option(self::CANCEL_OPTION, []);
            $tokens[$token] = [
                'type'          => 'pending',
                'pending_token' => $pendingToken,
                'expires'       => (int) $entry['expires'],
            ];
            update_option(self::CANCEL_OPTION, $tokens, false);

            $pending[$pendingToken]['cancel_token'] = $token;
            update_option(self::PENDING_OPTION, $pending, false);

            return $token;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function getCancelUrlForSlot(string $slot): string
    {
        try {
            $allMeta = (array) get_option('rrze_appointment_booked_slots_meta', []);
            $token   = $allMeta[$slot]['cancel_token'] ?? '';
            if (!$token) {
                $token = self::createCancelToken($slot);
            }
            return self::cancelUrl($token);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function validateCancelToken(string $token): ?array
    {
        try {
            self::cleanupPendingState();

            $tokens = (array) get_option(self::CANCEL_OPTION, []);
            $entry  = $tokens[$token] ?? null;
            if (!$entry) return null;
            if (is_string($entry)) {
                return ['slot' => $entry, 'type' => 'booked'];
            }
            if (!is_array($entry)) {
                return null;
            }
            if (($entry['type'] ?? '') !== 'pending') {
                return $entry;
            }

            $pendingToken = (string) ($entry['pending_token'] ?? '');
            $pending      = (array) get_option(self::PENDING_OPTION, []);
            $pendingEntry = $pending[$pendingToken] ?? null;
            if (
                !is_array($pendingEntry)
                || time() > (int) ($entry['expires'] ?? 0)
                || time() > (int) ($pendingEntry['expires'] ?? 0)
            ) {
                self::deletePending($pendingToken);
                self::deleteCancelToken($token);
                return null;
            }

            return [
                'type'          => 'pending',
                'pending_token' => $pendingToken,
                'slot'          => (string) ($pendingEntry['slot'] ?? ''),
                'expires'       => (int) $pendingEntry['expires'],
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function deleteCancelToken(string $token): void
    {
        try {
            $tokens = (array) get_option(self::CANCEL_OPTION, []);
            unset($tokens[$token]);
            update_option(self::CANCEL_OPTION, $tokens, false);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function confirmUrl(string $token): string
    {
        return add_query_arg('rrze_appt_confirm', $token, home_url('/'));
    }

    public static function cancelUrl(string $token): string
    {
        return add_query_arg('rrze_appt_cancel', $token, home_url('/'));
    }

    public static function imprintUrl(): string
    {
        try {
            if (class_exists('\RRZE\Legal\TOS\Endpoint')) {
                return \RRZE\Legal\TOS\Endpoint::endpointUrl('imprint');
            }
            $page = get_page_by_path('impressum');
            return $page ? get_permalink($page->ID) : home_url('/impressum/');
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
