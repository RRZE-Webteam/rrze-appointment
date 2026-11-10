#!/usr/bin/env python3
"""Run local production-readiness checks against the guarded synthetic multisite."""

import argparse
import concurrent.futures
from email import policy
from email.parser import BytesParser
from datetime import date, timedelta
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile


PHASES = ('build', 'seed', 'upgrade', 'cron_race', 'concurrency', 'faults', 'recovery',
          'network', 'mail_and_routes', 'load', 'native_smoke', 'first_concurrent',
          'mixed_versions', 'slow_mail', 'confirmation_failure', 'sso_boundary',
          'rollout', 'notification_cycle', 'additional', 'cron_load', 'repeat_build', 'atomic', 'maintenance', 'confirmation_delivery_failure')


MIXED_PHASES = ('build', 'seed', 'mixed_workflows', 'mixed_jobs', 'mixed_compat', 'mixed_atomic', 'mixed_cli')
PHASES += tuple(phase for phase in ('working_tree', *MIXED_PHASES) if phase not in PHASES)


class Audit:
    """Own the isolated processes, database snapshots and machine-readable evidence."""

    def __init__(self, root):
        """Load a setup manifest and refuse any non-audit database or source path."""
        self.root = Path(root).resolve()
        self.config = json.loads((self.root / 'manifest.json').read_text())
        if not self.config['database'].startswith('rrze_appt_audit_'):
            raise RuntimeError('Refusing a database outside the synthetic audit namespace.')
        self.site = Path(self.config['wordpress']).resolve()
        if self.site != self.root / 'wordpress' or Path(self.config['socket']).parent != self.root:
            raise RuntimeError('The WordPress copy and database socket must belong to this audit root.')
        if self.config['url'] != 'http://127.0.0.1:' + str(self.config['port']):
            raise RuntimeError('The audit server must use loopback only.')
        self.repo = Path(self.config['repo'])
        shutil.copy2(self.repo / 'tests/readiness/audit-mu.php', self.site / 'wp-content/mu-plugins/audit.php')
        shutil.copy2(self.repo / 'tests/readiness/audit-clock.php', self.site / 'wp-content/mu-plugins/audit-clock.php')
        self.env = dict(os.environ, PATH=self.config['mysql_bin'] + os.pathsep + os.environ['PATH'])
        self.cli = [self.config['php'], self.config['wp'], '--path=' + str(self.site), '--skip-themes']
        self.server = None
        self.results = {'candidate': self.config['candidate'], 'baseline': self.config['baseline'],
                        'scope': 'local synthetic data only', 'checks': []}
        if (self.root / 'results.json').exists():
            self.results = json.loads((self.root / 'results.json').read_text())

    def command(self, arguments, cwd=None, timeout=180):
        """Run a checked subprocess without exposing full command arguments in errors."""
        completed = subprocess.run(arguments, cwd=cwd, env=self.env, text=True,
                                   capture_output=True, timeout=timeout)
        if completed.returncode:
            raise RuntimeError(completed.stderr[-2500:] or completed.stdout[-2500:])
        return completed.stdout.strip()

    def wp(self, arguments, skip=False):
        """Execute WP-CLI against the isolated site, optionally without appointment startup."""
        prefix = self.cli + ['--url=' + self.config['nat_url']]
        if skip:
            prefix += ['--skip-plugins=rrze-appointment']
        return self.command(prefix + arguments)

    def fixture(self, mode, *arguments, skip=False):
        """Run the documented PHP fixture and decode its final JSON line."""
        output = self.wp(['eval-file', str(self.repo / 'tests/readiness/fixture.php'), mode, *map(str, arguments)], skip)
        return json.loads(output.splitlines()[-1])

    def fixture_on(self, url, mode, *arguments):
        """Run a fixture in an explicitly selected site of the isolated network."""
        assert url.startswith(self.config['url'])
        output = self.command(self.cli + ['--url=' + url, 'eval-file',
            str(self.repo / 'tests/readiness/fixture.php'), mode, *map(str, arguments)])
        return json.loads(output.splitlines()[-1])

    def sql(self, query):
        """Use WP-CLI's pre-bootstrap DB command, so inspection cannot trigger migration."""
        return self.wp(['db', 'query', query, '--skip-column-names', '--batch', '--raw'], skip=True)

    def check(self, name, passed, **evidence):
        """Persist a named assertion and sanitized evidence immediately."""
        row = dict(name=name, status='pass' if passed else 'fail', evidence=evidence)
        self.results['checks'] = [check for check in self.results['checks'] if check['name'] != name]
        self.results['checks'].append(row)
        self.save()
        print(json.dumps(row), flush=True)

    def save(self):
        """Write audit results without test keys, passwords or raw booking payloads."""
        (self.root / 'results.json').write_text(json.dumps(self.results, indent=2) + '\n')

    def start(self):
        """Start eight local PHP workers with real WordPress and no CLI opcode cache."""
        if self.server:
            return
        env = dict(self.env, PHP_CLI_SERVER_WORKERS='8')
        log = open(self.root / 'http.log', 'a')
        self.server = subprocess.Popen([self.config['php'], '-d', 'opcache.enable_cli=0',
            '-d', 'memory_limit=256M', '-d', 'max_execution_time=30', '-S',
            '127.0.0.1:' + str(self.config['port']), '-t', str(self.site),
            str(self.repo / 'tests/readiness/router.php')], env=env, stdout=log, stderr=log,
            start_new_session=True)
        (self.root / 'http.pid').write_text(str(self.server.pid))
        time.sleep(0.5)
        if self.server.poll() is not None:
            raise RuntimeError('Local PHP server failed; inspect http.log.')

    def stop(self):
        """Stop this audit's workers, including a verified preview from a previous run."""
        if self.server:
            os.killpg(self.server.pid, signal.SIGTERM)
            self.server.wait(timeout=10)
            self.server = None
        else:
            pid_file = self.root / 'http.pid'
            if pid_file.exists():
                pid = int(pid_file.read_text())
                process = subprocess.run(['ps', '-p', str(pid), '-o', 'command='], text=True, capture_output=True)
                if str(self.site) in process.stdout and 'router.php' in process.stdout:
                    os.killpg(pid, signal.SIGTERM)
                    time.sleep(0.2)
        (self.root / 'http.pid').unlink(missing_ok=True)

    def http(self, url, data=None):
        """Call a real local WordPress route and retain status, headers and timing."""
        if not url.startswith(self.config['url'] + '/'):
            raise RuntimeError('HTTP audit requests must remain on the isolated loopback site.')
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        request = urllib.request.Request(url, data=body)
        started = time.monotonic()
        try:
            response = urllib.request.urlopen(request, timeout=40)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return dict(status=response.status, headers=dict(response.headers),
                        body=response.read().decode('utf-8', errors='replace'),
                        seconds=round(time.monotonic() - started, 4))

    def install(self, version):
        """Replace only the plugin inside the disposable installation."""
        target = self.site / 'wp-content/plugins/rrze-appointment'
        assert target.is_relative_to(self.root)
        if target.exists():
            shutil.rmtree(target)
        if version == 'candidate':
            with zipfile.ZipFile(self.root / 'candidate.zip') as archive:
                archive.extractall(target.parent)
        else:
            shutil.copytree(self.root / 'baseline', target)

    def working_tree(self):
        """Freeze a fresh candidate and discard prior evidence tied to a different snapshot."""
        from prepare import snapshot_working_tree
        target = self.root / 'candidate'
        if target.is_symlink():
            raise RuntimeError('Refusing to replace a linked candidate directory.')
        if target.exists():
            shutil.rmtree(target)
        snapshot_working_tree(self.repo, target)
        self.config['candidate'] = (self.root / 'candidate-source.txt').read_text()
        (self.root / 'manifest.json').write_text(json.dumps(self.config, indent=2) + '\n')
        self.results = {'candidate': self.config['candidate'], 'baseline': self.config['baseline'],
                        'scope': 'local synthetic data only', 'checks': []}
        self.save()

    def update_plugin(self):
        """Install the staging ZIP through WordPress' upgrader on the disposable network."""
        self.wp(['plugin', 'install', str(self.root / 'candidate.zip'), '--force'])
        self.wp(['plugin', 'is-active', 'rrze-appointment', '--network'])

    def mixed_workflows(self):
        """Exercise ordinary HTTP routes, the version switch and concurrent writers."""
        from mixed_workflows import run_workflows
        run_workflows(self)

    def mixed_jobs(self):
        """Verify native WordPress-Cron loss/recovery against stored record jobs."""
        self.restore()
        self.mixed_fixture('mixed-jobs')

    def mixed_fixture(self, name, *arguments, skip=False, prefix=''):
        """Run a guarded native fixture and publish only its named boolean assertions."""
        output = self.wp(['eval-file', str(self.repo / 'tests/readiness' / (name + '.php')), *arguments], skip)
        checks = json.loads(output.splitlines()[-1])['checks']
        if not isinstance(checks, dict) or not checks or not all(type(value) is bool for value in checks.values()):
            raise RuntimeError('Expected named boolean checks from ' + name)
        for label, passed in checks.items():
            self.check(name + '-' + prefix + label, passed)

    def mixed_compat(self):
        """Check V1 fixtures, V2 damage and key outages in separate PHP processes."""
        self.restore()
        self.mixed_fixture('mixed-compat', skip=True)
        self.mixed_fixture('mixed-compat', 'seed-key-test', skip=True)
        flag = self.root / 'encryption-enabled'
        key = self.root / 'key.txt'
        saved = key.read_bytes()
        try:
            flag.unlink()
            self.mixed_fixture('mixed-compat', 'reject-key', skip=True, prefix='missing-')
            import base64
            key.write_bytes(base64.b64encode(os.urandom(32)))
            flag.touch()
            self.mixed_fixture('mixed-compat', 'reject-key', skip=True, prefix='wrong-')
        finally:
            key.write_bytes(saved)
            flag.touch()
            self.mixed_fixture('mixed-compat', 'cleanup', skip=True)

    def mixed_atomic(self):
        """Inject native SQL and connection failures on both legacy and V2 write paths."""
        for encrypted in (False, True):
            self.restore(encrypted=encrypted)
            self.mixed_fixture('atomic', skip=True, prefix=('mixed-' if encrypted else 'legacy-'))

    def mixed_cli(self):
        """Check distinct full-encryption, mixed-integrity and disabled migration exit codes."""
        self.restore()
        names = ','.join("'" + name + "'" for name in self.results['legacy']['stores'])
        query = ("SELECT option_name, SHA2(option_value,256) FROM wp_2_options "
                 "WHERE option_name IN (" + names + ") OR option_name LIKE 'rrze_appointment%v2%' ORDER BY option_name")
        before = self.sql(query)
        for mode, success in [('verify-mixed', True), ('verify', False), ('migrate', False)]:
            code, rows = self.run_maintenance(mode, network=False)
            self.check('cli-' + mode, (code == 0) == success, exit=code)
        code, rows = self.run_maintenance('verify-mixed', network=True)
        self.check('cli-inspects-actual-network', code == 0 and rows[-1]['checked'] == 5)
        self.check('cli-leaves-source-bytes-untouched', self.sql(query) == before)

    def snapshot(self, name):
        """Export only the isolated database; the test key remains a separate file."""
        file = self.root / (name + '.sql')
        self.wp(['db', 'export', str(file), '--single-transaction', '--add-drop-table'], skip=True)
        file.chmod(0o600)

    def restore(self, name='legacy', version='candidate', encrypted=True):
        """Restore a known synthetic snapshot and restart with a selected plugin version."""
        self.stop()
        for name_to_clear in ('fault.json', 'fault-fired', 'mail-delay', 'mail-entered', 'mail-fail', 'notification-clock'):
            (self.root / name_to_clear).unlink(missing_ok=True)
        self.wp(['db', 'import', str(self.root / (name + '.sql'))], skip=True)
        self.install(version)
        key_flag = self.root / 'encryption-enabled'
        if encrypted:
            key_flag.touch()
        else:
            key_flag.unlink(missing_ok=True)
        self.start()

    def build(self):
        """Build frozen DEV sources without invoking the package's version-bump script."""
        candidate = self.root / 'candidate'
        dependencies = candidate / 'node_modules'
        if not dependencies.exists():
            dependencies.symlink_to(self.repo / 'node_modules', target_is_directory=True)
        log = self.command(['node', str(dependencies / '@wordpress/scripts/bin/wp-scripts.js'),
                            'build', '--webpack-copy-php'], cwd=candidate)
        log += '\n' + self.command(['npm', 'run', 'build:email'], cwd=candidate)
        (self.root / 'build.log').write_text(log)
        version = json.loads((candidate / 'package.json').read_text())['version']
        with zipfile.ZipFile(self.root / 'candidate.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
            for path in sorted(candidate.rglob('*')):
                relative = path.relative_to(candidate)
                if 'node_modules' in relative.parts or 'tests' in relative.parts or not path.is_file():
                    continue
                entry = zipfile.ZipInfo('rrze-appointment/' + relative.as_posix(), (2026, 10, 23, 0, 0, 0))
                entry.compress_type = zipfile.ZIP_DEFLATED
                entry.external_attr = 0o644 << 16
                archive.writestr(entry, path.read_bytes())
        self.check('candidate-build-without-version-bump', version == '2.3.3', version=version,
                   sha256=hashlib.sha256((self.root / 'candidate.zip').read_bytes()).hexdigest())

    def background_fixture(self, mode, skip=False, *arguments):
        """Launch a separate real PHP process for deterministic overlap tests."""
        args = self.cli + ['--url=' + self.config['nat_url']]
        if skip:
            args += ['--skip-plugins=rrze-appointment']
        return subprocess.Popen(args + ['eval-file', str(self.repo / 'tests/readiness/fixture.php'), mode, *map(str, arguments)],
                                env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    def repeat_build(self):
        """Rebuild the same frozen sources and compare the complete ZIP byte digest."""
        previous = hashlib.sha256((self.root / 'candidate.zip').read_bytes()).hexdigest()
        self.build()
        current = hashlib.sha256((self.root / 'candidate.zip').read_bytes()).hexdigest()
        self.check('candidate-build-byte-reproducible', previous == current, previous_sha256=previous, repeated_sha256=current)

    def wait_file(self, name):
        """Wait at most 30 seconds for a specific audit synchronization point."""
        deadline = time.monotonic() + 30
        while not (self.root / name).exists():
            if time.monotonic() > deadline:
                raise RuntimeError('Timed out waiting for ' + name)
            time.sleep(0.02)

    def mail_count(self):
        """Count messages accepted by the local PHPMailer sink."""
        return len(list((self.root / 'mail').glob('*.eml')))

    def seed(self):
        """Create legacy bookings through real 2.3.3 classes and HTTP confirmations."""
        self.restore(name='setup', version='baseline', encrypted=False)
        scenario = self.fixture('seed')
        for name in ('booked', 'cancel', 'optout'):
            response = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens'][name])
            self.check('legacy-confirmation-' + name, response['status'] == 200, status=response['status'])
        scenario = self.fixture('context')
        (self.root / 'scenario.json').write_text(json.dumps(scenario, indent=2))
        (self.root / 'scenario.json').chmod(0o600)
        self.fixture('prepare-cron')
        self.results['environment'] = self.fixture('environment')
        self.results['legacy'] = self.fixture('summary')
        self.check('legacy-baseline-real-bookings', self.results['legacy']['stores']['rrze_appointment_booked_slots']['count'] == 3,
                   mails=self.mail_count())
        self.snapshot('legacy')

    def upgrade(self):
        """Exercise first-request migration, unchanged reads and existing action URLs."""
        self.restore()
        scenario = json.loads((self.root / 'scenario.json').read_text())
        first = self.http(scenario['post_url'])
        state = self.fixture('summary')
        old = self.results['legacy']['stores']
        equal = all(state['stores'].get(name, {}).get('digest') == value['digest'] for name, value in old.items())
        self.check('first-visitor-preserves-all-five-stores', equal and state['phase'] == 'encrypted' and state['completed'] == 5,
                   status=first['status'], seconds=first['seconds'], phase=state['phase'], completed=state['completed'])
        next_state = self.fixture('summary')
        self.check('completed-migration-does-not-rewrite-stores', state['stores'] == next_state['stores'] and state['state_digest'] == next_state['state_digest'])
        self.results['encrypted'] = state
        self.snapshot('encrypted')
        result = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
        duplicate = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
        after = self.fixture('summary')
        self.check('legacy-confirmation-link-after-upgrade', result['status'] == 200 and after['stores']['rrze_appointment_booked_slots']['count'] == 4,
                   status=result['status'], duplicate_status=duplicate['status'])
        optout = self.http(scenario['optout_urls']['optout'])
        self.check('legacy-optout-link-after-upgrade', optout['status'] == 200, status=optout['status'])
        cancellation = self.http(scenario['cancel_urls']['cancel'])
        match = re.search(r'name="rrze_appt_cancel_nonce"[^>]*value="([^"]+)"', cancellation['body'])
        if not match:
            raise RuntimeError('Cancellation page did not contain its expected nonce.')
        cancel = self.http(scenario['cancel_urls']['cancel'], {'rrze_appt_cancel_action': 'cancel', 'rrze_appt_cancel_nonce': match[1], 'cancellation_reason': 'Synthetic test'})
        cancel_twice = self.http(scenario['cancel_urls']['cancel'])
        self.check('legacy-cancellation-link-after-upgrade', cancel['status'] == 200 and cancel_twice['status'] == 410,
                   status=cancel['status'], duplicate_status=cancel_twice['status'])
        private = result['headers'].get('Cache-Control', '')
        self.check('token-response-private', 'no-store' in private, cache_control=private)

    def cron_race(self):
        """Replay a genuine WordPress cron snapshot after the migrator has committed."""
        self.restore()
        for name in ('cron-ready', 'cron-release'):
            (self.root / name).unlink(missing_ok=True)
        writer = self.background_fixture('hold-cron', skip=True)
        self.wait_file('cron-ready')
        migrated = self.fixture('summary')
        late_event = self.fixture('late-cron-event')
        (self.root / 'cron-release').touch()
        stdout, stderr = writer.communicate(timeout=15)
        if writer.returncode:
            raise RuntimeError(stderr)
        after = self.fixture('summary')
        hooks = {'rrze_appointment_send_reminder', 'rrze_appointment_expire_pending', 'rrze_appointment_booking_opened'}
        before_plain = sum(not event['opaque'] for event in migrated['cron'] if event['hook'] in hooks)
        after_plain = sum(not event['opaque'] for event in after['cron'] if event['hook'] in hooks)
        late_retained = any(row['args_digest'] == late_event['args_digest'] for row in after['cron'])
        self.check('foreign-cron-write-cannot-restore-plaintext', after_plain == 0 and late_retained,
                   plaintext_before=before_plain, plaintext_after=after_plain, phase_after=after['phase'], writer_exit=writer.returncode,
                   event_created_after_migration_retained=late_retained)

    def concurrency(self):
        """Verify real locks, concurrent read/modify/write and HTTP slot contention."""
        self.restore(name='encrypted')
        before = self.fixture('summary')['stores']['rrze_appointment_booked_slots']['count']
        workers = [self.background_fixture('add', False, str(i)) for i in range(5)]
        statuses = []
        for worker in workers:
            worker.communicate(timeout=30)
            statuses.append(worker.returncode)
        after = self.fixture('summary')['stores']['rrze_appointment_booked_slots']['count']
        self.check('five-native-concurrent-writes-preserved', statuses == [0] * 5 and after - before == 5,
                   process_statuses=statuses, added=after - before)
        scenario = self.fixture('context')
        data = {'action': 'rrze_appointment_book', 'nonce': scenario['nonce'], 'slot': scenario['slots']['parallel'],
                'post_id': scenario['post_id'], 'block_id': scenario['block_id'], 'booker_name': 'Concurrent synthetic', 'booker_email': 'parallel@example.test'}
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            responses = list(pool.map(lambda _: self.http(self.config['nat_url'] + 'wp-admin/admin-ajax.php', data), range(5)))
        bodies = [json.loads(response['body']) for response in responses]
        successes = sum(body.get('success') is True for body in bodies)
        self.check('five-http-bookings-reserve-slot-once', successes == 1, successes=successes,
                   statuses=[response['status'] for response in responses], seconds=[response['seconds'] for response in responses])
        for name in ('lock-ready', 'lock-release'):
            (self.root / name).unlink(missing_ok=True)
        holder = self.background_fixture('hold-lock')
        self.wait_file('lock-ready')
        started = time.monotonic()
        blocked = self.fixture('summary')
        elapsed = round(time.monotonic() - started, 3)
        (self.root / 'lock-release').touch()
        holder.communicate(timeout=10)
        recovered = self.fixture('summary')
        self.check('native-lock-timeout-and-recovery', bool(blocked['errors']) and not recovered['errors'],
                   blocked_store_count=len(blocked['errors']), seconds=elapsed, recovered=not recovered['errors'])

    def faults(self):
        """Interrupt each important migration boundary and verify durable recovery."""
        cases = [
            ('before-marker', {'match': 'rrze_appointment_storage_state', 'hit': 1, 'mode': 'exit'}),
            ('after-payload-before-checkpoint', {'match': 'rrze_appointment_storage_state', 'hit': 2, 'mode': 'exit'}),
            ('middle-store', {'match': 'rrze_appointment_pending_slots', 'hit': 1, 'mode': 'sql-error'}),
            ('cron-write', {'match': "'cron'", 'hit': 1, 'mode': 'sql-error'}),
            ('lost-lock', {'match': 'rrze_appointment_storage_state', 'hit': 2, 'mode': 'lost-lock'}),
        ]
        for name, fault in cases:
            self.restore()
            (self.root / 'fault.json').write_text(json.dumps(fault))
            response = self.http(self.config['nat_url'])
            fired = (self.root / 'fault-fired').exists()
            (self.root / 'fault.json').unlink()
            recovered = self.fixture('summary')
            equal = all(recovered['stores'].get(key, {}).get('digest') == val['digest'] for key, val in self.results['legacy']['stores'].items())
            self.check('migration-recovers-' + name, fired and equal and recovered['phase'] == 'encrypted',
                       injected=fired, response_status=response['status'], equal=equal, phase=recovered['phase'])

    def recovery(self):
        """Check unavailable keys, missing/corrupt rows and complete snapshot restoration."""
        self.restore(name='encrypted')
        original_key = (self.root / 'key.txt').read_text()
        for name in ('missing', 'wrong', 'invalid'):
            self.restore(name='encrypted')
            if name == 'missing':
                (self.root / 'encryption-enabled').unlink()
            else:
                (self.root / 'key.txt').write_text('QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=' if name == 'wrong' else 'invalid')
            before = self.sql("SELECT option_name,SHA2(option_value,256) FROM wp_2_options WHERE option_name LIKE 'rrze_appointment_%' AND option_name != 'rrze_appointment_settings' ORDER BY option_name")
            failed = self.fixture('summary')
            after = self.sql("SELECT option_name,SHA2(option_value,256) FROM wp_2_options WHERE option_name LIKE 'rrze_appointment_%' AND option_name != 'rrze_appointment_settings' ORDER BY option_name")
            (self.root / 'key.txt').write_text(original_key)
            (self.root / 'encryption-enabled').touch()
            restored = self.fixture('summary')
            self.check('key-error-' + name, len(failed['errors']) == 5 and before == after and not restored['errors'],
                       blocked_stores=len(failed['errors']), unchanged=before == after, recovered=not restored['errors'])
        for name, mutation in (
            ('corrupt-payload', "UPDATE wp_2_options SET option_value=CONCAT(option_value,'corruption') WHERE option_name='rrze_appointment_booked_slots_meta'"),
            ('missing-row', "DELETE FROM wp_2_options WHERE option_name='rrze_appointment_booked_slots_meta'"),
            ('corrupt-state', "UPDATE wp_2_options SET option_value='{}' WHERE option_name='rrze_appointment_storage_state'")):
            self.restore(name='encrypted')
            self.sql(mutation)
            state = self.fixture('summary')
            self.check(name + '-blocks-access', len(state['errors']) == 5, blocked_stores=len(state['errors']))
        self.restore(name='encrypted')
        state = self.fixture('summary')
        equal = state['stores'] == self.results['encrypted']['stores']
        self.check('complete-database-and-key-restore', equal and not state['errors'], identical_stores=equal)

    def network(self):
        """Exercise dormant sites, an isolated corrupt site and an accelerated retry pass."""
        self.restore(version='baseline', encrypted=False)
        self.fixture('network-seed')
        self.stop()
        self.install('candidate')
        (self.root / 'encryption-enabled').touch()
        first = self.fixture_on(self.config['url'] + '/', 'network')
        statuses = {}
        for site in (2, 3, 4, 5):
            value = self.sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(option_value,'$.phase')) FROM wp_" + str(site) + "_options WHERE option_name='rrze_appointment_storage_state'")
            statuses[str(site)] = value or 'not-started'
        self.check('network-isolates-failed-and-dormant-sites', first.get('failed') == 1 and statuses['3'] == 'encrypted' and statuses['5'] == 'not-started',
                   progress=first, statuses=statuses)
        self.sql("UPDATE wp_4_options SET option_value='a:0:{}' WHERE option_name='rrze_appointment_booked_slots_meta'")
        second = self.fixture_on(self.config['url'] + '/', 'network-retry')
        self.check('network-retries-repaired-site', second.get('failed') == 0 and second.get('encrypted') == 4,
                   progress=second, retry_clock='next_batch advanced by test fixture')

    def mail_and_routes(self):
        """Exercise new HTTP bookings, real MIME attachments, opening links and REST access."""
        self.restore(name='encrypted')
        scenario = self.fixture('context')
        before = set((self.root / 'mail').glob('*.eml'))
        response = self.http(self.config['nat_url'] + 'wp-admin/admin-ajax.php', {
            'action': 'rrze_appointment_book', 'nonce': scenario['nonce'], 'slot': scenario['slots']['new'],
            'post_id': scenario['post_id'], 'block_id': scenario['block_id'], 'booker_name': 'New synthetic',
            'booker_email': 'new@example.test'})
        success = json.loads(response['body']).get('success') is True
        token = self.fixture('pending-token', scenario['slots']['new'])['token']
        confirmation = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + (token or 'missing'))
        messages = [BytesParser(policy=policy.default).parsebytes(path.read_bytes()) for path in set((self.root / 'mail').glob('*.eml')) - before]
        calendar = [part for message in messages for part in message.walk() if part.get_content_type() == 'text/calendar']
        valid = all(b'BEGIN:VCALENDAR' in part.get_payload(decode=True) for part in calendar)
        recipients = sorted(str(message['To']) for message in messages)
        self.check('new-http-booking-confirmation-and-calendar', success and token is not None and confirmation['status'] == 200 and len(messages) == 3 and len(calendar) == 2 and valid,
                   request_status=response['status'], confirmation_status=confirmation['status'], messages=len(messages), calendar_parts=len(calendar), valid_calendars=valid, recipients=recipients)
        before = self.mail_count()
        dispatched = self.fixture('dispatch', 'rrze_appointment_send_reminder')
        reminder_mails = self.mail_count() - before
        self.check('migrated-reminder-jobs-send-mail', dispatched['dispatched'] == 3 and reminder_mails == 6,
                   dispatched=dispatched['dispatched'], messages=reminder_mails)
        self.fixture('opening-ready')
        before = self.mail_count()
        self.fixture('dispatch', 'rrze_appointment_booking_opened')
        opened_mails = self.mail_count() - before
        claim = self.http(self.config['nat_url'] + '?rrze_appt_opening=' + scenario['opening']['token'])
        self.check('legacy-opening-job-and-link', opened_mails == 1 and claim['status'] == 200,
                   messages=opened_mails, claim_status=claim['status'])
        anonymous = self.fixture('rest', 'anonymous')
        admin = self.fixture('rest', 'admin')
        self.check('management-rest-default-access', anonymous['status'] in (401, 403) and admin['status'] == 200,
                   anonymous=anonymous, admin=admin)

    def load(self):
        """Measure local synthetic first migration and a small post-update visitor burst."""
        for count in (0, 1000, 10000):
            self.restore(version='baseline', encrypted=False)
            if count:
                self.fixture('grow', count)
            before = self.fixture('summary')
            self.stop()
            self.install('candidate')
            (self.root / 'encryption-enabled').touch()
            self.start()
            scenario = json.loads((self.root / 'scenario.json').read_text())
            first = self.http(scenario['post_url'])
            metrics = [json.loads(line) for line in (self.root / 'request-metrics.jsonl').read_text().splitlines()]
            http_metrics = next(row for row in reversed(metrics) if row['path'] == urllib.parse.urlparse(scenario['post_url']).path)
            after = self.fixture('summary')
            equal = all(after['stores'].get(name, {}).get('digest') == row['digest'] for name, row in before['stores'].items())
            self.check('local-first-migration-extra-' + str(count), equal and first['status'] == 200 and after['phase'] == 'encrypted',
                       seconds=first['seconds'], peak_cli_bytes=after['peak_memory'], http_metrics=http_metrics, original_store_bytes=sum(row['bytes'] for row in before['stores'].values()), http_status=first['status'])
            if count == 0:
                with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
                    responses = list(pool.map(lambda _: self.http(scenario['post_url']), range(5)))
                self.check('five-ordinary-pages-under-small-load', all(row['status'] == 200 for row in responses),
                           statuses=[row['status'] for row in responses], seconds=[row['seconds'] for row in responses])

    def native_smoke(self):
        """Run the existing native MySQL conditional-write and two-connection smoke test."""
        output = self.wp(['eval-file', str(self.repo / 'tests/fixtures/native-option-database.php')], skip=True)
        self.check('native-mysql-smoke', 'passed' in output, result=output)

    def cron_load(self):
        """Migrate a synthetic large cron queue while preserving every event timestamp."""
        self.restore(version='baseline', encrypted=False)
        self.fixture('grow', 10000)
        created = self.fixture('grow-cron')
        before = self.fixture('summary')
        self.stop()
        self.install('candidate')
        (self.root / 'encryption-enabled').touch()
        self.start()
        scenario = json.loads((self.root / 'scenario.json').read_text())
        response = self.http(scenario['post_url'])
        after = self.fixture('summary')
        shape_before = sorted((row['time'], row['hook']) for row in before['cron'])
        shape_after = sorted((row['time'], row['hook']) for row in after['cron'])
        personal_hooks = {'rrze_appointment_send_reminder', 'rrze_appointment_expire_pending', 'rrze_appointment_booking_opened'}
        opaque = all(row['opaque'] for row in after['cron'] if row['hook'] in personal_hooks)
        metrics = [json.loads(line) for line in (self.root / 'request-metrics.jsonl').read_text().splitlines()]
        http_metrics = next(row for row in reversed(metrics) if row['path'] == urllib.parse.urlparse(scenario['post_url']).path)
        audit_equal = all(after['stores'].get(name, {}).get('digest') == row['digest'] for name, row in before['stores'].items())
        self.check('large-cron-queue-migrates-without-event-loss', response['status'] == 200 and shape_before == shape_after and opaque and audit_equal,
                   jobs_added=created['jobs_added'], seconds=response['seconds'], events_before=len(shape_before), events_after=len(shape_after),
                   all_personal_arguments_opaque=opaque, http_metrics=http_metrics)

    def mixed_versions(self):
        """Demonstrate an undrained legacy page overwriting already migrated data."""
        self.restore(version='baseline', encrypted=False)
        self.fixture('expire-one')
        for name in ('legacy-ready', 'legacy-release'):
            (self.root / name).unlink(missing_ok=True)
        (self.root / 'legacy-arm').touch()
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            old_request = pool.submit(self.http, self.config['nat_url'] + '?audit_hold_legacy=1')
            self.wait_file('legacy-ready')
            self.install('candidate')
            (self.root / 'encryption-enabled').touch()
            migrated = self.fixture('summary')
            (self.root / 'legacy-release').touch()
            response = old_request.result(timeout=40)
        (self.root / 'legacy-arm').unlink()
        format_after = self.sql("SELECT IF(option_value LIKE 'rrze-appointment:encrypted:%','encrypted','plaintext') FROM wp_2_options WHERE option_name='rrze_appointment_pending_slots'")
        state = self.fixture('summary')
        self.check('undrained-legacy-page-cannot-overwrite-migration', format_after == 'encrypted' and not state['errors'],
                   phase_before=migrated['phase'], format_after=format_after, blocked_stores=len(state['errors']), old_request_status=response['status'])

    def slow_mail(self):
        """Measure whether slow synchronous mail blocks unrelated ordinary pages."""
        self.restore(name='encrypted')
        scenario = self.fixture('context')
        (self.root / 'mail-delay').write_text('7')
        (self.root / 'mail-entered').unlink(missing_ok=True)
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            booking = pool.submit(self.http, self.config['nat_url'] + 'wp-admin/admin-ajax.php', {
                'action': 'rrze_appointment_book', 'nonce': scenario['nonce'], 'slot': scenario['slots']['new'],
                'post_id': scenario['post_id'], 'block_id': scenario['block_id'],
                'booker_name': 'Slow synthetic', 'booker_email': 'slow@example.test'})
            self.wait_file('mail-entered')
            page = self.http(scenario['post_url'])
            response = booking.result(timeout=30)
        (self.root / 'mail-delay').unlink()
        self.check('ordinary-page-during-slow-mail', page['status'] == 200 and page['seconds'] < 5,
                   page_status=page['status'], page_seconds=page['seconds'], booking_seconds=response['seconds'], simulated_transport_seconds=7)

    def first_concurrent(self):
        """Make visitors and booking requests compete for the very first migration."""
        self.restore()
        scenario = json.loads((self.root / 'scenario.json').read_text())
        data = {'action': 'rrze_appointment_book', 'nonce': scenario['nonce'], 'slot': scenario['slots']['parallel'],
                'post_id': scenario['post_id'], 'block_id': scenario['block_id'], 'booker_name': 'First concurrent',
                'booker_email': 'first-concurrent@example.test'}
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            page_futures = [pool.submit(self.http, scenario['post_url']) for _ in range(2)]
            booking_futures = [pool.submit(self.http, self.config['nat_url'] + 'wp-admin/admin-ajax.php', data) for _ in range(3)]
            pages = [future.result() for future in page_futures]
            bookings = [future.result() for future in booking_futures]
        successes = sum(json.loads(response['body']).get('success') is True for response in bookings)
        state = self.fixture('summary')
        retained = state['stores']['rrze_appointment_booked_slots']['digest'] == self.results['legacy']['stores']['rrze_appointment_booked_slots']['digest']
        self.check('simultaneous-first-visitors-and-bookings', successes == 1 and retained and state['phase'] == 'encrypted' and all(page['status'] == 200 for page in pages),
                   successful_reservations=successes, existing_bookings_retained=retained,
                   page_statuses=[page['status'] for page in pages], seconds=[item['seconds'] for item in pages + bookings])

    def confirmation_failure(self):
        """Compare fresh old confirmation links using a native server-side SQL fault."""
        for version in ('baseline', 'candidate'):
            self.restore(version='baseline', encrypted=False)
            scenario = self.fixture('fresh-pending')
            if version == 'candidate':
                self.stop()
                self.install('candidate')
                (self.root / 'encryption-enabled').touch()
                self.start()
                self.fixture('summary')
            if not self.fixture('confirmation-fault-install', skip=True)['installed']:
                raise RuntimeError('The synthetic confirmation fault could not be installed.')
            try:
                response = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
                fired = self.fixture('confirmation-fault-check', skip=True)['fired'] == 1
            finally:
                self.fixture('confirmation-fault-remove', skip=True)
            token = self.fixture('pending-token', scenario['slots']['confirm'])['token']
            state = self.fixture('summary')
            booked = state['stores']['rrze_appointment_booked_slots']['count'] == 4
            if version == 'baseline':
                self.check('legacy-confirmation-loss-reproduced', fired and token is None and not booked,
                    injected=fired, http_status=response['status'], negative_control=True)
            else:
                retry = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
                duplicate = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
                self.check('confirmation-write-failure-recoverable-candidate', fired and token is not None and not booked
                    and response['status'] == 500 and retry['status'] == 200 and duplicate['status'] == 410,
                    injected=fired, pending_retained=token is not None, retry_status=retry['status'], duplicate_status=duplicate['status'])

    def confirmation_delivery_failure(self):
        """Ensure a mail failure reports a saved booking without enabling duplicate confirmation."""
        self.restore(version='baseline', encrypted=False)
        scenario = self.fixture('fresh-pending')
        self.stop()
        self.install('candidate')
        (self.root / 'encryption-enabled').touch()
        (self.root / 'mail-fail').touch()
        self.start()
        response = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
        (self.root / 'mail-fail').unlink()
        state = self.fixture('summary')
        duplicate = self.http(self.config['nat_url'] + '?rrze_appt_confirm=' + scenario['tokens']['confirm'])
        self.check('confirmation-mail-failure-keeps-committed-booking', response['status'] == 500
            and 'Your appointment is booked' in response['body']
            and state['stores']['rrze_appointment_booked_slots']['count'] == 4 and duplicate['status'] == 410,
            status=response['status'], duplicate_status=duplicate['status'], booked_count=state['stores']['rrze_appointment_booked_slots']['count'])

    def atomic(self):
        """Run the current native transaction suite, including legacy and mixed modes."""
        self.mixed_atomic()

    def run_maintenance(self, mode, network=True):
        """Invoke the installed CLI helper with normal plugins and themes skipped."""
        command = self.cli + ['--url=' + self.config['nat_url'], '--skip-plugins', 'eval-file',
            str(self.site / 'wp-content/plugins/rrze-appointment/bin/storage-maintenance.php'), mode]
        if network:
            command.append('network')
        result = subprocess.run(command, env=self.env, capture_output=True, text=True, timeout=120)
        rows = [json.loads(line) for line in result.stdout.splitlines() if line.startswith('{')]
        return result.returncode, rows

    def maintenance_fingerprint(self):
        """Hash protected storage and cron directly, without executing plugin startup."""
        rows = []
        for site in range(1, 6):
            table = 'wp_options' if site == 1 else 'wp_' + str(site) + '_options'
            rows.append(self.sql("SELECT option_name,SHA2(option_value,256),autoload FROM " + table
                + " WHERE option_name LIKE 'rrze_appointment_%' OR option_name='cron' ORDER BY option_name"))
        return rows

    def maintenance(self):
        """Verify non-mutating checks, all-site preflight and drained legacy cron writers."""
        self.restore(version='baseline', encrypted=False)
        self.fixture('network-seed')
        self.stop()
        self.install('candidate')
        (self.root / 'encryption-enabled').touch()
        before = self.maintenance_fingerprint()
        preflight_code, preflight = self.run_maintenance('preflight')
        migrate_code, blocked = self.run_maintenance('migrate')
        self.check('maintenance-preflight-blocks-before-any-migration', preflight_code != 0 and migrate_code != 0
            and before == self.maintenance_fingerprint(), preflight=preflight, migrate=blocked)
        self.sql("UPDATE wp_4_options SET option_value='a:0:{}' WHERE option_name='rrze_appointment_booked_slots_meta'")
        before = self.maintenance_fingerprint()
        code, rows = self.run_maintenance('preflight')
        self.check('maintenance-preflight-is-read-only', code == 0 and before == self.maintenance_fingerprint(), results=rows)
        for name in ('cron-ready', 'cron-release'):
            (self.root / name).unlink(missing_ok=True)
        writer = self.background_fixture('hold-cron', True)
        self.wait_file('cron-ready')
        (self.root / 'cron-release').touch()
        writer.communicate(timeout=15)
        if writer.returncode:
            raise RuntimeError('The synthetic legacy cron writer did not drain.')
        late = self.fixture('late-cron-event', skip=True)
        code, rows = self.run_maintenance('migrate')
        self.check('maintenance-migrates-active-and-dormant-sites', code == 0
            and any(row.get('phase') == 'verify' and row.get('checked') == 4 and row.get('skipped') == 1 and row.get('failed') == 0 for row in rows), results=rows)
        before = self.maintenance_fingerprint()
        verify_code, verified = self.run_maintenance('verify')
        repeat_code, repeated = self.run_maintenance('migrate')
        self.check('maintenance-verification-and-repeat-do-not-write', verify_code == 0 and repeat_code == 0
            and before == self.maintenance_fingerprint(), verification=verified)
        state = self.fixture('summary')
        hooks = {'rrze_appointment_send_reminder', 'rrze_appointment_expire_pending', 'rrze_appointment_booking_opened'}
        self.check('drained-foreign-cron-writer-cannot-replay-legacy-snapshot', all(event['opaque'] for event in state['cron'] if event['hook'] in hooks)
            and any(row['args_digest'] == late['args_digest'] for row in state['cron']))
        self.fixture('prepare-cron')
        before = self.maintenance_fingerprint()
        code, rows = self.run_maintenance('verify')
        self.check('maintenance-detects-reintroduced-plaintext-without-repair', code != 0 and before == self.maintenance_fingerprint(), results=rows)

    def sso_boundary(self):
        """Verify default access and fail-closed behavior when optional SSO dependencies are absent."""
        self.restore(name='encrypted')
        before = self.fixture('rest', 'admin')
        anonymous = self.fixture('rest', 'anonymous')
        self.check('management-rest-default-access', anonymous['status'] in (401, 403) and before['status'] == 200,
                   anonymous=anonymous, admin=before)
        self.fixture('sso-required')
        protected = self.fixture('rest', 'admin')
        scenario = json.loads((self.root / 'scenario.json').read_text())
        public = self.http(scenario['post_url'])
        self.check('missing-sso-dependency-fails-closed', before['status'] == 200 and protected['status'] in (401, 403, 503) and public['status'] == 200,
                   default_admin_status=before['status'], protected_admin_status=protected['status'], public_status=public['status'])

    def preview(self):
        """Restore a representative state and expose the isolated site for browser inspection."""
        self.restore(name='encrypted')
        print('Browser preview: ' + self.config['nat_url'], flush=True)

    def baseline_preview(self):
        """Restore the unencrypted baseline for a controlled browser comparison."""
        self.restore(version='baseline', encrypted=False)
        print('Legacy browser preview: ' + self.config['nat_url'], flush=True)

    def invalidate_session(self):
        """Revoke only synthetic administrator sessions for the browser boundary check."""
        self.fixture('invalidate-session')

    def rollout(self):
        """Run the isolated local deployment rehearsal with a separate request gate."""
        from rollout import run_rollout
        run_rollout(self)

    def additional(self):
        """Exercise final outage, expiration, cron-retry and mixed-operation scenarios."""
        from additional import run_additional
        run_additional(self)

    def notification_cycle(self):
        """Run actual WordPress cron dispatch and an accelerated local calendar-day boundary."""
        scenario = json.loads((self.root / 'scenario.json').read_text())
        appointment_date = date.fromisoformat(scenario['slots']['booked'].split(' ')[0])
        prior_date = appointment_date - timedelta(days=1)
        outcomes = {}
        for version in ('baseline', 'candidate'):
            self.restore(name='legacy' if version == 'baseline' else 'encrypted', version=version, encrypted=version == 'candidate')
            (self.root / 'notification-clock').write_text(str(prior_date) + 'T23:59:00')
            before = self.mail_count()
            self.wp(['cron', 'event', 'run', 'rrze_appointment_send_reminder'])
            single = self.mail_count() - before
            before = self.mail_count()
            self.wp(['cron', 'event', 'run', 'rrze_appointment_daily_check'])
            daily = self.mail_count() - before
            (self.root / 'notification-clock').write_text(str(appointment_date) + 'T00:01:00')
            before = self.mail_count()
            self.wp(['cron', 'event', 'run', 'rrze_appointment_daily_check'])
            next_day = self.mail_count() - before
            outcomes[version] = dict(single_event_messages=single, same_day_fallback_messages=daily, next_day_messages=next_day)
        (self.root / 'notification-clock').unlink(missing_ok=True)
        self.check('daily-cycle-and-midnight-baseline-parity', outcomes['baseline'] == outcomes['candidate'] and outcomes['candidate']['single_event_messages'] == 6 and outcomes['candidate']['next_day_messages'] == 0,
                   outcomes=outcomes, clock='notification calendar accelerated; system clock unchanged')
        self.check('reminder-single-and-daily-do-not-duplicate', outcomes['candidate']['same_day_fallback_messages'] == 0,
                   baseline_duplicate_messages=outcomes['baseline']['same_day_fallback_messages'], candidate_duplicate_messages=outcomes['candidate']['same_day_fallback_messages'])

    def shutdown(self):
        """Stop only recorded audit PHP workers and the isolated MySQL socket server."""
        self.stop()
        self.command([str(Path(self.config['mysql_bin']) / 'mysqladmin'), '--no-defaults',
                      '--socket=' + self.config['socket'], '--user=root', 'shutdown'])
        print('Audit services stopped; evidence and synthetic snapshots retained.', flush=True)

    def run(self, phases, keep_server=False):
        """Execute independent checks and persist unexpected harness errors separately."""
        if any(phase not in (*PHASES, 'preview', 'baseline_preview', 'invalidate_session', 'shutdown') for phase in phases):
            raise RuntimeError('Unknown phase. Use only the documented audit phases.')
        try:
            for phase in phases:
                method = getattr(self, phase)
                try:
                    method()
                    if any(row['name'] == method.__name__ + '-harness-completion' for row in self.results['checks']):
                        self.check(method.__name__ + '-harness-completion', True, recovered=True)
                except Exception as error:
                    self.check(method.__name__ + '-harness-completion', False, error=str(error)[-2000:])
                    if phase in ('build', 'seed'):
                        raise
        finally:
            if not keep_server:
                self.stop()
            self.save()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True)
    parser.add_argument('--phases', help='Comma-separated checks; mixed candidates default to their staging acceptance suite.')
    parser.add_argument('--keep-server', action='store_true')
    arguments = parser.parse_args()
    audit = Audit(arguments.root)
    selected = (arguments.phases.split(',') if arguments.phases else
                MIXED_PHASES if (audit.root / 'candidate/includes/Storage/MixedRecordStore.php').is_file()
                else tuple(phase for phase in PHASES if phase not in (*MIXED_PHASES[2:], 'working_tree')))
    audit.run(selected, arguments.keep_server)
    if any(phase in PHASES for phase in selected):
        sys.exit(1 if any(row['status'] == 'fail' for row in audit.results['checks']) else 0)
