<?php

namespace RRZE\Appointment\Storage {
    /** Supplies a controllable worker clock without sleeping in network tests. */
    function time(): int { return $GLOBALS['clock']; }
    /** Models elapsed site processing time for the soft budget regression test. */
    function microtime(bool $asFloat = false): float { return $GLOBALS['elapsed'] += $GLOBALS['tick']; }
}

namespace {
    use RRZE\Appointment\Storage\BookingCipher;
    use RRZE\Appointment\Storage\BookingStorage;
    use RRZE\Appointment\Storage\EncryptionKey;
    use RRZE\Appointment\Storage\NetworkMigration;
    use RRZE\Appointment\Storage\OptionDatabase;

    define('RRZE_APPOINTMENT_STORAGE_TEST_BOOTSTRAP_ONLY', true);
    require __DIR__ . '/booking-storage.php';
    $scenario = $argv[1] ?? 'scale';
    if ($scenario !== 'no-key') {
        define('RRZE_APPOINTMENT_ENCRYPTION_KEY', $scenario === 'invalid-key' ? 'invalid' : base64_encode(str_repeat('test-key', 8)));
    }
    $wpdb = new BookingTestDatabase();
    $wpdb->db->exec('CREATE TABLE wp_blogs (blog_id INTEGER PRIMARY KEY, site_id INTEGER)');
    $GLOBALS['networkOptions'] = [1 => []];
    $GLOBALS['clock'] = 1900000000;
    $GLOBALS['elapsed'] = 0.0;
    $GLOBALS['tick'] = $scenario === 'budget' ? 11.0 : 0.0;
    $GLOBALS['site'] = 1;
    $GLOBALS['stack'] = [];
    $GLOBALS['active'] = [1 => true];
    $GLOBALS['actions'] = [];
    $GLOBALS['evicted'] = [];
    $GLOBALS['canManage'] = true;
    $GLOBALS['dropStateWrite'] = false;
    $GLOBALS['crashAfterClaim'] = false;
    $plugin = 'rrze-appointment/rrze-appointment.php';
    if (!in_array($scenario, ['individual', 'reelect'], true)) {
        $GLOBALS['networkOptions'][1]['active_sitewide_plugins'] = [$plugin => 1];
    }

    /** Identifies the isolated network without bootstrapping WordPress. */
    function is_multisite(): bool { return true; }
    /** Returns the fixture's network ID. */
    function get_current_network_id(): int { return 1; }
    /** Identifies the synthetic network main site. */
    function get_main_site_id(int $network): int { return 1; }
    /** Returns the currently selected fixture site. */
    function get_current_blog_id(): int { return $GLOBALS['site']; }
    /** Returns a plugin path as WordPress would. */
    function plugin_basename(string $path): string { return 'rrze-appointment/rrze-appointment.php'; }
    /** Reads synthetic coordinator metadata, independent of booking options. */
    function get_network_option($network, $name, $default = false): mixed
    {
        // A fresh WordPress network-option SQL read clears the previous query error.
        $GLOBALS['wpdb']->last_error = '';
        return $GLOBALS['networkOptions'][$network][$name] ?? $default;
    }
    /** Models persistent progress, including a crash immediately after an in-flight checkpoint. */
    function update_network_option($network, $name, $value): bool
    {
        if ($GLOBALS['dropStateWrite']) {
            return false;
        }
        $GLOBALS['networkOptions'][$network][$name] = $value;
        if ($GLOBALS['crashAfterClaim'] && ($value['in_flight'] ?? 0) === 2) {
            $GLOBALS['crashAfterClaim'] = false;
            throw new \RuntimeException('Synthetic crash after persisted checkpoint');
        }
        return true;
    }
    /** Resolves site existence and network membership from the real SQLite registry. */
    function get_site(int $id): ?object
    {
        global $wpdb;
        $network = $wpdb->db->querySingle('SELECT site_id FROM wp_blogs WHERE blog_id = ' . $id);
        return $network === null ? null : (object) ['network_id' => $network, 'deleted' => 0, 'archived' => 0, 'spam' => 0];
    }
    /** Models per-site plugin activation without loading a different plugin bootstrap. */
    function get_blog_option($id, $name, $default = false): mixed
    {
        return $name === 'active_plugins' ? (!empty($GLOBALS['active'][$id]) ? ['rrze-appointment/rrze-appointment.php'] : []) : $default;
    }
    /** Changes the active options table and records balanced switches. */
    function switch_to_blog(int $id): void
    {
        global $wpdb;
        $GLOBALS['stack'][] = $GLOBALS['site'];
        $GLOBALS['site'] = $id;
        $wpdb->options = $id === 1 ? 'wp_options' : 'wp_' . $id . '_options';
    }
    /** Restores the previous options table after a migration work unit. */
    function restore_current_blog(): void
    {
        global $wpdb;
        $GLOBALS['site'] = array_pop($GLOBALS['stack']);
        $wpdb->options = $GLOBALS['site'] === 1 ? 'wp_options' : 'wp_' . $GLOBALS['site'] . '_options';
    }
    /** Stores a synthetic recurring event through the existing cron fixture. */
    function wp_schedule_event($timestamp, $interval, $hook): bool { return wp_schedule_single_event($timestamp, $hook); }
    /** Records hook registration for bootstrap assertions. */
    function add_action($hook, $callback, $priority = 10): void { $GLOBALS['actions'][] = $hook; }
    /** Records filter registration using the same callback registry. */
    function add_filter($hook, $callback): void { add_action($hook, $callback); }
    /** Preserves source messages in isolated rendering assertions. */
    function __($text, $domain): string { return $text; }
    /** Models network administrator permissions. */
    function current_user_can($capability): bool { return $GLOBALS['canManage']; }
    /** Escapes notice text for HTML rendering tests. */
    function esc_html($text): string { return htmlspecialchars($text, ENT_QUOTES); }
    /** Escapes notice attributes for HTML rendering tests. */
    function esc_attr($text): string { return esc_html($text); }

