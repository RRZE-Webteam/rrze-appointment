"""Additional local fault and concurrency scenarios using the guarded Audit runner."""

import concurrent.futures
import json
from pathlib import Path
import re
import subprocess
import time


def restart_database(audit):
    """Restart only the audit datadir, with networking disabled and its private socket."""
    server = Path(audit.config.get('mysqld', '/Applications/MAMP/Library/bin/mysql57/bin/mysqld'))
    assert Path(audit.config['socket']).parent == audit.root
    with open(audit.root / 'mysql-process.log', 'a') as log:
        process = subprocess.Popen([str(server), '--no-defaults', '--basedir=' + str(server.parents[1]),
            '--datadir=' + str(audit.root / 'mysql-data'), '--socket=' + audit.config['socket'],
            '--skip-networking', '--pid-file=' + str(audit.root / 'mysql.pid'),
            '--log-error=' + str(audit.root / 'mysql.log'), '--innodb-buffer-pool-size=64M',
            '--max-allowed-packet=128M', '--explicit_defaults_for_timestamp=1'],
            stdout=log, stderr=log, start_new_session=True)
    for _ in range(100):
        if Path(audit.config['socket']).exists():
            return
        if process.poll() is not None:
            raise RuntimeError('The isolated database failed to restart; inspect mysql.log.')
        time.sleep(0.1)
    raise RuntimeError('The isolated database did not restart within ten seconds.')


def database_outage(audit):
    """Stop the real isolated MySQL server and verify errors and intact recovery."""
    audit.restore(name='encrypted')
    scenario = audit.fixture('context')
    before = audit.fixture('summary')
    admin = str(Path(audit.config['mysql_bin']) / 'mysqladmin')
    audit.command([admin, '--no-defaults', '--socket=' + audit.config['socket'], '--user=root', 'shutdown'])
    try:
        page = audit.http(scenario['post_url'])
        booking = audit.http(audit.config['nat_url'] + 'wp-admin/admin-ajax.php', {
            'action': 'rrze_appointment_book', 'nonce': scenario['nonce'], 'slot': scenario['slots']['new'],
            'post_id': scenario['post_id'], 'block_id': scenario['block_id'],
            'booker_name': 'Outage synthetic', 'booker_email': 'outage@example.test'})
    finally:
        restart_database(audit)
    after = audit.fixture('summary')
    recovered = audit.http(scenario['post_url'])
    equal = before['stores'] == after['stores']
    try:
        booking_success = json.loads(booking['body']).get('success') is True
    except (ValueError, AttributeError):
        booking_success = False
    database_error = 'Error establishing a database connection' in booking['body']
    audit.check('real-database-outage-fails-closed-and-recovers',
        page['status'] >= 500 and not booking_success and database_error and equal and recovered['status'] == 200,
        page_status=page['status'], booking_status=booking['status'], unchanged_stores=equal,
        recovered_status=recovered['status'], ajax_success=booking_success, ajax_database_error=database_error)


def cron_recovery(audit):
    """Run opaque reminder jobs with a missing key, then verify deferred delivery."""
    audit.restore(name='encrypted')
    hook = 'rrze_appointment_send_reminder'
    before_jobs = [row for row in audit.fixture('summary')['cron'] if row['hook'] == hook]
    before_mail = audit.mail_count()
    (audit.root / 'encryption-enabled').unlink()
    try:
        audit.wp(['cron', 'event', 'run', hook])
        failed = audit.fixture('summary')
        deferred = [row for row in failed['cron'] if row['hook'] == hook]
        no_mail = audit.mail_count() == before_mail
    finally:
        (audit.root / 'encryption-enabled').touch()
    audit.wp(['cron', 'event', 'run', hook])
    delivered = audit.mail_count() - before_mail
    same_refs = sorted(row['args_digest'] for row in before_jobs) == sorted(row['args_digest'] for row in deferred)
    audit.check('opaque-reminders-retry-after-temporary-key-failure',
        len(deferred) == 3 and all(row['opaque'] for row in deferred) and same_refs and no_mail and delivered == 6,
        deferred_jobs=len(deferred), same_references=same_refs, no_mail_during_failure=no_mail,
        recovery_messages=delivered, retry_dispatch='WP-CLI explicitly runs deferred events without waiting five minutes')


