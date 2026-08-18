<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

/**
 * Provides runtime data to the frontend and block-editor scripts.
 */
final class AssetManager
{
    /**
     * Localizes frontend booking state and translated interface labels.
     */
    public function enqueueFrontendAssets(): void
    {
        try {
            $viewHandle = 'rrze-appointment-view-script';
            if (!wp_script_is($viewHandle, 'registered')) {
                return;
            }

            $booked = (array) get_option('rrze_appointment_booked_slots', []);
            $pending = TokenManager::getPendingSlots();
            wp_localize_script($viewHandle, 'rrze_appointment', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restUrl' => rest_url('rrze/v2/appointment/booker'),
                'nonce' => wp_create_nonce('rrze_appointment_book'),
                'locale' => str_replace('_', '-', determine_locale()),
                'bookedSlots' => array_values(array_unique(array_merge($booked, $pending))),
                'i18n' => $this->getFrontendTranslations(),
            ]);
        } catch (CustomException $exception) {
            return;
        }
    }

    /**
     * Adds configuration consumed by the appointment block editor.
     */
    public function enqueueEditorAssets(): void
    {
        try {
            $data = [
                'faudir' => [
                    'available' => post_type_exists('custom_person')
                        && class_exists('\RRZE\FAUdir\API')
                        && class_exists('\RRZE\FAUdir\Config'),
                    'personsPath' => '/rrze/v2/appointment/persons',
                ],
                'recurrenceLimit' => (int) Settings::get('recurrence_limit'),
                'editorI18n' => $this->getEditorTranslations(),
            ];
        } catch (CustomException $exception) {
            $data = [
                'faudir' => [
                    'available' => false,
                    'personsPath' => '/rrze/v2/appointment/persons',
                ],
                'recurrenceLimit' => 52,
                'editorI18n' => $this->getEditorTranslations(),
            ];
        }

        wp_add_inline_script(
            'rrze-appointment-editor-script',
            'window.rrze_appointment = ' . wp_json_encode($data) . ';',
            'before'
        );
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
            'message' => __('Message', 'rrze-appointment'),
            'messageOptional' => __('Message (optional)', 'rrze-appointment'),
            'messagePlaceholder' => __('What would you like to discuss?', 'rrze-appointment'),
            'nameRequired' => __('Enter your name.', 'rrze-appointment'),
            'emailRequired' => __('Enter a valid email address.', 'rrze-appointment'),
            'messageRequired' => __('Enter a message.', 'rrze-appointment'),
            'book' => __('Request appointment', 'rrze-appointment'),
            'cancel' => __('Cancel', 'rrze-appointment'),
            'booking' => __('Sending request…', 'rrze-appointment'),
            'booked' => __('Check your inbox to confirm the appointment. We sent a confirmation link to your email address.', 'rrze-appointment'),
            'close' => __('Close', 'rrze-appointment'),
            'bookingError' => __("We couldn't request this appointment. Please try again.", 'rrze-appointment'),
            'networkError' => __('Connection problem. Check your internet connection and try again.', 'rrze-appointment'),
            'availableOn' => __('Available appointments on %s', 'rrze-appointment'),
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
            'requireMessageField' => __('Require a message', 'rrze-appointment'),
            'requireMessageHelp' => __('People must enter a message when requesting an appointment.', 'rrze-appointment'),
            'hideWeekendsField' => __('Hide weekends', 'rrze-appointment'),
            'hideWeekendsHelp' => __('Only show Monday through Friday in the calendar.', 'rrze-appointment'),
        ];
    }
}
