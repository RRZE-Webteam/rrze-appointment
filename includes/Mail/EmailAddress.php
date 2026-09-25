<?php

namespace RRZE\Appointment\Mail;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/** Validates a recipient and normalizes its domain, preserving the local part. */
final class EmailAddress
{
    private const MAX_LENGTH = 254;
    private const MAX_LOCAL_LENGTH = 64;
    private const MAX_DOMAIN_LABEL_LENGTH = 63;

    /**
     * Only surrounding ASCII spaces are removed. In particular, control
     * characters must be rejected rather than silently stripped by trim().
     * Domains use lowercase ASCII/Punycode; local-part case and plus tags stay intact.
     *
     * @throws AppointmentException If the address is missing or invalid.
     */
    public static function validate(string $value): string
    {
        $email = trim($value, ' ');
        if ($email === '') {
            throw new AppointmentException(__('Please provide an email address.', 'rrze-appointment'));
        }

        if (
            preg_match('/[\x00-\x1F\x7F]/', $email)
            || substr_count($email, '@') !== 1
        ) {
            throw new AppointmentException(__('Enter a valid email address.', 'rrze-appointment'));
        }

        [$local, $domain] = explode('@', $email, 2);
        $domain = self::normalizeDomain($domain);
        $email = $local . '@' . $domain;

        // Check wire-format lengths after IDNA conversion, not UTF-8 input lengths.
        // WordPress does not check these length and local-part dot rules.
        if (
            strlen($email) > self::MAX_LENGTH
            || !is_email($email)
            || strlen($local) > self::MAX_LOCAL_LENGTH
            || str_starts_with($local, '.')
            || str_ends_with($local, '.')
            || str_contains($local, '..')
            || array_filter(explode('.', $domain), static fn(string $label): bool => strlen($label) > self::MAX_DOMAIN_LABEL_LENGTH)
        ) {
            throw new AppointmentException(__('Enter a valid email address.', 'rrze-appointment'));
        }

        return $email;
    }

    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower($domain);
        $isUnicode = (bool) preg_match('/[^\x00-\x7F]/', $domain);

        // Do not let IDNA silently discard whitespace or invisible control characters.
        if ($isUnicode && (preg_match('//u', $domain) !== 1 || preg_match('/[\p{C}\p{Z}]/u', $domain))) {
            throw new AppointmentException(__('Enter a valid email address.', 'rrze-appointment'));
        }

        // Ordinary ASCII domains only need lowercasing. Check domains containing
        // existing Punycode (ACE) labels through IDNA too when available.
        if (!$isUnicode && !preg_match('/(?:^|\.)xn--/', $domain)) {
            return $domain;
        }

        if (!function_exists('idn_to_ascii')) {
            if (!$isUnicode) {
                return $domain;
            }
            throw new AppointmentException(__('Please enter the email domain in Punycode format; this server cannot convert internationalized domains.', 'rrze-appointment'));
        }

        // Nontransitional conversion keeps distinct domains such as faß.de and fass.de.
        $ascii = idn_to_ascii(
            $domain,
            IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ,
            INTL_IDNA_VARIANT_UTS46,
            $info
        );
        if ($ascii === false || !empty($info['errors'])) {
            throw new AppointmentException(__('Enter a valid email address.', 'rrze-appointment'));
        }

        return $ascii;
    }
}
