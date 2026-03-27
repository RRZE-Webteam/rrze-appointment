<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class TokenManager
{
    const PENDING_OPTION = 'rrze_appointment_pending_slots';
    const CANCEL_OPTION  = 'rrze_appointment_cancel_tokens';
    const PENDING_TTL    = 1800;

    public static function createPending(string $slot, array $meta): string
    {
        try {
            $token   = wp_generate_uuid4();
            $pending = (array) get_option(self::PENDING_OPTION, []);
            $pending[$token] = [
                'slot'    => $slot,
                'meta'    => $meta,
                'expires' => time() + self::PENDING_TTL,
            ];
            update_option(self::PENDING_OPTION, $pending, false);
            wp_schedule_single_event(time() + self::PENDING_TTL + 60, 'rrze_appointment_expire_pending', [$token]);
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
            if (time() > $entry['expires']) {
                unset($pending[$token]);
                update_option(self::PENDING_OPTION, $pending, false);
                return null;
            }

            unset($pending[$token]);
            update_option(self::PENDING_OPTION, $pending, false);
            return $entry;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function expirePending(string $token): void
    {
        try {
            $pending = (array) get_option(self::PENDING_OPTION, []);
            if (!isset($pending[$token])) return;
            unset($pending[$token]);
            update_option(self::PENDING_OPTION, $pending, false);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function getPendingSlots(): array
    {
        try {
            $pending = (array) get_option(self::PENDING_OPTION, []);
            return array_column(array_filter($pending, fn($e) => time() <= $e['expires']), 'slot');
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

    public static function createPendingCancelToken(string $slot, array $meta): string
    {
        try {
            $token  = wp_hash($slot . wp_salt() . microtime());
            $tokens = (array) get_option(self::CANCEL_OPTION, []);
            $tokens[$token] = ['slot' => $slot, 'type' => 'pending', 'meta' => $meta];
            update_option(self::CANCEL_OPTION, $tokens, false);
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
            $tokens = (array) get_option(self::CANCEL_OPTION, []);
            $entry  = $tokens[$token] ?? null;
            if (!$entry) return null;
            if (is_string($entry)) {
                return ['slot' => $entry, 'type' => 'booked'];
            }
            return $entry;
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
