<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class TokenManager
{
    const PENDING_OPTION = 'rrze_appointment_pending_slots';
    const CANCEL_OPTION  = 'rrze_appointment_cancel_tokens';
    const PENDING_TTL    = 1800; // 30 Minuten

    // --- Pending (Bestätigung ausstehend) ---

    public static function createPending(string $slot, array $meta): string
    {
        $token   = wp_generate_uuid4();
        $pending = (array) get_option(self::PENDING_OPTION, []);
        $pending[$token] = [
            'slot'    => $slot,
            'meta'    => $meta,
            'expires' => time() + self::PENDING_TTL,
        ];
        update_option(self::PENDING_OPTION, $pending, false);

        // Cron zum Aufräumen
        wp_schedule_single_event(time() + self::PENDING_TTL + 60, 'rrze_appointment_expire_pending', [$token]);

        return $token;
    }

    public static function confirmPending(string $token): ?array
    {
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
    }

    public static function expirePending(string $token): void
    {
        $pending = (array) get_option(self::PENDING_OPTION, []);
        if (!isset($pending[$token])) return;

        // Slot nur freigeben wenn noch nicht bestätigt
        unset($pending[$token]);
        update_option(self::PENDING_OPTION, $pending, false);
    }

    public static function getPendingSlots(): array
    {
        $pending = (array) get_option(self::PENDING_OPTION, []);
        return array_column(array_filter($pending, fn($e) => time() <= $e['expires']), 'slot');
    }

    // --- Cancel-Tokens ---

    public static function createCancelToken(string $slot): string
    {
        $token  = wp_hash($slot . wp_salt() . time());
        $tokens = (array) get_option(self::CANCEL_OPTION, []);
        $tokens[$token] = $slot;
        update_option(self::CANCEL_OPTION, $tokens, false);
        return $token;
    }

    public static function validateCancelToken(string $token): ?string
    {
        $tokens = (array) get_option(self::CANCEL_OPTION, []);
        return $tokens[$token] ?? null;
    }

    public static function deleteCancelToken(string $token): void
    {
        $tokens = (array) get_option(self::CANCEL_OPTION, []);
        unset($tokens[$token]);
        update_option(self::CANCEL_OPTION, $tokens, false);
    }

    // --- URLs ---

    public static function confirmUrl(string $token): string
    {
        return add_query_arg('rrze_appt_confirm', $token, home_url('/'));
    }

    public static function cancelUrl(string $token): string
    {
        return add_query_arg('rrze_appt_cancel', $token, home_url('/'));
    }

    // --- Impressum-URL via RRZE-Legal ---

    public static function imprintUrl(): string
    {
        if (class_exists('\RRZE\Legal\TOS\Endpoint')) {
            return \RRZE\Legal\TOS\Endpoint::endpointUrl('imprint');
        }
        // Fallback: Seite mit Slug 'impressum'
        $page = get_page_by_path('impressum');
        return $page ? get_permalink($page->ID) : home_url('/impressum/');
    }
}
