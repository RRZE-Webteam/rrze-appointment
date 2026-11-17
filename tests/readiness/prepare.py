#!/usr/bin/env python3
"""Prepare a disposable, synthetic multisite without booting the source plugin."""

import argparse
import hashlib
import base64
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tarfile
import io
import time


def command(arguments, cwd=None, env=None):
    """Run a setup command, retaining diagnostics but never printing credentials."""
    result = subprocess.run(arguments, cwd=cwd, env=env, capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError(result.stderr[-3000:] or result.stdout[-3000:])
    return result.stdout.strip()


def php_string(value):
    """Encode a configuration value as a PHP single-quoted literal."""
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def archive_revision(repo, revision, destination):
    """Extract a verified Git tree into a new disposable directory."""
    destination.mkdir(parents=True)
    archive = subprocess.check_output(["git", "archive", revision], cwd=repo)
    with tarfile.open(fileobj=io.BytesIO(archive)) as files:
        for member in files.getmembers():
            if member.issym() or member.islnk() or not (destination / member.name).resolve().is_relative_to(destination.resolve()):
                raise RuntimeError('The frozen Git tree contains an unsupported archive entry.')
        files.extractall(destination)



def snapshot_working_tree(repo, destination):
    """Freeze tracked and non-ignored source files, with a reproducible content digest."""
    destination.mkdir()
    names = subprocess.check_output(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], cwd=repo).decode().split('\0')
    digest = hashlib.sha256()
    for name in sorted(set(filter(None, names))):
        source = repo / name
        if not source.exists():
            continue
        if source.is_symlink() or not source.resolve().is_relative_to(repo.resolve()):
            raise RuntimeError('Unsupported link in working-tree snapshot.')
        if source.is_file():
            data = source.read_bytes()
            digest.update(name.encode() + b'\0' + hashlib.sha256(data).digest())
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
    (destination.parent / 'candidate-source.txt').write_text('working-tree:' + digest.hexdigest())


