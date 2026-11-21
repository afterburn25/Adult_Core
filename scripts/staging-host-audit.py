#!/usr/bin/env python3
"""Read-only HTTPS acceptance checks against an operator-owned Adult_Core staging host.

This does not install the site, authenticate, test a licensed payment, or certify
real cPanel / VPS worker supervision. There is no untrusted URL redirect, HTTP
fallback, proxy use, or command-line IP override.
"""
from __future__ import annotations

import argparse
import http.client
import ipaddress
import json
import re
import socket
import ssl
import sys
from dataclasses import dataclass
from urllib.parse import urlsplit

SCHEMA = 'adult-core-host-https-audit-v1'
PRIVATE_PATHS = (
    '/config/database.php', '/config/license.php', '/config/app-key.php',
    '/storage/install-state.json', '/storage/license-cache.json',
    '/src/Support/Autoload.php', '/scripts/backup-site.php',
    '/tests/run.php', '/AGENTS.md', '/.env',
)
MAX_REQUESTS = 17


class AuditInputError(ValueError):
    pass


class AuditNetworkError(RuntimeError):
    pass


@dataclass(frozen=True)
class ProbeResponse:
    status: int
    headers: dict[str, str]

    def header(self, name: str) -> str:
        return self.headers.get(name.lower(), '')


def validate_url(url: str) -> str:
    if not isinstance(url, str) or not 1 <= len(url) <= 253 or re.search(r'[\x00-\x20\x7f\\%]', url):
        raise AuditInputError('Provide only an HTTPS staging hostname, without credentials or extra URL syntax.')
    try:
        u = urlsplit(url)
        port = u.port
    except ValueError as exc:
        raise AuditInputError('Invalid staging HTTPS URL.') from exc
    if (u.scheme != 'https' or not u.hostname or u.username is not None
        or u.password is not None or port is not None or u.path not in ('', '/')
        or u.query or u.fragment or u.netloc != u.hostname):
        raise AuditInputError('Use exactly https://your-staging-host.example without port, path, userinfo or query.')
    host = u.hostname
    if not isinstance(host, str) or len(host) > 253 or not host.isascii():
        raise AuditInputError('A fully qualified ASCII DNS hostname is required.')
    if host.endswith('.') or host.startswith('.') or '.' not in host:
        raise AuditInputError('A fully qualified public DNS hostname is required.')
    if host.split('.')[-1] in ('localhost', 'local', 'internal', 'invalid', 'test', 'example'):
        raise AuditInputError('Use a real public DNS hostname, not a reserved test or internal domain.')
    labels = host.split('.')
    if any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', part) for part in labels):
        raise AuditInputError('Invalid staging DNS hostname.')
    if host.replace('.', '').isdigit():
        raise AuditInputError('IP-address targets are not allowed.')
    try:
        ipaddress.ip_address(host)
    except ValueError:
        pass
    else:
        raise AuditInputError('IP-address targets are not allowed.')
    return host


def resolve_public(host: str, resolver=None) -> str:
    """Refuse DNS answers with ANY non-global address, then pin all requests to one IP."""
    resolver = resolver or socket.getaddrinfo
    try:
        answers = resolver(host, 443, type=socket.SOCK_STREAM)
    except (OSError, ValueError) as exc:
        raise AuditNetworkError('Staging DNS resolution failed.') from exc
    candidates = []
    for entry in answers:
        try:
            value = ipaddress.ip_address(entry[4][0])
        except (IndexError, TypeError, ValueError) as exc:
            raise AuditNetworkError('Staging DNS answer was malformed.') from exc
        if not value.is_global:
            raise AuditInputError('Staging DNS includes a private, reserved or loopback address; refusing the probe.')
        candidates.append(value.compressed)
    if not candidates:
        raise AuditNetworkError('Staging DNS did not return an address.')
    return candidates[0]


class _PinnedHTTPSConnection(http.client.HTTPSConnection):
    def __init__(self, host: str, ip: str):
        super().__init__(host, port=443, timeout=5, context=ssl.create_default_context())
        self._pinned_ip = ip

    def connect(self) -> None:
        raw = socket.create_connection((self._pinned_ip, 443), self.timeout)
        try:
            self.sock = self._context.wrap_socket(raw, server_hostname=self.host)
        except BaseException:
            raw.close()
            raise


def fetch_headers(host: str, address: str, path: str) -> ProbeResponse:
    """GET without following redirects or reading response bodies (including private probes)."""
    connection = _PinnedHTTPSConnection(host, address)
    try:
        connection.request('GET', path, headers={
            'User-Agent': 'AdultCore-ReadOnlyStagingAudit/1',
            'Accept': 'text/html, text/css;q=0.9, */*;q=0.5',
            'Cache-Control': 'no-store',
            'Connection': 'close',
        })
        response = connection.getresponse()
        return ProbeResponse(response.status, {key.lower(): value for key, value in response.getheaders()})
    except (OSError, ssl.SSLError, http.client.HTTPException) as exc:
        raise AuditNetworkError('Staging TLS or HTTP request was inconclusive.') from exc
    finally:
        connection.close()


