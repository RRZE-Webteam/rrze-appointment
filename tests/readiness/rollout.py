"""Rehearse a local drain with a public HTML cache and explicit private-route gate."""

import concurrent.futures
import http.server
import json
import threading
import time
import urllib.error
import urllib.parse
import urllib.request


def local_request(url, data=None):
    """Send a loopback-only probe and return its status and optional JSON body."""
    if urllib.parse.urlsplit(url).hostname != '127.0.0.1':
        raise RuntimeError('The rollout probe only permits loopback URLs.')
    request = urllib.request.Request(url, data=None if data is None else urllib.parse.urlencode(data).encode())
    try:
        response = urllib.request.urlopen(request, timeout=40)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read()


def run_rollout(audit):
    """Drain a real old request, switch versions and check public/private traffic separately."""
    audit.restore(version='baseline', encrypted=False)
    scenario = audit.fixture('context')
    cached = audit.http(scenario['post_url'])['body'].encode()
    public_path = urllib.parse.urlsplit(scenario['post_url']).path
    gate = threading.Event()

    class ProbeHandler(http.server.BaseHTTPRequestHandler):
        """Model an external deployment gate; never cache personal or query responses."""

        def log_message(self, *arguments):
            """Keep request URLs and synthetic action tokens out of console output."""

        def do_GET(self):
            """Serve the prevalidated public page while PHP is being drained."""
            self.dispatch()

        def do_POST(self):
            """Reject mutations during the brief deployment gate."""
            self.dispatch()

        def dispatch(self):
            """Use an exact public path allowlist; proxy everything only outside the gate."""
            if gate.is_set():
                public = self.command == 'GET' and self.path == public_path
                status, body = (200, cached) if public else (503, b'Synthetic booking pause')
                self.send_response(status)
                self.send_header('Cache-Control', 'no-store' if not public else 'public, max-age=0')
                self.end_headers()
                self.wfile.write(body)
                return
            body = self.rfile.read(int(self.headers.get('Content-Length', 0))) if self.command == 'POST' else None
            request = urllib.request.Request(audit.config['url'] + self.path, data=body)
            try:
                response = urllib.request.urlopen(request, timeout=40)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                self.send_response(response.status)
                self.end_headers()
                self.wfile.write(response.read())

    proxy = http.server.ThreadingHTTPServer(('127.0.0.1', 0), ProbeHandler)
    thread = threading.Thread(target=proxy.serve_forever, daemon=True)
    thread.start()
    frontend = 'http://127.0.0.1:' + str(proxy.server_port)
    data = {'action': 'rrze_appointment_book', 'nonce': scenario['nonce'], 'slot': scenario['slots']['new'],
            'post_id': scenario['post_id'], 'block_id': scenario['block_id'],
            'booker_name': 'Rollout synthetic', 'booker_email': 'rollout@example.test'}
    (audit.root / 'mail-delay').write_text('2')
    (audit.root / 'mail-entered').unlink(missing_ok=True)
    samples = []
    finished = threading.Event()

    def watch_public():
        """Record public availability throughout the controlled deployment gate."""
        while not finished.is_set():
            samples.append(local_request(frontend + public_path)[0])
            time.sleep(0.05)

    for name in ('cron-ready', 'cron-release'):
        (audit.root / name).unlink(missing_ok=True)
    foreign_writer = audit.background_fixture('hold-cron', True)
    audit.wait_file('cron-ready')
    try:
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            old = pool.submit(local_request, frontend + '/nat/wp-admin/admin-ajax.php', data)
            audit.wait_file('mail-entered')
            started = time.monotonic()
            gate.set()
            watcher = threading.Thread(target=watch_public, daemon=True)
            watcher.start()
            private_status = local_request(frontend + '/nat/?rrze_appt_confirm=synthetic')[0]
            old_status, old_body = old.result(timeout=20)
            (audit.root / 'cron-release').touch()
            foreign_writer.communicate(timeout=15)
            if foreign_writer.returncode:
                raise RuntimeError('The foreign cron writer did not drain.')
            audit.stop()
            (audit.root / 'mail-delay').unlink()
            audit.install('candidate')
            (audit.root / 'encryption-enabled').touch()
            code, maintenance = audit.run_maintenance('migrate')
            if code:
                raise RuntimeError('Network migration failed while the deployment gate remained closed.')
            audit.start()
            initial = audit.http(scenario['post_url'])
            state = audit.fixture('summary')
            gate.clear()
            data['slot'] = scenario['slots']['parallel']
            new_status, new_body = local_request(frontend + '/nat/wp-admin/admin-ajax.php', data)
            elapsed = round(time.monotonic() - started, 3)
            finished.set()
            watcher.join(timeout=5)
            old_success = json.loads(old_body).get('success') is True
            new_success = json.loads(new_body).get('success') is True
            audit.check('local-drained-rollout-under-60-seconds', elapsed <= 60 and all(status == 200 for status in samples)
                        and old_success and new_success and private_status == 503 and state['phase'] == 'encrypted'
                        and any(row.get('phase') == 'verify' and row.get('checked') == 5 and row.get('failed') == 0 for row in maintenance),
                        seconds=elapsed, network_maintenance=maintenance, foreign_cron_drained=True, public_samples=len(samples), public_statuses=sorted(set(samples)),
                        private_status=private_status, old_booking_success=old_success, new_booking_success=new_success,
                        first_new_page_status=initial['status'], opcache='disabled in local CLI server',
                        public_delivery='exact-path cached HTML; real hosting cache and static assets not certified')
    finally:
        (audit.root / 'cron-release').touch()
        foreign_writer.communicate(timeout=15)
        finished.set()
        proxy.shutdown()
        proxy.server_close()
