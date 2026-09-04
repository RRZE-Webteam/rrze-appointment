<?php

namespace RRZE\Appointment\Configuration;

defined('ABSPATH') || exit;

/**
 * Provides validated access to persisted plugin configuration.
 */
final class PluginSettings
{
    public const OPTION_NAME = 'rrze_appointment_settings';
    public const MAX_REMINDER_DAYS = 7;
    public const MAX_RETENTION_DAYS = 3650;
    public const ILLUSTRATION_DEFAULTS = [
        'confirmation_success' => 'order-confirmed-62.png',
        'confirmation_questions' => 'financial-analyst-31.png',
        'cancellation_confirmation' => 'neutral-face-89.png',
        'cancellation_success' => 'neutral-face-89.png',
        'waitlist_preference' => 'reminder-note-28.png',
        'opening_notification' => 'notification-36.png',
        'error' => 'bug-fixing-71.png',
    ];

    /**
     * @return array{
     *     reminder_days: int,
     *     recurrence_limit: int,
     *     retention_days: int,
     *     cancellation_reason_enabled: bool,
     *     sensitive_mode_enabled: bool,
     *     appointment_manager_user_ids: array<int, int>,
     *     illustrations: array<string, int>
     * }
     */
    public static function defaults(): array
    {
        return [
            'reminder_days' => 0,
            'recurrence_limit' => 52,
            'retention_days' => 30,
            'cancellation_reason_enabled' => true,
            'sensitive_mode_enabled' => false,
            'appointment_manager_user_ids' => [],
            'illustrations' => [],
        ];
    }

    public static function get(string $key): mixed
    {
        $storedOptions = get_option(self::OPTION_NAME, []);
        $options = is_array($storedOptions) ? $storedOptions : [];
        $defaults = self::defaults();

        return isset($options[$key]) && $options[$key] !== ''
            ? $options[$key]
            : ($defaults[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     reminder_days: int,
     *     recurrence_limit: int,
     *     retention_days: int,
     *     cancellation_reason_enabled: bool,
     *     sensitive_mode_enabled: bool,
     *     appointment_manager_user_ids: array<int, int>,
     *     illustrations: array<string, int>
     * }
     */
    public static function sanitize(array $input): array
    {
        $storedOptions = get_option(self::OPTION_NAME, []);
        $currentOptions = is_array($storedOptions) ? $storedOptions : [];
        $recurrenceLimit = max(1, (int) ($currentOptions['recurrence_limit'] ?? 52));
        $scope = is_string($input['_settings_scope'] ?? null)
            ? $input['_settings_scope']
            : '';

        $reminderDays = $scope === 'illustrations'
            ? self::get('reminder_days')
            : ($input['reminder_days'] ?? 0);
        $retentionDays = $scope === 'illustrations'
            ? self::get('retention_days')
            : ($input['retention_days'] ?? 30);
        $cancellationReasonEnabled = $scope === 'illustrations'
            ? self::get('cancellation_reason_enabled')
            : !empty($input['cancellation_reason_enabled']);
        $sensitiveModeEnabled = $scope === 'illustrations'
            ? self::get('sensitive_mode_enabled')
            : !empty($input['sensitive_mode_enabled']);
        $appointmentManagerUserIds = $scope === 'illustrations'
            ? self::get('appointment_manager_user_ids')
            : ($input['appointment_manager_user_ids'] ?? []);
        $illustrations = $scope === 'general'
            ? self::get('illustrations')
            : ($input['illustrations'] ?? []);

        return [
            'reminder_days' => min(
                self::MAX_REMINDER_DAYS,
                max(0, (int) $reminderDays)
            ),
            'recurrence_limit' => $recurrenceLimit,
            'retention_days' => min(
                self::MAX_RETENTION_DAYS,
                max(0, (int) $retentionDays)
            ),
            'cancellation_reason_enabled' => (bool) $cancellationReasonEnabled,
            'sensitive_mode_enabled' => (bool) $sensitiveModeEnabled,
            'appointment_manager_user_ids' => self::sanitizeAppointmentManagerUserIds(
                $appointmentManagerUserIds
            ),
            'illustrations' => self::sanitizeIllustrations($illustrations),
        ];
    }

    /**
     * Keeps eligible, non-administrator users belonging to the current site.
     *
     * @param mixed $input Submitted user IDs.
     * @return array<int, int>
     */
    private static function sanitizeAppointmentManagerUserIds($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $userIds = [];
        foreach ($input as $value) {
            $userId = absint($value);
            $user = $userId > 0 ? get_userdata($userId) : false;
            if (
                !$user
                || !is_user_member_of_blog($userId, get_current_blog_id())
                || user_can($user, 'manage_options')
            ) {
                continue;
            }

            $userIds[$userId] = $userId;
        }

        ksort($userIds, SORT_NUMERIC);
        return array_values($userIds);
    }

    /**
     * Keeps only image attachment IDs belonging to supported public screens.
     *
     * @param mixed $input Submitted illustration mapping.
     * @return array<string, int>
     */
    private static function sanitizeIllustrations($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $illustrations = [];
        foreach (array_keys(self::ILLUSTRATION_DEFAULTS) as $key) {
            $attachmentId = absint($input[$key] ?? 0);
            if ($attachmentId > 0 && wp_attachment_is_image($attachmentId)) {
                $illustrations[$key] = $attachmentId;
            }
        }

        return $illustrations;
    }
}
