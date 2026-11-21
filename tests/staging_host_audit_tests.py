#!/usr/bin/env python3
"""Offline security and protocol regression for opt-in staging HTTP acceptance."""
import http.client
import importlib.util
import json
import pathlib
import shutil
import socket
import subprocess
import sys
import tempfile
import time

SOURCE = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('staging_host_audit', SOURCE / 'scripts' / 'staging-host-audit.py')
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
CHECKS = 0


def check(case, condition):
    global CHECKS
    CHECKS += 1
    if not condition:
        raise AssertionError(case)


def reject(case, func, kind=module.AuditInputError):
    try:
        func()
    except kind:
        check(case, True)
    else:
        check(case, False)


def resolver(host, port, type):
    check('Only explicit HTTPS port 443 resolved', port == 443)
    return [(2, 1, 6, '', ('8.8.8.8', 443))]


def response(status, **headers):
    return module.ProbeResponse(status, {k.replace('_', '-'): v for k,v in headers.items()})


def transport(phase, overrides=None, broken=None):
    overrides = overrides or {}
    broken = broken or set()
    calls = []

    def fetch(host, ip, path):
        calls.append((host, ip, path))
        if path in broken:
            raise module.AuditNetworkError('test-only transport failure')
        if path in overrides:
            return overrides[path]
        if path == '/assets/style.css':
            return response(200, **{'content-type': 'text/css'})
        if path == '/':
            if phase == 'installed':
                return response(200, **{'content-security-policy': "default-src 'none'", 'x-content-type-options': 'nosniff'})
            return response(302, location='/install/', **{'content-security-policy': "default-src 'none'", 'x-content-type-options': 'nosniff'})
        if path == '/admin/login':
            return response(200 if phase == 'installed' else 302, location='/install/')
        if path == '/install/':
            return response(404 if phase == 'installed' else 200)
        return response(404 if phase == 'installed' else 302, location='/install/')

    return fetch, calls