    /** Creates a site with synthetic payloads only; no wp-config or real data is read. */
    function seedSite(int $id, int $network = 1, bool $withData = true): void
    {
        global $wpdb;
        $wpdb->db->exec("INSERT INTO wp_blogs VALUES ($id, $network)");
        switch_to_blog($id);
        $wpdb->db->exec("CREATE TABLE IF NOT EXISTS $wpdb->options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)");
        if ($withData) {
            foreach (BookingStorage::OPTIONS as $option) {
                $wpdb->seed($option, serialize(['site' => $id, 'booker_name' => 'Synthetic Person']));
            }
        }
        restore_current_blog();
    }
    /** Advances a simulated minute and executes one ordinary cron callback. */
    function tick(): void
    {
        $GLOBALS['clock'] += 60;
        NetworkMigration::run();
    }
    /** Returns only operational network progress for test assertions. */
    function progress(): array { return get_network_option(1, NetworkMigration::OPTION, []); }
    /** Captures the network notice without exposing booking payloads. */
    function notice(): string { ob_start(); NetworkMigration::renderStatus(); return ob_get_clean(); }

    $count = $scenario === 'scale' ? 1005 : 15;
    for ($id = 1; $id <= $count; $id++) {
        seedSite($id, 1, $scenario !== 'individual' || $id !== 3);
    }
    seedSite(2000, 2);
    NetworkMigration::register();
    if ($scenario === 'subsite-start') {
        switch_to_blog(2);
    }
    NetworkMigration::schedule();
    $result = [
        'registered' => in_array(NetworkMigration::HOOK, $GLOBALS['actions'], true),
        'scheduled' => wp_next_scheduled(NetworkMigration::HOOK) !== false,
        'noEagerMigration' => $wpdb->raw(BookingStorage::STATE_OPTION) === null,
    ];
    if ($scenario === 'subsite-start') {
        $result['owner'] = progress()['owner'];
        $result['noSubsiteEvent'] = wp_next_scheduled(NetworkMigration::HOOK) === false;
        $result['stillOnSubsite'] = get_current_blog_id() === 2;
        restore_current_blog();
        $result['mainScheduled'] = wp_next_scheduled(NetworkMigration::HOOK) !== false;
    } elseif ($scenario === 'no-key') {
        $result['noState'] = progress() === [];
    } elseif ($scenario === 'scale') {
        switch_to_blog(3);
        $wpdb->seed(BookingStorage::OPTIONS[0], 'corrupt-synthetic-data');
        restore_current_blog();
        $batches = 0;
        $maxDelta = 0;
        $released = true;
        $restored = true;
        do {
            $before = progress()['scanned'] ?? 0;
            tick();
            $maxDelta = max($maxDelta, progress()['scanned'] - $before);
            $released = $released && $wpdb->activeLocks === []
                && (new \ReflectionProperty(BookingStorage::class, 'sites'))->getValue() === []
                && (new \ReflectionProperty(OptionDatabase::class, 'active'))->getValue() === [];
            $restored = $restored && get_current_blog_id() === 1 && $GLOBALS['stack'] === [];
        } while (!(progress()['finished_at'] ?? 0) && ++$batches < 200);
        $result['firstPass'] = progress();
        $result['maxSitesPerBatch'] = $maxDelta;
        $result['peakLocks'] = $wpdb->peakLocks;
        $result['released'] = $released;
        $result['restored'] = $restored;
        $result['batches'] = $batches + 1;
        $contexts = [];
        $intact = true;
        $ciphertexts = [];
        for ($id = 1; $id <= $count; $id++) {
            if ($id === 3) {
                continue;
            }
            switch_to_blog($id);
            $state = json_decode($wpdb->raw(BookingStorage::STATE_OPTION), true);
            $contexts[] = $state['context'];
            $cipher = new BookingCipher(EncryptionKey::fromConfiguration(), $state['context']);
            $ciphertexts[$id] = $wpdb->raw(BookingStorage::OPTIONS[0]);
            foreach (BookingStorage::OPTIONS as $option) {
                $intact = $intact && $cipher->decrypt($wpdb->raw($option), $option)['site'] === $id;
            }
            restore_current_blog();
        }
        $result['payloadsIntact'] = $intact;
        $result['uniqueContexts'] = count(array_unique($contexts));
        switch_to_blog(3);
        $wpdb->seed(BookingStorage::OPTIONS[0], serialize(['site' => 3, 'booker_name' => 'Synthetic Person']));
        restore_current_blog();
        seedSite(1010);
        $GLOBALS['clock'] += 3600;
        tick();
        for ($attempt = 0; !(progress()['finished_at'] ?? 0) && $attempt < 200; $attempt++) {
            tick();
        }
        $result['retryPass'] = progress();
        $unchanged = true;
        foreach ($ciphertexts as $id => $raw) {
            switch_to_blog($id);
            $unchanged = $unchanged && $wpdb->raw(BookingStorage::OPTIONS[0]) === $raw;
            restore_current_blog();
        }
        $result['noRepeatedEncryption'] = $unchanged;
        switch_to_blog(2000);
        $result['otherNetworkUntouched'] = $wpdb->raw(BookingStorage::STATE_OPTION) === null;
        restore_current_blog();
    } elseif ($scenario === 'contention') {
        $wpdb->denyLock = true;
        $before = progress();
        tick();
        $result['unchanged'] = progress() === $before;
    } elseif ($scenario === 'state-failure') {
        $GLOBALS['dropStateWrite'] = true;
        tick();
        $result['noBookingWrite'] = $wpdb->raw(BookingStorage::STATE_OPTION) === null;
    } elseif ($scenario === 'site-query-failure') {
        $wpdb->failSiteQuery = true;
        tick();
        $result['afterFailure'] = progress();
        $wpdb->failSiteQuery = false;
        tick();
        $result['afterRecovery'] = progress();
    } elseif ($scenario === 'crash') {
        $GLOBALS['crashAfterClaim'] = true;
        tick();
        $result['claimed'] = progress()['in_flight'];
        tick();
        tick();
        $result['afterCrash'] = progress();
        $GLOBALS['clock'] += 3600;
        tick();
        tick();
        $result['recovered'] = progress();
    } elseif ($scenario === 'reelect') {
        unset($GLOBALS['active'][1]);
        $GLOBALS['active'][2] = true;
        switch_to_blog(2);
        $GLOBALS['clock'] += 301;
        NetworkMigration::schedule();
        $result['owner'] = progress()['owner'];
        $result['newScheduled'] = wp_next_scheduled(NetworkMigration::HOOK) !== false;
        restore_current_blog();
        $result['oldRemoved'] = wp_next_scheduled(NetworkMigration::HOOK) === false;
        $before = progress();
        tick();
        $result['oldDoesNoWork'] = progress() === $before;
    } elseif ($scenario === 'stalled') {
        tick();
        $GLOBALS['clock'] += 1000;
        NetworkMigration::schedule();
        $result['stalledVisible'] = str_contains(notice(), 'needs attention');
    } else {
        tick();
        $result['afterBatch'] = progress();
        $before = progress();
        NetworkMigration::run();
        $result['duplicateDidNoWork'] = progress() === $before;
    }
    $result['notice'] = notice();
    $GLOBALS['canManage'] = false;
    $result['noticeRestricted'] = notice() === '';
    $result['locksReleased'] = $wpdb->activeLocks === [];
    $result['siteRestored'] = get_current_blog_id() === 1 && $GLOBALS['stack'] === [];
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
