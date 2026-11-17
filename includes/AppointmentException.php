<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Represents an appointment-domain failure.
 *
 * Logging belongs at the boundary where an exception is handled, keeping
 * construction free of application side effects.
 */
final class AppointmentException extends \Exception
{
    /**
     * Keeps deliberate domain messages, discarding unexpected dependency details.
     *
     * Never chain an integration exception: its message or trace may contain mail
     * bodies, addresses, tokens or database values. Callers must use fixed messages
     * when constructing this domain exception themselves.
     */
    public static function fromThrowable(#[\SensitiveParameter] \Throwable $exception): self
    {
        // Rebuild even deliberate failures: their original stack can contain
        // unannotated third-party arguments (for example a PHPMailer object).
        return new self(
            $exception instanceof self ? $exception->getMessage()
                : __('The appointment operation could not be completed. Please try again.', 'rrze-appointment'),
            $exception instanceof self ? (int) $exception->getCode() : 500
        );
    }
}
