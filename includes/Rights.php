<?php

namespace RRZE\Appointment;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Reads the current passive SSO identity without triggering authentication.
 */
final class Rights
{
    private const ID_ATTRIBUTE_KEYS = ['uid', 'idm', 'eduPersonPrincipalName'];
    private const GIVEN_NAME_ATTRIBUTE_KEYS = ['givenName', 'gn'];
    private const FAMILY_NAME_ATTRIBUTE_KEYS = ['sn', 'surname'];
    private const DISPLAY_NAME_ATTRIBUTE_KEYS = ['displayName', 'cn'];
    private const EMAIL_ATTRIBUTE_KEYS = ['mail', 'email', 'mailPrimaryAddress'];

    /**
     * Returns the first non-empty string from the requested SSO attributes.
     *
     * @param array<string, mixed> $attributes SSO attribute collection.
     * @param array<int, string>   $keys       Attribute names in priority order.
     */
    private static function firstAttribute(array $attributes, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $values = is_array($attributes[$key]) ? $attributes[$key] : [$attributes[$key]];
            foreach ($values as $value) {
                $value = is_string($value) ? trim($value) : '';
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Returns the passive SSO identity used to prefill booking forms.
     *
     * The IdM identifier is used only as an authentication marker and is never
     * included in the returned data.
     *
     * @return array{authenticated: bool, bookerName: string, bookerEmail: string}
     */
    public static function get(): array
    {
        try {
            return self::getAuthenticatedBooker() ?? self::anonymousBooker();
        } catch (\Exception $exception) {
            throw new AppointmentException(
                $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * Reads and normalizes the existing SSO session.
     *
     * @return array{authenticated: true, bookerName: string, bookerEmail: string}|null
     */
    private static function getAuthenticatedBooker(): ?array
    {
        if (!class_exists('\RRZE\AccessControl\Permissions')) {
            return null;
        }

        try {
            $permissions = new \RRZE\AccessControl\Permissions();
            if (!$permissions->simplesamlAuth()) {
                return null;
            }

            $auth = $permissions->simplesamlAuth ?? null;
            if (
                !is_object($auth)
                || !method_exists($auth, 'isAuthenticated')
                || !method_exists($auth, 'getAttributes')
                || !$auth->isAuthenticated()
            ) {
                return null;
            }

            $attributes = (array) $auth->getAttributes();
            $identityMarker = sanitize_text_field(
                self::firstAttribute($attributes, self::ID_ATTRIBUTE_KEYS)
            );
            if ($identityMarker === '') {
                return null;
            }

            return [
                'authenticated' => true,
                'bookerName' => self::getBookerName($attributes),
                'bookerEmail' => self::getBookerEmail($attributes),
            ];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Builds a display name from structured or complete-name attributes.
     *
     * @param array<string, mixed> $attributes SSO attribute collection.
     */
    private static function getBookerName(array $attributes): string
    {
        $givenName = sanitize_text_field(
            self::firstAttribute($attributes, self::GIVEN_NAME_ATTRIBUTE_KEYS)
        );
        $familyName = sanitize_text_field(
            self::firstAttribute($attributes, self::FAMILY_NAME_ATTRIBUTE_KEYS)
        );

        if ($givenName === '') {
            $displayName = sanitize_text_field(
                self::firstAttribute($attributes, self::DISPLAY_NAME_ATTRIBUTE_KEYS)
            );
            if ($displayName !== '') {
                return $displayName;
            }
        }

        return trim($givenName . ' ' . $familyName);
    }

    /**
     * Returns the first valid email address from common SSO attributes.
     *
     * @param array<string, mixed> $attributes SSO attribute collection.
     */
    private static function getBookerEmail(array $attributes): string
    {
        foreach (self::EMAIL_ATTRIBUTE_KEYS as $key) {
            $values = is_array($attributes[$key] ?? null)
                ? $attributes[$key]
                : [$attributes[$key] ?? ''];

            foreach ($values as $value) {
                $email = sanitize_email(is_string($value) ? $value : '');
                if ($email !== '') {
                    return $email;
                }
            }
        }

        return '';
    }

    /**
     * Returns the public anonymous identity schema.
     *
     * @return array{authenticated: false, bookerName: string, bookerEmail: string}
     */
    private static function anonymousBooker(): array
    {
        return ['authenticated' => false, 'bookerName' => '', 'bookerEmail' => ''];
    }
}
