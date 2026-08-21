<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Provides access to legacy plugin defaults and namespaced option keys.
 */
final class Defaults
{
    private const PREFIX_LENGTH = 6;
    private const SLUG_PART_LENGTH = 3;

    /**
     * @var array<string, mixed>
     */
    private readonly array $defaults;

    /**
     * Initializes the legacy defaults collection.
     */
    public function __construct()
    {
        $this->defaults = [];
    }

    /**
     * Returns a default value, or null when the key is not configured.
     *
     * @return mixed|null
     */
    public function get(string $key): mixed
    {
        return $this->defaults[$key] ?? null;
    }

    /**
     * Returns every configured default value.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->defaults;
    }

    /**
     * Prepends a deterministic six-character plugin prefix to a key.
     *
     * The prefix is derived from the plugin slug and remains stable between
     * requests. The supplied key is normalized with WordPress' key sanitizer.
     */
    public function withPrefix(string $key = ''): string
    {
        return $this->buildPrefix(plugin()->getSlug()) . '_' . sanitize_key($key);
    }

    /**
     * Builds the fixed-length prefix used by {@see self::withPrefix()}.
     */
    private function buildPrefix(string $slug): string
    {
        $cleanSlug = preg_replace('/[^a-z0-9]/', '', strtolower($slug)) ?: '';
        $slugPart = substr($cleanSlug, 0, self::SLUG_PART_LENGTH);
        $hashLength = self::PREFIX_LENGTH - strlen($slugPart);
        $hashPart = substr(md5($cleanSlug), 0, $hashLength);
        $prefix = $slugPart . $hashPart;

        if (preg_match('/^[a-z]/', $prefix) !== 1) {
            return 'p' . substr($prefix, 0, self::PREFIX_LENGTH - 1);
        }

        return $prefix;
    }
}
