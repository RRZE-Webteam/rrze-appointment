<?php

namespace RRZE\Appointment\Booking;

defined('ABSPATH') || exit;

/** Site-local quotas shared by guest bookings and opening notifications. */
final class GuestRequestLimiter
{
    private const OPTION_PREFIX = 'rrze_appt_guest_rate_';
    private const DEFAULT_LIMITS = ['email' => 5, 'ip' => 300, 'window' => 900];
    private const STORAGE_RETRY_SECONDS = 60;

    /**
     * Consumes one request, or returns the number of seconds to wait.
     * Call after validating the booking and normalizing the email, before side effects.
     */
    public static function consume(string $email): int
    {
        $configured = apply_filters('rrze_appointment_guest_request_limits', self::DEFAULT_LIMITS);
        $limits = self::DEFAULT_LIMITS;
        foreach ($limits as $name => $default) {
            $value = is_array($configured) ? ($configured[$name] ?? null) : null;
            if (is_int($value) && ($value > 0 || ($name === 'ip' && $value === 0))) {
                $limits[$name] = $value;
            }
        }

        // Check the source first so changing recipients cannot create unlimited rows.
        if ($limits['ip'] > 0) {
            $retryAfter = self::consumeBucket('ip', self::clientNetwork(), $limits['ip'], $limits['window']);
            if ($retryAfter > 0) {
                return $retryAfter;
            }
        }

        // Fold case for abuse counting only; never rewrite the delivery address or
        // strip provider-specific plus tags/dots from the local part.
        return self::consumeBucket('email', strtolower($email), $limits['email'], $limits['window']);
    }

    /** Removes expired counters during WordPress's daily transient cleanup. */
    public static function cleanup(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE option_name LIKE %s
             AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
            $wpdb->options,
            $wpdb->esc_like(self::OPTION_PREFIX) . '%',
            time()
        ));
    }

    /**
     * Uses non-autoloaded database rows, independent of evictable object caches.
     * Conditional writes prevent lost increments under concurrent submissions.
     */
    private static function consumeBucket(string $kind, string $identity, int $limit, int $window): int
    {
        global $wpdb;

        $key = self::OPTION_PREFIX . hash_hmac(
            'sha256',
            get_current_blog_id() . '|' . $kind . '|' . $identity,
            wp_salt('nonce')
        );

        // A competing writer may win between SELECT and UPDATE/INSERT. Retry with
        // its latest count, and refuse the request if storage remains contended.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $now = time();
            $previous = $wpdb->get_var($wpdb->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s',
                $wpdb->options,
                $key
            ));
            if ($wpdb->last_error !== '') {
                return self::STORAGE_RETRY_SECONDS;
            }

            $expires = $now + $window;
            $count = 0;
            if ($previous !== null) {
                [$storedExpiry, $storedCount] = array_pad(explode(':', $previous, 2), 2, '');
                if (!ctype_digit($storedExpiry) || !ctype_digit($storedCount)) {
                    return self::STORAGE_RETRY_SECONDS;
                }
                if ((int) $storedExpiry > $now) {
                    $expires = (int) $storedExpiry;
                    $count = (int) $storedCount;
                }
            }
            if ($count >= $limit) {
                return $expires - $now;
            }

            $next = $expires . ':' . ($count + 1);
            if ($previous === null) {
                $written = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $wpdb->options,
                    $key,
                    $next
                ));
            } else {
                $written = $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
                    $wpdb->options,
                    $next,
                    $key,
                    $previous
                ));
            }
            if ($written === 1) {
                return 0;
            }
            if ($written === false) {
                return self::STORAGE_RETRY_SECONDS;
            }
        }

        return self::STORAGE_RETRY_SECONDS;
    }

    /** Uses the web server's peer address; never trusts client-supplied proxy headers. */
    private static function clientNetwork(): string
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? '';
        $packed = is_string($address) && filter_var($address, FILTER_VALIDATE_IP)
            ? inet_pton($address)
            : false;
        if ($packed === false) {
            return 'unknown';
        }
        // Treat IPv4-mapped IPv6 as IPv4; group native IPv6 addresses by /64.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        return strlen($packed) === 16
            ? 'v6:' . bin2hex(substr($packed, 0, 8))
            : 'v4:' . bin2hex($packed);
    }
}
