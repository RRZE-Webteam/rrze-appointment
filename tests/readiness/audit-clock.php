<?php
/** Controlled calendar clock for accelerated notification tests, never production. */
namespace RRZE\Appointment\Notification;

if (!defined('RRZE_APPOINTMENT_AUDIT_ROOT') || !str_starts_with(DB_NAME, 'rrze_appt_audit_')) {
    exit('The notification clock is restricted to the isolated audit database.');
}

/**
 * Override only the notification domain's calendar dependency for midnight tests.
 *
 * @return \DateTimeImmutable Test calendar time or WordPress's actual local time.
 */
function current_datetime(): \DateTimeImmutable
{
    $file = RRZE_APPOINTMENT_AUDIT_ROOT . '/notification-clock';
    return is_file($file)
        ? new \DateTimeImmutable(trim(file_get_contents($file)), \wp_timezone())
        : \current_datetime();
}
