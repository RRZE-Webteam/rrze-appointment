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
}
