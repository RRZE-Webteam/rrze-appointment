<?php

/** Models the SQL boundary while exercising the production lock and compare-and-swap implementation. */
class AccessTestDatabase
{
    public string $options = 'test_options';
    public string $last_error = '';
    public bool $locked = false;
    public bool $fail = false;

    /** Keeps SQL and parameters separate for assertions without executing SQL. */
    public function prepare(string $query, ...$args): array { return [$query, $args]; }

    /** Models advisory locking and uncached option reads. */
    public function get_var(array $query): ?string
    {
        [$sql, $args] = $query;
        if (str_contains($sql, 'GET_LOCK')) { $this->locked = !$this->fail; return $this->locked ? '1' : '0'; }
        if (str_contains($sql, 'IS_USED_LOCK')) { return $this->locked ? '1' : '0'; }
        if (str_contains($sql, 'RELEASE_LOCK')) { $this->locked = false; return '1'; }
        $value = get_option($args[1], null);
        return $value === null ? null : (is_array($value) ? serialize($value) : (string) $value);
    }

    /** Applies only matching option replacements and records non-autoloaded writes. */
    public function query(array $query): int|false
    {
        [$sql, $args] = $query;
        if ($this->fail) { $this->last_error = 'synthetic SQL failure'; return false; }
        $insert = str_starts_with($sql, 'INSERT');
        $name = $insert ? $args[1] : $args[3];
        $next = $insert ? $args[2] : $args[1];
        $old = $this->get_var(['SELECT option_value', [$this->options, $name]]);
        if (($insert && $old !== null) || (!$insert && hash('sha256', $old ?? '') !== $args[4])) { return 0; }
        update_option($name, unserialize($next, ['allowed_classes' => false]), false);
        return 1;
    }
}
