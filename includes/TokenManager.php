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
        unset($pending[$token]);
        update_option(self::PENDING_OPTION, $pending, false);
    }

    public static function getPendingSlots(): array
    {
        $pending = (array) get_option(self::PENDING_OPTION, []);
        return array_column(array_filter($pending, fn($e) => time() <= $e['expires']), 'slot');
    }

    // --- Cancel-Tokens ---

    /**
     * Cancel-Token für eine bestätigte Buchung (löscht gebuchten Slot).
     */
    public static function createCancelToken(string $slot): string
    {
        $token  = wp_hash($slot . wp_salt() . time());
        $tokens = (array) get_option(self::CANCEL_OPTION, []);
        $tokens[$token] = ['slot' => $slot, 'type' => 'booked'];
        update_option(self::CANCEL_OPTION, $tokens, false);

        // Token im Slot-Meta speichern
        $allMeta = (array) get_option('rrze_appointment_booked_slots_meta', []);
        if (isset($allMeta[$slot])) {
            $allMeta[$slot]['cancel_token'] = $token;
            update_option('rrze_appointment_booked_slots_meta', $allMeta, false);
        }

        return $token;
    }

    /**
     * Cancel-Token für eine noch nicht bestätigte Anfrage (löscht Pending-Eintrag).
     */
    public static function createPendingCancelToken(string $slot, array $meta): string
    {
        $token  = wp_hash($slot . wp_salt() . microtime());
        $tokens = (array) get_option(self::CANCEL_OPTION, []);
        $tokens[$token] = ['slot' => $slot, 'type' => 'pending', 'meta' => $meta];
        update_option(self::CANCEL_OPTION, $tokens, false);
        return $token;
    }

    /**
     * Gibt die Storno-URL für einen bestätigten Slot zurück.
     * Erstellt einen neuen Token falls noch keiner existiert.
     */
    public static function getCancelUrlForSlot(string $slot): string
    {
        $allMeta = (array) get_option('rrze_appointment_booked_slots_meta', []);
        $token   = $allMeta[$slot]['cancel_token'] ?? '';
        if (!$token) {
            $token = self::createCancelToken($slot);
        }
        return self::cancelUrl($token);
    }

    /**
     * Gibt den Token-Eintrag zurück: ['slot' => ..., 'type' => 'booked'|'pending']
     */
    public static function validateCancelToken(string $token): ?array
    {
        $tokens = (array) get_option(self::CANCEL_OPTION, []);
        $entry  = $tokens[$token] ?? null;
        if (!$entry) return null;
        // Rückwärtskompatibilität: alter Eintrag war nur ein String
        if (is_string($entry)) {
            return ['slot' => $entry, 'type' => 'booked'];
        }
        return $entry;
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
        $page = get_page_by_path('impressum');
        return $page ? get_permalink($page->ID) : home_url('/impressum/');
    }
}
