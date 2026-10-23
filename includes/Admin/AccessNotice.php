<?php

namespace RRZE\Appointment\Admin;

defined('ABSPATH') || exit;

/** Supplies consistent, data-free access notices to PHP pages and the React application. */
final class AccessNotice
{
    /** Returns translated presentation metadata without account or appointment information. */
    public static function messages(): array
    {
        return [
            'rrze_appointment_grants_forbidden' => [
                'title' => __('Access management is reserved for responsible people', 'rrze-appointment'),
                'description' => __('Only responsible people can manage permissions. Contact a responsible person or support if responsibility needs to be reassigned.', 'rrze-appointment'), 'action' => 'dashboard',
            ],
            'rrze_appointment_forbidden' => [
                'title' => __('No access to appointment management', 'rrze-appointment'),
                'description' => AppointmentPermissions::forbidden()->get_error_message(), 'action' => 'dashboard',
            ],
            'rrze_appointment_sso_required' => [
                'title' => __('Please sign in with SSO', 'rrze-appointment'),
                'description' => __('You have permission to manage appointments. Sign in with the SSO account belonging to your WordPress account to continue.', 'rrze-appointment'), 'action' => 'login',
            ],
            'rrze_appointment_sso_identity_mismatch' => [
                'title' => __('The signed-in accounts do not match', 'rrze-appointment'),
                'description' => __('Use the SSO account belonging to your current WordPress account, then reload this page.', 'rrze-appointment'), 'action' => 'reload',
            ],
            'rrze_appointment_sso_unavailable' => [
                'title' => __('SSO sign-in is currently unavailable', 'rrze-appointment'),
                'description' => __('Please try again. If the problem persists, contact support. Appointment access remains protected.', 'rrze-appointment'), 'action' => 'reload',
            ],
            'rrze_appointment_invalid_nonce' => [
                'title' => __('Please renew your WordPress session', 'rrze-appointment'),
                'description' => __('Reload this page and sign in to WordPress again if requested.', 'rrze-appointment'), 'action' => 'reload',
            ],
            'rrze_appointment_access_unavailable' => [
                'title' => __('Access settings are unavailable', 'rrze-appointment'),
                'description' => AccessPolicy::unavailable()->get_error_message(), 'action' => 'reload',
            ],
        ];
    }

    /** Renders a styled, accessible denial without mounting the booking application. */
    public static function render(\WP_Error $error, string $destination = 'bookings'): void
    {
        $message = self::messages()[$error->get_error_code()] ?? [
            'title' => __('The change could not be saved', 'rrze-appointment'),
            'description' => $error->get_error_message(), 'action' => 'reload',
        ];
        $url = match ($message['action']) {
            'login' => BackendSsoSettings::loginUrl($destination),
            'dashboard' => admin_url(),
            default => BackendSsoSettings::destination($destination),
        };
        $label = match ($message['action']) {
            'login' => __('Sign in with SSO', 'rrze-appointment'),
            'dashboard' => __('Back to dashboard', 'rrze-appointment'),
            default => __('Reload page', 'rrze-appointment'),
        };
        ?>
        <section class="rrze-appointment-access-card" role="status" aria-labelledby="rrze-access-title">
            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
            <h2 id="rrze-access-title"><?php echo esc_html($message['title']); ?></h2>
            <p><?php echo esc_html($message['description']); ?></p>
            <?php AccessSettings::renderOwners(); ?>
            <p><a class="button button-primary" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a></p>
        </section>
        <?php
    }
}
