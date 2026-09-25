<?php

namespace RRZE\Appointment\Mail;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/** Validates a recipient without rewriting its mailbox or domain. */
final class EmailAddress
{
    private const MAX_LENGTH = 254;
    private const MAX_LOCAL_LENGTH = 64;
    private const MAX_DOMAIN_LABEL_LENGTH = 63;

    /**
     * Only surrounding ASCII spaces are removed. In particular, control
     * characters must be rejected rather than silently stripped by trim().
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
            strlen($email) > self::MAX_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $email)
            || !is_email($email)
        ) {
            throw new AppointmentException(__('Enter a valid email address.', 'rrze-appointment'));
        }

        [$local, $domain] = explode('@', $email, 2);
        // WordPress does not check these length and local-part dot rules.
        if (
            strlen($local) > self::MAX_LOCAL_LENGTH
            || str_starts_with($local, '.')
            || str_ends_with($local, '.')
            || str_contains($local, '..')
            || array_filter(explode('.', $domain), static fn(string $label): bool => strlen($label) > self::MAX_DOMAIN_LABEL_LENGTH)
        ) {
            throw new AppointmentException(__('Enter a valid email address.', 'rrze-appointment'));
        }

        return $email;
    }
}
