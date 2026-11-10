<?php
/**
 * Real WordPress/InnoDB job acceptance checks in the disposable /nat/ subsite.
 * The separate foreign writer loads WordPress with normal plugins skipped.
 */

use RRZE\Appointment\Notification\BookingOpeningNotifier;
use RRZE\Appointment\Storage\BookingCipher;
use RRZE\Appointment\Storage\BookingScheduler;
use RRZE\Appointment\Storage\BookingStorage;
use RRZE\Appointment\Storage\EncryptionKey;

if (!defined('WP_CLI') || !WP_CLI || !defined('RRZE_APPOINTMENT_AUDIT_ROOT')
    || !str_starts_with(DB_NAME, 'rrze_appt_audit_')
    || wp_parse_url(home_url('/'), PHP_URL_HOST) !== '127.0.0.1'
    || wp_parse_url(home_url('/'), PHP_URL_PATH) !== '/nat/') {
    exit('This scenario requires the synthetic loopback /nat/ subsite.');
}

/** Completes a real stale cron update in a process where the Appointment plugin has never been loaded. */
function auditJobsForeignWriter(): void
{
    if (class_exists(BookingStorage::class, false)) {
        exit(91);
    }
    $snapshot = _get_cron_array();
    fwrite(STDOUT, "snapshot-ready\n");
    fflush(STDOUT);
    stream_set_timeout(STDIN, 30);
    if (trim((string) fgets(STDIN)) !== 'write') {
        exit(92);
    }
    // A legitimate foreign change ensures update_option actually writes the old snapshot.
    $arguments = ['synthetic-job-check'];
    $snapshot[time() + 8000]['audit_late_foreign_job'][md5(serialize($arguments))] = ['schedule' => false, 'args' => $arguments];
    uksort($snapshot, 'strnatcasecmp');
    exit(_set_cron_array($snapshot, true) === true ? 0 : 93);
}

