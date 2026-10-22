<?php

namespace RRZE\Appointment\Admin;

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Storage\BookingStorage;

defined('ABSPATH') || exit;

/**
 * Reports site-local encryption status without requiring customer migration actions.
 */
final class StorageStatus
{
    /**
     * Retains a safe startup error even if migration could not save its first marker.
     *
     * @param string|null $initializationError Operator-facing error from this request.
     */
    public function __construct(private readonly ?string $initializationError = null)
    {
    }

    /**
     * Registers diagnostics for the automatically managed storage migration.
     */
    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /**
     * Displays status on settings, and storage failures throughout administration.
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $phase = 'error';
        $message = __('Appointment storage is unavailable. Check the encryption key, database connection and migration state. Booking operations have been stopped to protect existing data.', 'rrze-appointment');
        try {
            if ($this->initializationError !== null) {
                throw new AppointmentException($this->initializationError);
            }
            $status = BookingStorage::status();
            $phase = $status['phase'];
            $message = match ($phase) {
                'legacy' => __('Booking data is not encrypted. Configuring RRZE_APPOINTMENT_ENCRYPTION_KEY on the server starts migration automatically on the next request.', 'rrze-appointment'),
                'ready' => __('The encryption key is configured. Migration starts automatically when the plugin initializes or booking data are accessed.', 'rrze-appointment'),
                'migrating' => sprintf(__('Encryption migration is incomplete (%1$d of %2$d stores). It resumes automatically on the next request. Storage errors must be corrected before booking operations can continue.', 'rrze-appointment'), $status['completed'], $status['total']),
                'encrypted' => __('Booking and notification stores are encrypted. Scheduled jobs use opaque references.', 'rrze-appointment'),
            };
        } catch (AppointmentException $exception) {
            // Storage exceptions contain operator-safe descriptions, never payloads.
            $message .= ' ' . $exception->getMessage();
        }
        if ($phase !== 'error' && Request::queryText('page', true) !== SettingsPage::PAGE_SLUG) {
            return;
        }
        $class = $phase === 'encrypted' ? 'notice-success' : ($phase === 'error' ? 'notice-error' : 'notice-warning');
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($message) . '</p>';
        echo '</div>';
    }
}