def finish_setup(args):
    """Complete an installed, guarded audit network without recreating its database."""
    root = Path(args.root).resolve()
    site = root / 'wordpress'
    repo = Path(__file__).resolve().parents[2]
    config_file = site / 'wp-config.php'
    config = config_file.read_text()
    if php_string(root) not in config or "rrze_appt_audit_" not in config:
        raise RuntimeError('Refusing to finish an installation without the audit guard.')
    url = 'http://127.0.0.1:' + str(args.port)
    if "define('MULTISITE'" not in config:
        network = "define('MULTISITE', true);\ndefine('SUBDOMAIN_INSTALL', false);\n"
        network += "define('DOMAIN_CURRENT_SITE', '127.0.0.1:" + str(args.port) + "');\n"
        network += "define('PATH_CURRENT_SITE', '/');\ndefine('SITE_ID_CURRENT_SITE', 1);\ndefine('BLOG_ID_CURRENT_SITE', 1);\n"
        config_file.write_text(config.replace("require_once ABSPATH", network + "require_once ABSPATH"))
    env = dict(os.environ, PATH=args.mysql_bin + os.pathsep + os.environ['PATH'])
    cli = [args.php, args.wp, '--path=' + str(site), '--skip-themes']
    paths = command(cli + ['site', 'list', '--field=path'], env=env).splitlines()
    for slug in ('nat', 'dormant', 'broken', 'empty'):
        if '/' + slug + '/' not in paths:
            command(cli + ['site', 'create', '--slug=' + slug, '--title=Audit ' + slug, '--email=audit@example.test'], env=env)
    command(cli + ['theme', 'enable', 'FAU-Elemental', '--network'], env=env)
    command(cli + ['theme', 'activate', 'FAU-Elemental', '--url=' + url + '/nat/'], env=env)
    command(cli + ['plugin', 'activate', 'rrze-appointment', '--network'], env=env)
    database = command(cli + ['config', 'get', 'DB_NAME', '--type=constant'], env=env)
    manifest = dict(root=str(root), wordpress=str(site), repo=str(repo), database=database, php=args.php, wp=args.wp,
        mysql_bin=args.mysql_bin, mysqld=args.mysqld, mysql_pid=int((root / 'mysql.pid').read_text()), socket=str(root / 'mysql.sock'),
        url=url, nat_url=url + '/nat/', port=args.port,
        baseline=command(['git', 'rev-parse', args.baseline], cwd=repo),
        candidate=(root / 'candidate-source.txt').read_text() if args.working_tree else command(['git', 'rev-parse', args.candidate], cwd=repo))
    (root / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    command(cli + ['db', 'export', str(root / 'setup.sql'), '--single-transaction', '--add-drop-table'], env=env)
    (root / 'setup.sql').chmod(0o600)
    print(json.dumps(manifest, indent=2))


def prepare(args):
    """Create a fresh test database, WordPress installation and frozen source trees."""
    root = Path(args.root).resolve()
    if root.exists() and any(root.iterdir()):
        raise RuntimeError("The audit root must be new; existing databases are never reset by setup.")
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    repo = Path(__file__).resolve().parents[2]
    source = Path(args.source).resolve()
    php = args.php
    wp = args.wp
    env = dict(os.environ, PATH=args.mysql_bin + os.pathsep + os.environ["PATH"])
    data = root / 'mysql-data'
    socket = root / 'mysql.sock'
    server = Path(args.mysqld)
    command([str(server), '--no-defaults', '--basedir=' + str(server.parents[1]),
             '--datadir=' + str(data), '--initialize-insecure'])
    log = open(root / 'mysql-process.log', 'w')
    process = subprocess.Popen([str(server), '--no-defaults', '--basedir=' + str(server.parents[1]),
        '--datadir=' + str(data), '--socket=' + str(socket), '--skip-networking',
        '--pid-file=' + str(root / 'mysql.pid'), '--log-error=' + str(root / 'mysql.log'),
        '--innodb-buffer-pool-size=64M', '--max-allowed-packet=128M', '--explicit_defaults_for_timestamp=1'],
        stdout=log, stderr=log, start_new_session=True)
    for _ in range(200):
        if socket.exists():
            break
        if process.poll() is not None:
            raise RuntimeError('The isolated MySQL process stopped; inspect mysql.log.')
        time.sleep(0.1)
    else:
        raise RuntimeError('The isolated MySQL socket did not become ready.')
    credentials = dict(DB_USER='root', DB_PASSWORD='', DB_HOST='localhost:' + str(socket))
    database = "rrze_appt_audit_" + secrets.token_hex(6)
    command([str(Path(args.mysql_bin) / 'mysql'), '--no-defaults', '--socket=' + str(socket),
        '--user=root', '--execute=CREATE DATABASE `' + database + '` CHARACTER SET utf8mb4;'])
    site = root / "wordpress"
    site.mkdir()
    ignore = shutil.ignore_patterns("node_modules", ".git", ".DS_Store")
    for name in ("wp-admin", "wp-includes"):
        shutil.copytree(source / name, site / name, ignore=ignore)
    for item in source.iterdir():
        if item.is_file() and item.suffix == ".php" and item.name != "wp-config.php":
            shutil.copy2(item, site / item.name)
    for name in ("plugins", "themes", "mu-plugins", "uploads"):
        (site / "wp-content" / name).mkdir(parents=True)
    shutil.copytree(source / "wp-content/themes/FAU-Elemental", site / "wp-content/themes/FAU-Elemental", ignore=ignore)
    shutil.copytree(source / "wp-content/themes/twentytwentyfive", site / "wp-content/themes/twentytwentyfive", ignore=ignore)
    archive_revision(repo, args.baseline, root / "baseline")
    if args.working_tree:
        snapshot_working_tree(repo, root / "candidate")
    else:
        archive_revision(repo, args.candidate, root / "candidate")
    shutil.copytree(root / "baseline", site / "wp-content/plugins/rrze-appointment")
    shutil.copy2(repo / "tests/readiness/audit-mu.php", site / "wp-content/mu-plugins/audit.php")
    (root / "mail").mkdir()
    (root / "key.txt").write_text(base64.b64encode(secrets.token_bytes(32)).decode())
    (root / "key.txt").chmod(0o600)
    config = "<?php\n"
    for name, value in dict(credentials, DB_NAME=database, DB_CHARSET="utf8mb4", DB_COLLATE="").items():
        config += "define(" + php_string(name) + ", " + php_string(value) + ");\n"
    for name in ("AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT"):
        config += "define(" + php_string(name) + ", " + php_string(secrets.token_hex(32)) + ");\n"
    config += "define('RRZE_APPOINTMENT_AUDIT_ROOT', " + php_string(root) + ");\n"
    config += "if (is_file(RRZE_APPOINTMENT_AUDIT_ROOT . '/encryption-enabled')) { define('RRZE_APPOINTMENT_ENCRYPTION_KEY', trim(file_get_contents(RRZE_APPOINTMENT_AUDIT_ROOT . '/key.txt'))); }\n"
    config += "define('DISABLE_WP_CRON', true);\ndefine('WP_HTTP_BLOCK_EXTERNAL', true);\ndefine('WP_DEBUG', true);\ndefine('WP_DEBUG_DISPLAY', false);\n"
    config += "define('WP_DEBUG_LOG', RRZE_APPOINTMENT_AUDIT_ROOT . '/debug.log');\ndefine('WP_ENVIRONMENT_TYPE', 'local');\n$table_prefix = 'wp_';\n"
    config += "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n"
    (site / "wp-config.php").write_text(config)
    (site / "wp-config.php").chmod(0o600)
    cli = [php, wp, "--path=" + str(site), "--skip-themes"]
    url = "http://127.0.0.1:" + str(args.port)
    password = secrets.token_urlsafe(24)
    command(cli + ["core", "multisite-install", "--url=" + url, "--title=Appointment Audit", "--admin_user=audit", "--admin_password=" + password, "--admin_email=audit@example.test", "--skip-email"], env=env)
    (root / "admin-password.txt").write_text(password)
    (root / "admin-password.txt").chmod(0o600)
    finish_setup(args)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", required=True)
    parser.add_argument("--finish", action='store_true', help='Finish an interrupted audit setup after core installation.')
    parser.add_argument("--source", default="/Users/ej64ojyw/Sites/multisite")
    parser.add_argument("--php", default=shutil.which("php") or "php")
    parser.add_argument("--wp", default="/opt/homebrew/bin/wp")
    parser.add_argument("--mysql-bin", default="/Applications/MAMP/Library/bin/mysql80/bin")
    parser.add_argument("--mysqld", default="/Applications/MAMP/Library/bin/mysql57/bin/mysqld")
    parser.add_argument("--port", default=8898, type=int)
    parser.add_argument("--baseline", default="28b5552dc96171826ce22603964f4c67dd06a0b7")
    parser.add_argument("--working-tree", action="store_true", help="Freeze the current working tree as the candidate.")
    parser.add_argument("--candidate", default="13cacbc75a39ddef599c005ca4f1fbd984143547")
    arguments = parser.parse_args()
    (finish_setup if arguments.finish else prepare)(arguments)