def audit(url: str, phase: str, *, fetch=None, resolver=None) -> dict:
    """Pure classification with injectable transport; production CLI uses pinned verified HTTPS."""
    host = validate_url(url)
    if phase not in ('preinstall', 'installed'):
        raise AuditInputError('Phase must be preinstall or installed.')
    address = resolve_public(host, resolver)
    fetch = fetch or fetch_headers
    checks = []
    requests = 0
    nonce = 'audit-nonexistent-' + __import__('secrets').token_hex(8)

    def check(check_id: str, path: str, classifier, requirement: str) -> None:
        nonlocal requests
        if requests >= MAX_REQUESTS:
            raise RuntimeError('Probe budget exceeded.')
        requests += 1
        try:
            r = fetch(host, address, path)
            if not isinstance(r, ProbeResponse) or not isinstance(r.status, int):
                raise AuditNetworkError('Staging response was malformed.')
            verdict = bool(classifier(r))
            checks.append({'id': check_id, 'status': 'pass' if verdict else 'block',
                           'http_status': r.status, 'requirement': requirement})
        except AuditNetworkError:
            checks.append({'id': check_id, 'status': 'inconclusive', 'http_status': None,
                           'requirement': requirement})

    def installer_redirect(response: ProbeResponse) -> bool:
        # Never follow a redirect: a private resource must only point to the installer.
        return response.status == 302 and response.header('location') in ('/install', '/install/')

    def is_private(response: ProbeResponse) -> bool:
        return response.status in (403, 404) or phase == 'preinstall' and installer_redirect(response)

    def entry(response: ProbeResponse) -> bool:
        return (response.status == 200 if phase == 'installed' else installer_redirect(response))

    check('homepage', '/', entry, 'Installed site responds 200, or fresh site redirects to /install/.')
    check('admin_login', '/admin/login',
          lambda r: r.status == 200 if phase == 'installed' else installer_redirect(r),
          'Admin login is reachable only in installed mode; preinstall redirects to installer.')
    check('installer_route', '/install/',
          lambda r: r.status in (403, 404) if phase == 'installed' else r.status == 200,
          'Installed site does not expose the installer; preinstall wizard is reachable.')
    check('static_stylesheet', '/assets/style.css',
          lambda r: r.status == 200 and r.header('content-type').lower().startswith('text/css'),
          'Public CSS asset is served with a stylesheet MIME type.')
    check('nonexistent_route', '/' + nonce,
          lambda r: r.status == 404 if phase == 'installed' else installer_redirect(r),
          'The live web server routes unknown paths through the application, not directory listing.')
    check('security_headers', '/',
          lambda r: bool(r.header('content-security-policy')) and r.header('x-content-type-options').lower() == 'nosniff',
          'Application responses include CSP and nosniff headers.')
    for path in PRIVATE_PATHS:
        check('private_' + path.rsplit('/', 1)[-1].lower().replace('.', '_') + '_' + str(PRIVATE_PATHS.index(path)),
              path, is_private, 'Private application files must be inaccessible by HTTP.')

    blocked = sum(1 for value in checks if value['status'] == 'block')
    unsure = sum(1 for value in checks if value['status'] == 'inconclusive')
    status = 'blocked' if blocked else 'inconclusive' if unsure else 'passed'
    return {
        'schema': SCHEMA, 'host': host, 'phase': phase,
        'outcome': status, 'checks': checks, 'blockers': blocked,
        'inconclusive': unsure, 'requests': requests,
        'limitations': [
            'Read-only GET and response-header checks; private response bodies are never read or printed.',
            'Successful responses do not prove the PHP-FPM runtime, document root, DNS history or application database is trustworthy.',
            'Pro worker supervision, actual license status, monthly payments, offsite restore and legal/compliance require separate tests.',
        ],
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description='Opt-in, read-only HTTPS tests on your Adult_Core staging host')
    parser.add_argument('--url', required=True, help='HTTPS URL of the operator-owned staging domain')
    parser.add_argument('--phase', required=True, choices=('preinstall', 'installed'))
    parser.add_argument('--confirm-staging-readonly', action='store_true', help='Confirm target ownership and staging-only scope')
    parser.add_argument('--json', action='store_true')
    args = parser.parse_args(argv)
    if not args.confirm_staging_readonly:
        parser.error('Pass --confirm-staging-readonly only when testing an operator-owned staging host.')
    try:
        report = audit(args.url, args.phase)
    except AuditInputError as exc:
        print('Refused staging request: ' + str(exc), file=sys.stderr)
        return 64
    except AuditNetworkError as exc:
        print('Staging acceptance was inconclusive: ' + str(exc), file=sys.stderr)
        return 3
    if args.json:
        print(json.dumps(report, indent=2, sort_keys=True))
    else:
        print('Adult_Core staging HTTPS audit | ' + report['host'] + ' | ' + report['phase'] + ' | ' + report['outcome'])
        for item in report['checks']:
            print(('PASS' if item['status']=='pass' else item['status'].upper()), item['id'], 'HTTP', item['http_status'])
        print('These HTTP checks do not certify actual hosting, billing, identity or Pro capabilities.')
    return {'passed': 0, 'blocked': 2, 'inconclusive': 3}[report['outcome']]


if __name__ == '__main__':
    raise SystemExit(main())