/** Starts a second WP-CLI process in exactly the same synthetic subsite. */
function auditJobsWorker(string $mode, array &$pipes)
{
    $manifest = json_decode(file_get_contents(RRZE_APPOINTMENT_AUDIT_ROOT . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $command = [PHP_BINARY, $manifest['wp'], '--path=' . ABSPATH, '--url=' . home_url('/'), '--skip-themes'];
    if ($mode === 'foreign') {
        $command[] = '--skip-plugins';
    }
    // eval-file may execute PHP via eval(); use the manifest's repository path instead of __FILE__.
    return proc_open([...$command, 'eval-file', $manifest['repo'] . '/tests/readiness/mixed-jobs.php', $mode],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
}

/** Ends the subprocess and checks its status without printing WordPress output containing private diagnostics. */
function auditJobsFinish($worker, array $pipes): bool
{
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($worker) === 0;
}

/** Discards the parent process's option caches after a separate PHP process has written cron. */
function auditJobsForgetCron(): void
{
    wp_cache_delete('cron', 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}

/** Captures every unrelated event to compare content and timestamps without reporting any arguments. */
function auditJobsForeignInventory(): array
{
    $events = [];
    foreach (_get_cron_array() as $time => $hooks) {
        foreach ($hooks as $hook => $entries) {
            if (!str_starts_with($hook, 'rrze_appointment_')) {
                $events[$time][$hook] = $entries;
            }
        }
    }
    return $events;
}

/** Counts only messages addressed to this scenario's unique synthetic mailbox in the local mail sink. */
function auditJobsMailCount(string $address): int
{
    $count = 0;
    foreach (glob(RRZE_APPOINTMENT_AUDIT_ROOT . '/mail/*.eml') ?: [] as $file) {
        $mime = file_get_contents($file);
        if (preg_match('/^To:[^\r\n]*' . preg_quote($address, '/') . '/mi', $mime)) {
            $count++;
        }
    }
    return $count;
}

/** Adds an isolated historical opening to the real source option, preserving its V0 or V1 representation. */
function auditJobsSeedOldOpening(string $token, array $entry): void
{
    global $wpdb;
    BookingStorage::release();
    $name = BookingOpeningNotifier::OPTION;
    $raw = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name));
    $cipher = null;
    if ($raw !== null && str_starts_with($raw, BookingCipher::PREFIX)) {
        $v1 = json_decode((string) get_option(BookingStorage::STATE_OPTION), true, flags: JSON_THROW_ON_ERROR);
        $cipher = new BookingCipher(EncryptionKey::fromConfiguration(), $v1['context']);
    }
    $old = $raw === null ? [] : ($cipher ? $cipher->decrypt($raw, $name) : BookingStorage::decodeLegacy($raw));
    $old[$token] = $entry;
    if (!update_option($name, $cipher ? $cipher->encrypt($old, $name) : $old, false)) {
        throw new RuntimeException('Could not seed the synthetic legacy opening.');
    }
}

if (($args[0] ?? '') === 'foreign') {
    auditJobsForeignWriter();
}
if (($args[0] ?? '') === 'startup') {
    // The ordinary WP bootstrap and init hooks have already run in this independent process.
    exit(class_exists(BookingScheduler::class, false) ? 0 : 94);
}

$checks = array_fill_keys([
    'mixed-jobs-innodb-and-local-mail', 'mixed-jobs-foreign-snapshot-ready', 'mixed-jobs-encrypted-at-creation',
    'mixed-jobs-wake-actually-lost', 'mixed-jobs-wake-repaired-by-new-bootstrap',
    'mixed-jobs-only-argumentless-wake', 'mixed-jobs-opening-dispatched-once',
    'mixed-jobs-retry-promotes-and-preserves-times', 'mixed-jobs-early-old-callback-is-harmless',
    'mixed-jobs-foreign-events-preserved',
], false);
if (!class_exists(BookingStorage::class) || !method_exists(BookingStorage::class, 'verifyMixed')) {
    WP_CLI::line(json_encode(['checks' => $checks], JSON_THROW_ON_ERROR));
    return;
}
$worker = null;
$pipes = [];
$foreignHook = 'audit_mixed_foreign_job';
$foreignArgs = ['synthetic-job-check'];
$token = $oldToken = $oldRef = null;
try {
    global $wpdb;
    $engine = $wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options
    ));
    $checks['mixed-jobs-innodb-and-local-mail'] = $engine === 'InnoDB' && $wpdb->dbh instanceof mysqli
        && defined(EncryptionKey::CONFIGURATION_CONSTANT) && ($GLOBALS['phpmailer'] ?? null) instanceof AppointmentAuditMailer;
    if (!$checks['mixed-jobs-innodb-and-local-mail']) {
        throw new RuntimeException('Missing synthetic prerequisites.');
    }
    $now = time();
    wp_clear_scheduled_hook($foreignHook, $foreignArgs);
    wp_clear_scheduled_hook('audit_late_foreign_job', $foreignArgs);
    wp_schedule_single_event($now + 7500, $foreignHook, $foreignArgs);
    wp_clear_scheduled_hook(BookingScheduler::WAKE_HOOK, []);
    $foreignBefore = auditJobsForeignInventory();
    BookingStorage::release();

    $worker = auditJobsWorker('foreign', $pipes);
    stream_set_timeout($pipes[1], 20);
    $checks['mixed-jobs-foreign-snapshot-ready'] = trim((string) fgets($pipes[1])) === 'snapshot-ready';
    if (!$checks['mixed-jobs-foreign-snapshot-ready']) {
        throw new RuntimeException('Foreign writer did not open its snapshot.');
    }

    $email = 'mixed-job-' . bin2hex(random_bytes(6)) . '@example.test';
    $slot = '2099-12-31 03:11-03:19';
    $result = BookingOpeningNotifier::subscribe($slot, ['title' => 'Synthetic opening', 'booker_email' => $email], $now - 2, $now + 3600);
    $token = $result['token'];
    $id = BookingStorage::recordId('opening', $token);
    $name = 'rrze_appointment_v2_record_opening_' . $id;
    $row = $wpdb->get_row($wpdb->prepare('SELECT option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $name), ARRAY_A);
    $state = json_decode((string) get_option(BookingStorage::MIXED_STATE_OPTION), true, flags: JSON_THROW_ON_ERROR);
    $cipher = new BookingCipher(EncryptionKey::fromConfiguration(), $state['context']);
    $record = $cipher->decrypt($row['option_value'], $name);
    $checks['mixed-jobs-encrypted-at-creation'] = $row['autoload'] === 'no' && str_starts_with($row['option_value'], BookingCipher::PREFIX)
        && !str_contains($row['option_value'], $email) && !str_contains($row['option_value'], $token)
        && $record['data_version'] === 2 && $record['id'] === $id
        && array_column($record['jobs'], 'due') === [$now - 2] && wp_next_scheduled(BookingScheduler::WAKE_HOOK) !== false;
    // The foreign writer resumes after the business transaction and writes through the real core API.
    fwrite($pipes[0], "write\n");
    fflush($pipes[0]);
    $foreignCommitted = auditJobsFinish($worker, $pipes);
    $worker = null;
    auditJobsForgetCron();
    $afterForeign = auditJobsForeignInventory();
    $original = $afterForeign;
    foreach ($original as $time => &$hooks) {
        unset($hooks['audit_late_foreign_job']);
        if (!$hooks) {
            unset($original[$time]);
        }
    }
    unset($hooks);
    $checks['mixed-jobs-wake-actually-lost'] = $foreignCommitted && wp_next_scheduled(BookingScheduler::WAKE_HOOK) === false
        && $original === $foreignBefore && wp_next_scheduled('audit_late_foreign_job', $foreignArgs) !== false;
    BookingStorage::release();
    $pipes = [];
    $startup = auditJobsWorker('startup', $pipes);
    $freshStart = auditJobsFinish($startup, $pipes);
    auditJobsForgetCron();
    $wake = wp_next_scheduled(BookingScheduler::WAKE_HOOK);
    $checks['mixed-jobs-wake-repaired-by-new-bootstrap'] = $freshStart && $wake !== false && $wake <= time() + 2;

    $wakeEvents = [];
    foreach (_get_cron_array() as $hooks) {
        foreach (($hooks[BookingScheduler::WAKE_HOOK] ?? []) as $event) {
            $wakeEvents[] = $event['args'];
        }
    }
    $checks['mixed-jobs-only-argumentless-wake'] = $wakeEvents === [[]] && !wp_next_scheduled(BookingOpeningNotifier::CRON_HOOK, [$token]);
    // WordPress unschedules one-off events before dispatch. Also present the old callback twice.
    if ($wake !== false) {
        wp_unschedule_event($wake, BookingScheduler::WAKE_HOOK);
    }
    do_action(BookingScheduler::WAKE_HOOK);
    do_action(BookingOpeningNotifier::CRON_HOOK, $token);
    do_action(BookingOpeningNotifier::CRON_HOOK, $token);
    $checks['mixed-jobs-opening-dispatched-once'] = auditJobsMailCount($email) === 1
        && (BookingStorage::get(BookingOpeningNotifier::OPTION)[$token]['notified_at'] ?? 0) > 0
        && BookingStorage::managedJobs()[$id]['jobs'] === [];

    // This unchanged historical owner receives a new retry. Its pre-existing event is retained.
    $oldToken = wp_generate_uuid4();
    $oldEmail = 'mixed-old-' . bin2hex(random_bytes(6)) . '@example.test';
    $oldDue = $now + 7200;
    $retryDue = $now + 1200;
    auditJobsSeedOldOpening($oldToken, ['slot' => '2099-12-31 03:21-03:29', 'status_token' => wp_generate_uuid4(),
        'meta' => ['title' => 'Synthetic older opening', 'booker_email' => $oldEmail], 'opens_at' => $now - 30,
        'closes_at' => $now + 86400, 'created_at' => $now - 3600, 'notified_at' => 0]);
    $oldRef = BookingStorage::jobReference(BookingOpeningNotifier::CRON_HOOK, $oldToken);
    wp_schedule_single_event($oldDue, BookingOpeningNotifier::CRON_HOOK, [$oldRef]);
    BookingScheduler::schedule($retryDue, BookingOpeningNotifier::CRON_HOOK, $oldToken);
    BookingStorage::release();
    $promoted = BookingStorage::recordId('opening', $oldToken);
    $jobs = BookingStorage::managedJobs()[$promoted]['jobs'];
    $times = array_column($jobs, 'due');
    sort($times, SORT_NUMERIC);
    $checks['mixed-jobs-retry-promotes-and-preserves-times'] = $promoted !== null && $times === [$retryDue, $oldDue]
        && BookingScheduler::next(BookingOpeningNotifier::CRON_HOOK, $oldToken) === $retryDue
        && wp_next_scheduled(BookingOpeningNotifier::CRON_HOOK, [$oldRef]) === $oldDue;
    do_action(BookingOpeningNotifier::CRON_HOOK, $oldRef);
    $checks['mixed-jobs-early-old-callback-is-harmless'] = auditJobsMailCount($oldEmail) === 0
        && BookingStorage::managedJobs()[$promoted]['jobs'] === $jobs;
    $checks['mixed-jobs-foreign-events-preserved'] = auditJobsForeignInventory() === $afterForeign;
} catch (Throwable) {
    // Unreached acceptance criteria stay false. Never emit exception arguments or booking data.
} finally {
    if (is_resource($worker)) {
        proc_terminate($worker);
        auditJobsFinish($worker, $pipes);
    }
    BookingStorage::release();
    try {
        if ($token !== null || $oldToken !== null) {
            $openings = BookingStorage::get(BookingOpeningNotifier::OPTION);
            unset($openings[$token], $openings[$oldToken]);
            BookingStorage::set(BookingOpeningNotifier::OPTION, $openings);
        }
        if ($oldRef !== null) {
            wp_clear_scheduled_hook(BookingOpeningNotifier::CRON_HOOK, [$oldRef]);
        }
        wp_clear_scheduled_hook($foreignHook, $foreignArgs);
        wp_clear_scheduled_hook('audit_late_foreign_job', $foreignArgs);
        BookingScheduler::repairWake();
    } catch (Throwable) {
        $checks['mixed-jobs-fixture-cleanup'] = false;
    }
    BookingStorage::release();
}
WP_CLI::line(json_encode(['checks' => $checks], JSON_THROW_ON_ERROR));
