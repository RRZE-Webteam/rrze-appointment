"""Run old links, fresh reservations and a controlled version handoff on real HTTP workers."""

import concurrent.futures
import hashlib
from html.parser import HTMLParser
import json
from datetime import datetime
import urllib.error
import urllib.parse
import urllib.request
from zoneinfo import ZoneInfo


class FormInputs(HTMLParser):
    """Collect hidden form values from the actual WordPress response."""

    def __init__(self):
        """Start an empty field map for this response."""
        super().__init__()
        self.fields = {}

    def handle_starttag(self, tag, attrs):
        """Record only named hidden inputs and their HTML-decoded values."""
        item = dict(attrs)
        if tag == 'input' and item.get('type') == 'hidden' and item.get('name'):
            self.fields[item['name']] = item.get('value', '')


def form(response, field):
    """Require a genuine preview and its nonce before submitting the business operation."""
    inputs = FormInputs()
    inputs.feed(response['body'])
    if response['status'] != 200 or not inputs.fields.get(field):
        raise RuntimeError(f'Synthetic preview did not supply {field} (HTTP {response["status"]}).')
    return inputs.fields


def booking(audit, context, slot, email, hold=False):
    """Use the published block, visitor nonce, and real public booking AJAX handler."""
    data = {'action': 'rrze_appointment_book', 'nonce': context['nonce'],
            'slot': context['slots'][slot], 'post_id': context['post_id'], 'block_id': context['block_id'],
            'booker_name': 'Mixed synthetic visitor', 'booker_email': email}
    if hold:
        data['audit_hold_mixed'] = '1'
    return audit.http(audit.config['nat_url'] + 'wp-admin/admin-ajax.php', data)


def accepted(response):
    """Recognize an actual successful AJAX result, independently of HTTP status."""
    try:
        return response['status'] == 200 and json.loads(response['body']).get('success') is True
    except (ValueError, AttributeError):
        return False


def footprint(audit, tables, include_cron=False):
    """Fingerprint physical Appointment rows, including autoload, without logging payloads."""
    queries = []
    for table in tables:
        if table not in {'wp_options', *(f'wp_{i}_options' for i in range(2, 6))}:
            raise ValueError('Unknown audit table.')
        predicate = "option_name REGEXP '^rrze_appointment_(booked_slots|pending_slots|cancel_tokens|booking_opening_notifications|storage_state|record_index|v2_)'"
        if include_cron:
            predicate += " OR option_name='cron'"
        queries.append(f"SELECT '{table}' AS tbl, option_name, SHA2(option_value,256), autoload FROM {table} WHERE {predicate}")
    rows = audit.sql(' UNION ALL '.join(queries) + ' ORDER BY tbl, option_name')
    return hashlib.sha256(rows.encode()).hexdigest()


def source_rows(audit):
    """Return exact-byte fingerprints for the five source options of the active subsite."""
    return audit.sql("SELECT option_name,SHA2(option_value,256),autoload FROM wp_2_options "
                     "WHERE option_name IN ('rrze_appointment_booked_slots','rrze_appointment_booked_slots_meta',"
                     "'rrze_appointment_pending_slots','rrze_appointment_cancel_tokens','rrze_appointment_booking_opening_notifications') "
                     "ORDER BY option_name")


def records(audit, kind):
    """Read V2 option names and verify encryption plus disabled autoload in the physical table."""
    if kind not in ('booking', 'pending', 'opening'):
        raise ValueError('Unknown record type.')
    prefix = 'rrze_appointment_v2_record_' + kind + '_'
    rows = audit.sql("SELECT option_name,option_value LIKE 'rrze-appointment:encrypted:%',autoload "
                     f"FROM wp_2_options WHERE LEFT(option_name,{len(prefix)})='{prefix}' ORDER BY option_name")
    result = []
    for line in rows.splitlines():
        name, encrypted, autoload = line.split('\t')
        if encrypted != '1' or autoload != 'no':
            raise RuntimeError('Synthetic record lacks ciphertext or is autoloaded.')
        result.append(name[len(prefix):])
    return result


