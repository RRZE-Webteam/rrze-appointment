<?php

namespace RRZE\Appointment\Admin;

defined('ABSPATH') || exit;

/**
 * Reads sanitized scalar values from WordPress admin requests.
 */
final class Request
{
    public static function postText(string $key, bool $sanitizeAsKey = false): string
    {
        return self::text($_POST, $key, $sanitizeAsKey);
    }

    public static function postTextarea(string $key): string
    {
        $value = wp_unslash($_POST[$key] ?? '');

        return is_string($value) ? sanitize_textarea_field($value) : '';
    }

    public static function postInt(string $key): int
    {
        return self::integer($_POST, $key);
    }

    public static function queryText(string $key, bool $sanitizeAsKey = false): string
    {
        return self::text($_GET, $key, $sanitizeAsKey);
    }

    public static function queryInt(string $key): int
    {
        return self::integer($_GET, $key);
    }

    public static function hasQueryFlag(string $key): bool
    {
        return isset($_GET[$key]) && is_scalar($_GET[$key]);
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function text(array $source, string $key, bool $sanitizeAsKey): string
    {
        $value = wp_unslash($source[$key] ?? '');
        if (!is_string($value)) {
            return '';
        }

        return $sanitizeAsKey ? sanitize_key($value) : sanitize_text_field($value);
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function integer(array $source, string $key): int
    {
        $value = wp_unslash($source[$key] ?? 0);

        return is_scalar($value) ? (int) $value : 0;
    }
}
