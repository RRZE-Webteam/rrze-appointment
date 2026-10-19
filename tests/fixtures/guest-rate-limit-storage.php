<?php

namespace RRZE\Appointment\Booking {
    function time(): int { return $GLOBALS['testNow'] ?? 1000; }
}

namespace {
    // Isolated SQL storage: execute conditional writes against SQLite, adapting
    // only MySQL's INSERT IGNORE / LIKE escaping and SUBSTRING_INDEX syntax.
    final class RateLimitTestDatabase {
        public string $options = 'wp_options';
        public string $last_error = '';
        public ?\Closure $beforeWrite = null;
        public bool $failReads = false;
        public bool $failWrites = false;
        public \SQLite3 $db;

        public function __construct() {
            $this->db = new \SQLite3(':memory:');
            $this->db->enableExceptions(true);
            $this->db->createFunction('SUBSTRING_INDEX', static fn($value, $delimiter, $count) => explode($delimiter, $value)[0], 3);
            foreach (['wp_options', 'wp_2_options'] as $table) {
                $this->db->exec("CREATE TABLE $table (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)");
            }
        }
        public function prepare(string $query, ...$args): string {
            $index = 0;
            return preg_replace_callback('/%[isd]/', static function ($match) use ($args, &$index) {
                $value = $args[$index++];
                return match ($match[0]) {
                    '%i' => '"' . str_replace('"', '""', $value) . '"',
                    '%d' => (string) (int) $value,
                    default => "'" . \SQLite3::escapeString($value) . "'",
                };
            }, $query);
        }
        public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
        public function get_var(string $query): ?string {
            $this->last_error = $this->failReads ? 'Storage unavailable' : '';
            return $this->failReads ? null : $this->db->querySingle($query);
        }
        public function query(string $query): int|false {
            if ($this->failWrites) {
                return false;
            }
            if ($this->beforeWrite) {
                $callback = $this->beforeWrite;
                $this->beforeWrite = null;
                $callback($this, $query);
            }
            $query = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query);
            $query = preg_replace('/\s+AND CAST/', " ESCAPE '\\' AND CAST", $query);
            $this->db->exec($query);
            return $this->db->changes();
        }
        public function rows(): array {
            $rows = [];
            $result = $this->db->query('SELECT * FROM ' . $this->options . ' ORDER BY option_name');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
            return $rows;
        }
    }
    function apply_filters($hook, $value) { return $GLOBALS['rateLimits'] ?? $value; }
    function wp_salt($scheme) { return 'isolated-test-salt'; }
    function get_current_blog_id() { return $GLOBALS['testBlog'] ?? 1; }
}
