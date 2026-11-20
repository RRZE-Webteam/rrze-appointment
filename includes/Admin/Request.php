<?php

namespace RRZE\Appointment\Admin;

defined('ABSPATH') || exit;

/**
 * Reads sanitized scalar values from WordPress admin requests.
 */
final class Request
{
    /**
     * Reads a single-line POST field, returning an empty string for missing or non-string values.
     *
     * @param bool $sanitizeAsKey Apply WordPress key sanitization for identifiers such as actions.
     */
    public static function postText(string $key, bool $sanitizeAsKey = false): string
    {
        return self::text($_POST, $key, $sanitizeAsKey);
    }

    /** Reads a multiline POST field, preserving line breaks and returning an empty string for invalid input. */
    public static function postTextarea(string $key): string
    {
        $value = wp_unslash($_POST[$key] ?? '');

        return is_string($value) ? sanitize_textarea_field($value) : '';
    }

    /** Casts a scalar POST field to an integer, defaulting to zero for missing or non-scalar values. */
    public static function postInt(string $key): int
    {
        return self::integer($_POST, $key);
    }

    /**
     * Reads a single-line query parameter, returning an empty string for missing or non-string values.
     *
     * @param bool $sanitizeAsKey Apply WordPress key sanitization for identifiers such as tab names.
     */
    public static function queryText(string $key, bool $sanitizeAsKey = false): string
    {
        return self::text($_GET, $key, $sanitizeAsKey);
    }

    /** Casts a scalar query parameter to an integer, defaulting to zero for missing or non-scalar values. */
    public static function queryInt(string $key): int
    {
        return self::integer($_GET, $key);
    }

    /** Checks for a scalar query parameter, including an empty string or zero. */
    public static function hasQueryFlag(string $key): bool
    {
        return isset($_GET[$key]) && is_scalar($_GET[$key]);
    }

    /**
     * Unslashes a request field and sanitizes its string value as either text or a WordPress key.
     *
     * @param array<string, mixed> $source WordPress request fields.
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
     * Unslashes a scalar request field for integer conversion, using zero as the fallback.
     *
     * @param array<string, mixed> $source WordPress request fields.
     */
    private static function integer(array $source, string $key): int
    {
        $value = wp_unslash($source[$key] ?? 0);

        return is_scalar($value) ? (int) $value : 0;
    }
}
