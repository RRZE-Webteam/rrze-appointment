<?php

namespace RRZE\Appointment\Presentation;

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Configuration\PluginSettings;

defined('ABSPATH') || exit;

/**
 * Provides runtime configuration to the public and block-editor scripts.
 */
final class AssetManager
{
    private const FRONTEND_SCRIPT_HANDLE = 'rrze-appointment-view-script';
    private const EDITOR_SCRIPT_HANDLE = 'rrze-appointment-editor-script';
    private const CONFIGURATION_OBJECT = 'rrze_appointment';
    private const PERSONS_REST_PATH = '/rrze/v2/appointment/persons';
    private const DEFAULT_RECURRENCE_LIMIT = 52;

    /**
     * Localizes frontend booking state and translated interface labels.
     */
    public function enqueueFrontendAssets(): void
    {
        if (!wp_script_is(self::FRONTEND_SCRIPT_HANDLE, 'registered')) {
            return;
        }

        try {
            $configuration = $this->getFrontendConfiguration();
        } catch (AppointmentException $exception) {
            return;
        }

        wp_localize_script(
            self::FRONTEND_SCRIPT_HANDLE,
            self::CONFIGURATION_OBJECT,
            $configuration
        );
    }

    /**
     * Adds configuration consumed by the appointment block editor.
     */
    public function enqueueEditorAssets(): void
    {
        if (!wp_script_is(self::EDITOR_SCRIPT_HANDLE, 'registered')) {
            return;
        }

        try {
            $configuration = $this->getEditorConfiguration();
        } catch (AppointmentException $exception) {
            $configuration = $this->getDefaultEditorConfiguration();
        }

        wp_add_inline_script(
            self::EDITOR_SCRIPT_HANDLE,
            'window.' . self::CONFIGURATION_OBJECT . ' = ' . wp_json_encode($configuration) . ';',
            'before'
        );
    }

    /**
     * Builds configuration consumed by the public booking interface.
     *
     * @return array<string, mixed>
     * @throws AppointmentException If pending-slot state cannot be loaded.
     */
    private function getFrontendConfiguration(): array
    {
        $bookedSlots = (array) get_option(Bookings::SLOTS_OPTION, []);
        $pendingSlots = TokenManager::getPendingSlots();

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('rrze/v2/appointment/booker'),
            'nonce' => wp_create_nonce('rrze_appointment_book'),
            'locale' => str_replace('_', '-', determine_locale()),
            'bookedSlots' => array_values(array_unique(array_merge($bookedSlots, $pendingSlots))),
            'i18n' => $this->getFrontendTranslations(),
        ];
    }

    /**
     * Builds configuration consumed by the appointment block editor.
     *
     * @return array<string, mixed>
     */
    private function getEditorConfiguration(): array
    {
        return [
            'faudir' => [
                'available' => $this->isFaudirAvailable(),
                'personsPath' => self::PERSONS_REST_PATH,
            ],
            'recurrenceLimit' => (int) PluginSettings::get('recurrence_limit'),
            'editorI18n' => $this->getEditorTranslations(),
        ];
    }

    /**
     * Returns safe editor defaults when optional configuration is unavailable.
     *
     * @return array<string, mixed>
     */
    private function getDefaultEditorConfiguration(): array
    {
        return [
            'faudir' => [
                'available' => false,
                'personsPath' => self::PERSONS_REST_PATH,
            ],
            'recurrenceLimit' => self::DEFAULT_RECURRENCE_LIMIT,
            'editorI18n' => $this->getEditorTranslations(),
        ];
    }

    /**
     * Determines whether the optional FAUdir integration can be used.
     */
    private function isFaudirAvailable(): bool
    {
        return post_type_exists('custom_person')
            && class_exists('\RRZE\FAUdir\API')
            && class_exists('\RRZE\FAUdir\Config');
    }

    /**
     * Returns translations used by the public booking interface.
     *
     * @return array<string, string>
     */
    private function getFrontendTranslations(): array
    {
        return [
            'chooseDate' => __('Choose an appointment date', 'rrze-appointment'),
            'previousMonth' => __('Previous month', 'rrze-appointment'),
            'nextMonth' => __('Next month', 'rrze-appointment'),
            'available' => __('available appointments', 'rrze-appointment'),
            'unavailable' => __('no available appointments', 'rrze-appointment'),
            'notOpen' => __('appointments not yet bookable', 'rrze-appointment'),
            'bookingAdvanceDay' => __('These appointments can only be booked %d day in advance.', 'rrze-appointment'),
            'bookingAdvanceDays' => __('These appointments can only be booked %d days in advance.', 'rrze-appointment'),
            'notifyDialogTitle' => __('Notify me when booking opens', 'rrze-appointment'),
            'notifyDialogIntro' => __('Enter your details and we will email you as soon as this appointment opens for booking.', 'rrze-appointment'),
            'notifyButton' => __('Notify me', 'rrze-appointment'),
            'notifySending' => __('Saving notification…', 'rrze-appointment'),
            'notifySuccessTitle' => __('Notification registered', 'rrze-appointment'),
            'notifySuccess' => __('We will email you when this appointment opens for booking.', 'rrze-appointment'),
            'selected' => __('selected', 'rrze-appointment'),
            'today' => __('today', 'rrze-appointment'),
            'noSlotsAvailable' => __('No time slots available.', 'rrze-appointment'),
            'bookingDetailsLoading' => __('Loading booking details…', 'rrze-appointment'),
            'required' => __('required', 'rrze-appointment'),
            'dialogTitle' => __('Request appointment', 'rrze-appointment'),
            'dialogIntro' => __('Enter your details to request this appointment. You will receive an email to confirm it.', 'rrze-appointment'),
            'selectedAppointment' => __('Selected appointment', 'rrze-appointment'),
            'closeDialog' => __('Close dialog', 'rrze-appointment'),
            'successTitle' => __('Check your inbox', 'rrze-appointment'),
            'waitlist' => __('Notify me if an earlier appointment becomes available.', 'rrze-appointment'),
            'yourAppointment' => __('Your appointment on %s at %s', 'rrze-appointment'),
            'yourEmail' => __('Email address', 'rrze-appointment'),
            'yourName' => __('Name', 'rrze-appointment'),
            'namePlaceholder' => __('First and last name', 'rrze-appointment'),
            'nameRequired' => __('Enter your name.', 'rrze-appointment'),
            'emailRequired' => __('Enter a valid email address.', 'rrze-appointment'),
            'book' => __('Request appointment', 'rrze-appointment'),
            'cancel' => __('Cancel', 'rrze-appointment'),
            'booking' => __('Sending request…', 'rrze-appointment'),
            'booked' => __('Check your inbox to confirm the appointment. We sent a confirmation link to your email address.', 'rrze-appointment'),
            'close' => __('Close', 'rrze-appointment'),
            'bookingError' => __("We couldn't request this appointment. Please try again.", 'rrze-appointment'),
            'networkError' => __('Connection problem. Check your internet connection and try again.', 'rrze-appointment'),
            'availableOn' => __('Available appointments on %s', 'rrze-appointment'),
            'appointmentsOn' => __('Appointments on %s', 'rrze-appointment'),
            'slotsOnDay' => __('Times on selected day', 'rrze-appointment'),
        ];
    }

    /**
     * Returns translations used by editor-only controls.
     *
     * @return array<string, string>
     */
    private function getEditorTranslations(): array
    {
        return [
            'hideWeekendsField' => __('Hide weekends', 'rrze-appointment'),
            'hideWeekendsHelp' => __('Only show Monday through Friday in the calendar.', 'rrze-appointment'),
        ];
    }
}