def pending_expiry(audit):
    """Verify a real legacy reservation remains blocked until expiration after upgrade."""
    audit.restore(version='baseline', encrypted=False)
    scenario = audit.fixture('fresh-pending')
    audit.stop()
    audit.install('candidate')
    (audit.root / 'encryption-enabled').touch()
    audit.start()
    audit.http(scenario['post_url'])
    before = audit.fixture('logical-slots')
    audit.fixture('expire-current')
    # Real WordPress startup cleans expired reservations before cron dispatch.
    audit.http(scenario['post_url'])
    after = audit.fixture('logical-slots')
    old_link = audit.http(audit.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
    audit.check('legacy-reservation-expires-and-old-link-rejects',
        scenario['slots']['confirm'] in before['pending'] and scenario['slots']['confirm'] not in after['pending']
        and old_link['status'] == 410 and before['booked'] == after['booked'],
        pending_before=len(before['pending']), pending_after=len(after['pending']), expired_link_status=old_link['status'])


def mixed_operations(audit):
    """Overlap real visitors, booking, confirmation, cancellation and cron at upgrade."""
    audit.restore(version='baseline', encrypted=False)
    audit.fixture('fresh-pending')
    scenario = audit.fixture('context')
    cancel_page = audit.http(scenario['cancel_urls']['cancel'])
    match = re.search(r'name="rrze_appt_cancel_nonce"[^>]*value="([^"]+)"', cancel_page['body'])
    if match is None:
        raise RuntimeError('Legacy cancellation form did not provide its nonce.')
    audit.stop()
    audit.install('candidate')
    (audit.root / 'encryption-enabled').touch()
    audit.start()
    data = {'action': 'rrze_appointment_book', 'nonce': scenario['nonce'], 'slot': scenario['slots']['parallel'],
            'post_id': scenario['post_id'], 'block_id': scenario['block_id'],
            'booker_name': 'Overlap synthetic', 'booker_email': 'overlap@example.test'}
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        page = pool.submit(audit.http, scenario['post_url'])
        booking = pool.submit(audit.http, audit.config['nat_url'] + 'wp-admin/admin-ajax.php', data)
        confirm = pool.submit(audit.http, audit.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
        cancel = pool.submit(audit.http, scenario['cancel_urls']['cancel'],
            {'rrze_appt_cancel_action': 'cancel', 'rrze_appt_cancel_nonce': match[1], 'cancellation_reason': 'Local overlap'})
        cron = pool.submit(audit.wp, ['cron', 'event', 'run', 'rrze_appointment_send_reminder'])
        responses = [future.result() for future in (page, booking, confirm, cancel)]
        cron.result()
    final = audit.fixture('logical-slots')
    expected = {scenario['slots'][name] for name in ('booked', 'optout', 'confirm')}
    audit.check('overlapping-first-migration-all-booking-operations',
        all(row['status'] == 200 for row in responses) and json.loads(responses[1]['body']).get('success') is True
        and set(final['booked']) == expected and final['pending'] == [scenario['slots']['parallel']],
        statuses=[row['status'] for row in responses], seconds=[row['seconds'] for row in responses],
        booked_count=len(final['booked']), pending_count=len(final['pending']), exact_expected_slots=set(final['booked']) == expected)


def network_concurrency(audit):
    """Run two network workers alongside a visitor and compare each site's own data."""
    audit.restore(version='baseline', encrypted=False)
    audit.fixture('network-seed')
    before = {site: audit.fixture('site-summary', site) for site in (2, 3)}
    audit.stop()
    audit.install('candidate')
    (audit.root / 'encryption-enabled').touch()
    audit.start()
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        workers = [pool.submit(audit.fixture_on, audit.config['url'] + '/', 'network') for _ in range(2)]
        visitor = pool.submit(audit.http, audit.config['nat_url'])
        progress = [future.result() for future in workers]
        response = visitor.result()
    after = {site: audit.fixture('site-summary', site) for site in (2, 3)}
    identical = all(all(after[site]['stores'][key]['digest'] == value['digest']
        for key, value in before[site]['stores'].items()) for site in before)
    audit.check('parallel-network-workers-preserve-site-isolation',
        identical and response['status'] == 200 and all(row['phase'] == 'encrypted' for row in after.values()),
        identical_site_payloads=identical, visitor_status=response['status'],
        worker_scanned=[row.get('scanned', 0) if isinstance(row, dict) else 0 for row in progress])


def run_additional(audit):
    """Run each independent supplemental scenario and preserve harness error evidence."""
    for check in (database_outage, cron_recovery, pending_expiry, mixed_operations, network_concurrency):
        audit.results['checks'] = [row for row in audit.results['checks']
                                   if row['name'] != check.__name__ + '-harness-completion']
        try:
            check(audit)
        except Exception as error:
            audit.check(check.__name__ + '-harness-completion', False, error=str(error)[-2000:])