def anchor(audit):
    """Hash only the untouched legacy control booking and all its legacy cancellation aliases."""
    return audit.wp(['eval', """
        $s = get_option('rrze_audit_scenario')['slots']['booked'];
        $slots = get_option('rrze_appointment_booked_slots', []);
        $meta = get_option('rrze_appointment_booked_slots_meta', []);
        $cancel = array_filter(get_option('rrze_appointment_cancel_tokens', []),
            static fn($e) => (is_array($e) ? ($e['slot'] ?? null) : $e) === $s);
        echo hash('sha256', serialize([in_array($s,$slots,true),$meta[$s] ?? null,$cancel]));
    """], skip=True)


def admin_preview(audit):
    """Read the real management screen with a short-lived synthetic admin session."""
    cookies = json.loads(audit.wp(['eval', """
        $expires = time() + 180;
        $token = WP_Session_Tokens::get_instance(1)->create($expires);
        echo wp_json_encode([AUTH_COOKIE => wp_generate_auth_cookie(1,$expires,'auth',$token),
            LOGGED_IN_COOKIE => wp_generate_auth_cookie(1,$expires,'logged_in',$token)]);
    """], skip=True))
    url = audit.config['nat_url'] + 'wp-admin/admin.php?page=rrze-appointment-bookings'
    if not url.startswith(audit.config['url'] + '/'):
        raise RuntimeError('Management request must use the audit server.')
    request = urllib.request.Request(url, headers={'Cookie': '; '.join(k + '=' + v for k, v in cookies.items())})
    try:
        response = urllib.request.urlopen(request, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status == 200 and b'id="rrze-appointment-admin"' in response.read()


def check_month_password_redirect(audit, context):
    """Verify the native password form carries both calendars of the current page through login."""
    post_id = int(context['post_id'])
    prefix = f'rrze_appt_month_{post_id}_' + 'a' * 64 + '_'
    inputs = FormInputs()
    inputs.feed(audit.wp(['eval', f"""
        $_GET = ['{prefix}1' => '2099-03', 'other' => 'omit', '{prefix}2' => '2099-04',
            '{prefix}3' => '2099-13', '{prefix}4' => ['2099-05'],
            'rrze_appt_month_0_' . str_repeat('a', 64) . '_1' => '2099-06'];
        echo get_the_password_form({post_id});
    """]))
    page = urllib.parse.urlsplit(context['post_url'])
    query = urllib.parse.parse_qsl(page.query) + [(prefix + '1', '2099-03'), (prefix + '2', '2099-04')]
    expected = page._replace(query=urllib.parse.urlencode(query), fragment=prefix + '2')
    audit.check('password-form-preserves-only-current-calendar-months', inputs.fields.get('redirect_to') == expected.geturl())


def workflow_links(audit):
    """Exercise a normal package update, non-mutating previews, atomic actions and private bookings."""
    audit.restore(version='baseline', encrypted=False)
    context = audit.fixture('context')
    if context['slots']['new'][:10] <= datetime.now(ZoneInfo('Europe/Berlin')).date().isoformat():
        audit.seed()
    audit.fixture('fresh-pending')
    audit.fixture('opening-ready')
    context = audit.fixture('context')
    before = footprint(audit, ['wp_2_options'])
    others = ['wp_options', 'wp_3_options', 'wp_4_options', 'wp_5_options']
    unrelated = footprint(audit, others, include_cron=True)
    untouched = anchor(audit)
    original_sources = source_rows(audit)
    foreign = [ev for ev in audit.fixture('summary')['cron'] if ev['hook'] == 'audit_foreign_event']
    if not foreign:
        raise RuntimeError('The synthetic control cron event is missing.')
    (audit.root / 'encryption-enabled').touch()
    audit.update_plugin()
    check_month_password_redirect(audit, context)

    site = audit.config['nat_url']
    confirm = site + '?rrze_appt_confirm=' + context['tokens']['confirm']
    claim = site + '?rrze_appt_opening=' + context['opening']['token']
    wait = context['optout_urls']['optout']
    cancel = context['cancel_urls']['cancel']
    targets = [context['post_url'], context['list_url'], confirm, cancel, wait, claim,
               site + '?rrze_appt_opening_registered=' + context['opening']['statusToken']]
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        previews = list(pool.map(audit.http, targets))
    management = admin_preview(audit)
    rest = audit.fixture('rest', 'admin')
    private = all('no-store' in reply['headers'].get('Cache-Control', '') for reply in previews[2:])
    unchanged = footprint(audit, ['wp_2_options']) == before
    audit.check('mixed-http-previews-and-management-preserve-source-bytes',
                unchanged and all(reply['status'] == 200 for reply in previews) and management and rest['status'] == 200 and private,
                protected_rows_identical=unchanged, preview_statuses=[reply['status'] for reply in previews],
                management_rendered=management, authenticated_rest=rest['status'], private_headers=private)

    before_mail = audit.mail_count()
    new = booking(audit, context, 'new', 'mixed-new@example.test')
    pending_ids = records(audit, 'pending')
    new_token = audit.fixture('pending-token', context['slots']['new'])['token']
    if not new_token:
        raise RuntimeError('The synthetic public reservation did not produce a readable token.')
    new_url = site + '?rrze_appt_confirm=' + new_token
    preview = audit.http(new_url)
    invalid = audit.http(new_url, {'rrze_appt_questions_nonce': 'synthetic-invalid'})
    invalid_preserved = records(audit, 'pending') == pending_ids and not records(audit, 'booking')
    confirmed = audit.http(new_url, form(preview, 'rrze_appt_questions_nonce'))
    booked_ids = records(audit, 'booking')
    duplicate = audit.http(new_url)
    audit.check('mixed-new-public-reservation-encrypted-from-first-write',
                accepted(new) and len(pending_ids) == 1 and invalid_preserved and confirmed['status'] == 200
                and 'rrze_appt_questions_nonce' not in confirmed['body'] and booked_ids == pending_ids
                and not records(audit, 'pending') and duplicate['status'] == 410
                and original_sources == source_rows(audit) and audit.mail_count() - before_mail == 3,
                reservation_ciphertexts=len(pending_ids), confirmed_ciphertexts=len(booked_ids), stable_id=pending_ids == booked_ids,
                rejected_nonce_status=invalid['status'], retry_token_status=duplicate['status'], source_rows_identical=original_sources == source_rows(audit))

    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        replies = list(pool.map(lambda _: booking(audit, context, 'parallel', 'mixed-parallel@example.test'), range(4)))
    successes = sum(accepted(reply) for reply in replies)
    slots = audit.fixture('logical-slots')
    audit.check('mixed-concurrent-http-reservations-have-one-owner',
                successes == 1 and slots['pending'].count(context['slots']['parallel']) == 1,
                successes=successes, attempts=len(replies), statuses=[r['status'] for r in replies])

    confirm_reply = audit.http(confirm, form(previews[2], 'rrze_appt_questions_nonce'))
    cancel_reply = audit.http(cancel, form(previews[3], 'rrze_appt_cancel_nonce'))
    wait_fields = form(previews[4], 'rrze_appt_waitlist_nonce')
    wait_reply = audit.http(wait, wait_fields)
    next_wait_fields = form(wait_reply, 'rrze_appt_waitlist_nonce')
    claim_reply = audit.http(claim, form(previews[5], 'rrze_appt_opening_nonce'))
    opening_token = audit.fixture('pending-token', context['slots']['opening'])['token']
    if not opening_token:
        raise RuntimeError('The synthetic opening claim did not produce a pending confirmation.')
    opening_reply = audit.http(site + '?rrze_appt_confirm=' + opening_token, form(claim_reply, 'rrze_appt_questions_nonce'))
    final = audit.fixture('logical-slots')
    expected = {context['slots'][k] for k in ('booked', 'optout', 'confirm', 'new', 'opening')}
    consumed = [audit.http(url)['status'] for url in (confirm, cancel, claim)]
    foreign_after = [ev for ev in audit.fixture('summary')['cron'] if ev['hook'] == 'audit_foreign_event']
    audit.check('mixed-legacy-action-links-apply-one-business-change', all(
        r['status'] == 200 for r in (confirm_reply, cancel_reply, wait_reply, claim_reply, opening_reply))
        and wait_fields.get('rrze_appt_waitlist_action') == 'optout' and next_wait_fields.get('rrze_appt_waitlist_action') == 'optin'
        and consumed == [410, 410, 410] and set(final['booked']) == expected and final['pending'] == [context['slots']['parallel']]
        and len(records(audit, 'booking')) == 4 and anchor(audit) == untouched,
        consumed_statuses=consumed, booked_count=len(final['booked']), encrypted_bookings=len(records(audit, 'booking')),
        untouched_legacy_booking_preserved=anchor(audit) == untouched)
    audit.check('mixed-update-isolated-to-active-site-and-owned-jobs',
                foreign_after == foreign and footprint(audit, others, include_cron=True) == unrelated,
                unaffected_sites=len(others), foreign_jobs_before=len(foreign), foreign_jobs_after=len(foreign_after))


def handoff(audit, drain):
    """Coordinate the same genuine legacy request through drained and undrained package updates."""
    audit.restore(version='baseline', encrypted=False)
    context = audit.fixture('context')
    (audit.root / 'encryption-enabled').touch()
    arm, ready, release = (audit.root / ('mixed-slot-' + suffix) for suffix in ('arm', 'ready', 'release'))
    for flag in (ready, release):
        flag.unlink(missing_ok=True)
    arm.touch()
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        old = pool.submit(booking, audit, context, 'parallel', 'old-race@example.test', True)
        try:
            audit.wait_file('mixed-slot-ready')
            if drain:
                release.touch()
                old_reply = old.result(timeout=30)
            audit.update_plugin()
            new_reply = booking(audit, context, 'parallel', 'new-race@example.test')
            if not drain:
                before_release = audit.fixture('summary')
                ciphertext_before = audit.sql("SELECT SHA2(option_value,256) FROM wp_2_options "
                                              "WHERE option_name REGEXP '^rrze_appointment_v2_record_pending_' ORDER BY option_name")
                release.touch()
                old_reply = old.result(timeout=30)
        finally:
            release.touch()
            arm.unlink(missing_ok=True)
    for flag in (ready, release):
        flag.unlink(missing_ok=True)
    seen_old = audit.sql("SELECT LOCATE('old-race@example.test',option_value)>0 FROM wp_2_options "
                         "WHERE option_name='rrze_appointment_pending_slots'") == '1'
    verified = audit.fixture('summary')
    count_v2 = len(records(audit, 'pending'))
    if drain:
        slots = audit.fixture('logical-slots')
        other_slot = booking(audit, context, 'new', 'other-race@example.test')
        audit.check('mixed-real-package-update-after-legacy-writer-finished',
                    accepted(old_reply) and seen_old and not accepted(new_reply) and count_v2 == 0
                    and not verified['errors'] and slots['pending'].count(context['slots']['parallel']) == 1 and accepted(other_slot),
                    legacy_completed_before_update=True, new_same_slot_accepted=accepted(new_reply), different_slot_accepted=accepted(other_slot))
    else:
        ciphertext_after = audit.sql("SELECT SHA2(option_value,256) FROM wp_2_options "
                                     "WHERE option_name REGEXP '^rrze_appointment_v2_record_pending_' ORDER BY option_name")
        same_v2 = bool(ciphertext_before) and ciphertext_before == ciphertext_after
        audit.check('mixed-negative-control-undrained-legacy-writer-conflicts',
                    accepted(new_reply) and seen_old and count_v2 == 1 and same_v2
                    and not before_release['errors'] and bool(verified['errors']),
                    writer_held_after_slot_check=True, legacy_response_status=old_reply['status'],
                    legacy_success=accepted(old_reply), new_success=accepted(new_reply), legacy_write_seen=seen_old,
                    encrypted_record_bytes_identical=same_v2, intact_encrypted_pending_rows=count_v2,
                    blocked_views=len(verified['errors']))


def run_workflows(audit):
    """Execute the public mixed-mode acceptance checks on the disposable loopback installation."""
    workflow_links(audit)
    handoff(audit, drain=False)
    handoff(audit, drain=True)
