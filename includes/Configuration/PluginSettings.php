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
     *     illustrations: array<string, int>
     * }
     */
    public static function sanitize(array $input): array
    {
        $storedOptions = get_option(self::OPTION_NAME, []);
        $currentOptions = is_array($storedOptions) ? $storedOptions : [];
        $recurrenceLimit = max(1, (int) ($currentOptions['recurrence_limit'] ?? 52));

        return [
            'reminder_days' => min(
                self::MAX_REMINDER_DAYS,
                max(0, (int) ($input['reminder_days'] ?? 0))
            ),
            'recurrence_limit' => $recurrenceLimit,
            'retention_days' => min(
                self::MAX_RETENTION_DAYS,
                max(0, (int) ($input['retention_days'] ?? 30))
            ),
            'cancellation_reason_enabled' => !empty($input['cancellation_reason_enabled']),
            'illustrations' => self::sanitizeIllustrations($input['illustrations'] ?? []),
        ];
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
