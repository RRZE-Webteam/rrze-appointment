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

    /**
     * @return array{
     *     reminder_days: int,
     *     recurrence_limit: int,
     *     retention_days: int,
     *     cancellation_reason_enabled: bool
     * }
     */
    public static function defaults(): array
    {
        return [
            'reminder_days' => 0,
            'recurrence_limit' => 52,
            'retention_days' => 30,
            'cancellation_reason_enabled' => true,
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
     *     cancellation_reason_enabled: bool
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
        ];
    }
}
