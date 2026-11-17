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
    /** Retains a safe template setup error so administrative recovery remains available. */
    public function __construct(private readonly ?string $templateError = null)
    {
    }

    /**
     * Registers diagnostics for the current site's write policy.
     */
    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /**
     * Displays technical status only to operators on Appointment screens.
     */
    public function render(): void
    {
        if (!DiagnosticNotice::allowed()) {
            return;
        }
        if ($this->templateError !== null) {
            echo '<div class="notice notice-error"><p>' . esc_html($this->templateError) . '</p></div>';
        }
        $phase = 'error';
        $message = __('Appointment storage or scheduled jobs need attention. Check the encryption key, database connection and migration state.', 'rrze-appointment');
        try {
            $status = BookingStorage::status();
            $phase = $status['phase'];
            $message = match ($phase) {
                'legacy' => __('Booking data is stored in the legacy format. Configure RRZE_APPOINTMENT_ENCRYPTION_KEY to encrypt new and changed data.', 'rrze-appointment'),
                'mixed' => __('Encryption is active for new and changed data.', 'rrze-appointment'),
                default => throw new AppointmentException('Unknown appointment storage state.'),
            };
        } catch (AppointmentException $exception) {
            // Storage exceptions contain operator-safe descriptions, never payloads.
            $message .= ' ' . $exception->getMessage();
        }
        $class = $phase === 'mixed' ? 'notice-success' : ($phase === 'legacy' ? 'notice-warning' : 'notice-error');
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($message) . '</p>';
        echo '</div>';
    }
}