def main():
    check('Well-formed HTTPS staging host', module.validate_url('https://staging.site.example.org') == 'staging.site.example.org')
    for bad in (
        'http://staging.example.org', 'https://localhost', 'https://127.0.0.1',
        'https://192.168.1.1', 'https://169.254.169.254', 'https://[::1]',
        'https://internal.local', 'https://test.example', 'https://staging.example.org:443',
        'https://staging.example.org:8443', 'https://staging.example.org/path',
        'https://staging.example.org/?key=secret', 'https://staging.example.org/#x',
        'https://user@staging.example.org', 'https://staging.example.org@evil.org',
        'https://staging.example.org.evil.org\\@test', 'https://staging.example.org%2f.evil.org',
        'https://staging.example.org\n', 'https://staging..example.org',
        'https://-bad.example.org', 'https://staging.example.org.',
    ):
        reject('Reject malformed/untrusted URL ' + bad[:40], lambda bad=bad: module.validate_url(bad))
    def different_dns(addresses):
        return lambda host, port, type: [(2, 1, 6, '', (ip, port)) for ip in addresses]
    for bad in ('127.0.0.1','10.0.0.2','169.254.169.254','192.168.0.1','::1','0.0.0.0','2001:db8::4'):
        reject('Reject nonpublic DNS ' + bad,
               lambda bad=bad: module.resolve_public('staging.example.org', different_dns([bad])))
    reject('Reject mixed DNS private/real', lambda: module.resolve_public('staging.example.org', different_dns(['8.8.8.8','10.10.10.10'])))
    check('Resolve pinned public address', module.resolve_public('staging.example.org', resolver) == '8.8.8.8')
    reject('DNS error treated as inconclusive',
           lambda: module.resolve_public('staging.example.org', lambda *args, **kw: (_ for _ in ()).throw(OSError('DNS failed'))),
           module.AuditNetworkError)

    for phase in ('preinstall','installed'):
        get, calls = transport(phase)
        result = module.audit('https://staging.example.org', phase, fetch=get, resolver=resolver)
        check('Pass ' + phase, result['outcome']=='passed')
        check('All mandatory controls passed ' + phase, result['blockers']==0 and result['inconclusive']==0)
        check('Pinned IP on each request ' + phase, all(call[:2] == ('staging.example.org','8.8.8.8') for call in calls))
        check('No extra requests ' + phase, len(calls) == result['requests'] == 16)
        check('All private paths included ' + phase, set(module.PRIVATE_PATHS).issubset({row[2] for row in calls}))
        check('No keys or private response bodies ' + phase, 'license_key' not in json.dumps(result))
        check('SSL/TLS external still required ' + phase, bool(result['limitations']))
        for exposed in module.PRIVATE_PATHS:
            get, calls = transport(phase, overrides={exposed: response(200)})
            report = module.audit('https://staging.example.org', phase, fetch=get, resolver=resolver)
            check('Public exposure blocks ' + phase + exposed,
                  report['outcome']=='blocked' and any(row['status']=='block' for row in report['checks']))
        get, _ = transport(phase, overrides={'/': response(302, location='https://evil.test')})
        check('Cross-origin redirect blocked ' + phase,
              module.audit('https://staging.example.org', phase, fetch=get, resolver=resolver)['outcome']=='blocked')
        get, _ = transport(phase, broken={'/config/database.php'})
        report = module.audit('https://staging.example.org', phase, fetch=get, resolver=resolver)
        check('Transport failure inconclusive ' + phase, report['outcome']=='inconclusive')
        get, _ = transport(phase, broken={'/config/database.php'}, overrides={'/AGENTS.md':response(200)})
        report = module.audit('https://staging.example.org', phase, fetch=get, resolver=resolver)
        check('Public exposure blocks despite outage ' + phase, report['outcome']=='blocked')
    get, _ = transport('installed', overrides={'/assets/style.css': response(200, **{'content-type':'text/html'})})
    check('Static content-type mismatch blocks', module.audit('https://staging.example.org','installed',fetch=get,resolver=resolver)['outcome']=='blocked')
    get, _ = transport('installed', overrides={'/install/':response(200)})
    check('Installer exposure after installation blocks', module.audit('https://staging.example.org','installed',fetch=get,resolver=resolver)['outcome']=='blocked')
    get, _ = transport('installed', overrides={'/':response(503)})
    check('Root 503 fails staging acceptance', module.audit('https://staging.example.org','installed',fetch=get,resolver=resolver)['outcome']=='blocked')
    reject('Invalid phase refused', lambda: module.audit('https://staging.example.org','other',fetch=get,resolver=resolver))
    # Real PHP HTTP lifecycle acceptance: the injected transport deliberately
    # uses loopback HTTP only in this test, while the operator CLI always uses
    # verified HTTPS. This checks actual route response shapes, not certificates.
    with tempfile.TemporaryDirectory(prefix='adultcore-host-http-') as directory:
        checkout = pathlib.Path(directory) / 'Adult_Core'
        shutil.copytree(SOURCE, checkout)
        with socket.socket() as available:
            available.bind(('127.0.0.1', 0))
            port = available.getsockname()[1]
        server = subprocess.Popen(['php','-S',f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'],
                                  cwd=checkout, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1',port), timeout=0.2):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise AssertionError('Local fixture PHP server failed to start')
            def local_fixture_http(host, address, path):
                conn = http.client.HTTPConnection('127.0.0.1', port, timeout=4)
                try:
                    conn.request('GET', path, headers={'Host':'staging.example.org'})
                    r = conn.getresponse()
                    return module.ProbeResponse(r.status, {k.lower():v for k,v in r.getheaders()})
                finally:
                    conn.close()
            result = module.audit('https://staging.example.org','preinstall',
                                  fetch=local_fixture_http,resolver=resolver)
            check('Real PHP development server preinstall route contract', result['outcome']=='passed')
            check('Real PHP development server all private probes pass', result['requests']==16)
        finally:
            server.terminate()
            try:
                server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                server.kill(); server.wait(timeout=3)

    script = str(SOURCE/'scripts'/'staging-host-audit.py')
    p = subprocess.run([sys.executable,script,'--url=https://staging.example.org','--phase=installed'],capture_output=True,text=True)
    check('CLI requires opt-in', p.returncode == 2 and 'confirm-staging-readonly' in p.stderr)
    p = subprocess.run([sys.executable,script,'--url=http://staging.example.org','--phase=installed','--confirm-staging-readonly'],capture_output=True,text=True)
    check('CLI refuses HTTP before network', p.returncode == 64)
    print(f'Passed {CHECKS} staging-host HTTPS security and contract checks.')


if __name__ == '__main__':
    main()
