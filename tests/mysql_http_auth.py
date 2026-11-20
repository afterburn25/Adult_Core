"""Opt-in disposable MySQL + real HTTP session integration test.

Run only with the AC_TEST_DB_* vars in docs/TESTING.md, against a NEW disposable
MySQL database. Sets up a temporary COPY of source; does not change the working
repository. The fixture deletes only its ac_ tables after the test.
"""
import http.cookiejar
import os
import pathlib
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

SOURCE = pathlib.Path(__file__).resolve().parents[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, new_url):
        return None


def main():
    if os.environ.get('AC_TEST_DB_CONFIRM') != 'YES_THIS_IS_A_DISPOSABLE_DATABASE':
        raise SystemExit('Refusing real MySQL HTTP test: disposable DB confirmation missing.')
    with tempfile.TemporaryDirectory(prefix='adult-core-mysql-http-') as tmp:
        root = pathlib.Path(tmp) / 'Adult_Core'
        shutil.copytree(SOURCE, root)
        php_fixture = ['php', 'tests/mysql_http_fixture.php']
        server = None
        setup_started = False
        try:
            setup_started = True
            subprocess.check_call(php_fixture + ['setup'], cwd=root)
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public'],
                                      cwd=root, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            checks = 0

            def req(path, data=None):
                payload = urllib.parse.urlencode(data).encode() if data is not None else None
                try:
                    resp = opener.open(f'http://127.0.0.1:{port}{path}', data=payload, timeout=4)
                except urllib.error.HTTPError as err:
                    resp = err
                with resp:
                    return resp.status, resp.read().decode('utf-8', errors='replace')

            for _ in range(50):
                try:
                    req('/admin/login')
                    break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError('PHP development server failed to start')

            def ensure(label, condition):
                nonlocal checks
                if not condition:
                    raise AssertionError(label)
                checks += 1

            code, body = req('/admin')
            ensure('Anonymous admin access must redirect', code == 302)
            code, body = req('/admin/login')
            ensure('Real login form loads', code == 200 and 'Sign in' in body)
            csrf_match = re.search(r'name="csrf" value="([a-f0-9]{64})"', body)
            ensure('Login CSRF hidden field', csrf_match is not None)
            csrf = csrf_match.group(1)
            code, body = req('/admin/login', {'email': 'owner@example.test', 'password': 'bad-password', 'csrf': csrf})
            ensure('Incorrect password rejected', code == 401 and 'Invalid email or password' in body)
            code, body = req('/admin')
            ensure('Failed login cannot view dashboard', code == 302)
            code, body = req('/admin/login', {'email': 'owner@example.test', 'password': 'strong-example-admin-password', 'csrf': csrf})
            ensure('Correct credentials redirect to dashboard', code == 303)
            code, body = req('/admin')
            ensure('Dashboard loads authenticated', code == 200 and 'HTTP Fixture &lt;script&gt;alert(1)&lt;/script&gt;' in body and '<script>alert(1)</script>' not in body)
            ensure('Edition visible', 'LITE' in body)
            # Browser-driven additive migration and public page editor.
            code, setup = req('/admin/setup')
            ensure('Authenticated setup page', code == 200 and 'Apply additive migrations' in setup)
            setup_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', setup)
            ensure('Setup has CSRF token', setup_token is not None)
            code, _ = req('/admin/setup', {'csrf': setup_token.group(1)})
            ensure('Schema setup redirects after migrations', code == 303)
            code, pages = req('/admin/pages')
            ensure('Pages admin lists draft homepage', code == 200 and 'Welcome' in pages)
            code, editor = req('/admin/page')
            edit_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', editor)
            ensure('Page editor requires auth and token', code == 200 and edit_csrf is not None)
            code, _ = req('/admin/page', {
                'csrf': edit_csrf.group(1), 'slug': 'safe-page',
                'title': 'Safe page', 'body': 'Hello <script>unsafe()</script>', 'status': 'published'
            })
            ensure('Page editor persists and redirects', code == 303)
            code, public = req('/p/safe-page')
            ensure('Published page escaped in frontend', code == 200 and '&lt;script&gt;' in public and '<script>unsafe()</script>' not in public)
            # Authenticated moderation: new content remains draft/private.
            code, moderation = req('/admin/moderation')
            ensure('Admin moderation queue available', code == 200 and 'Create unpublished draft' in moderation)
            moderation_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', moderation)
            ensure('Moderation is CSRF protected', moderation_token is not None)
            code, _ = req('/admin/moderation', {'csrf': 'invalid', 'action': 'new_draft', 'title': 'Hidden safety demo'})
            ensure('Bad moderation CSRF refused', code == 403)
            code, _ = req('/admin/moderation', {'csrf': moderation_token.group(1), 'action': 'new_draft', 'title': 'Hidden safety demo'})
            ensure('Draft creation redirect', code == 303)
            code, moderation = req('/admin/moderation')
            draft_match = re.search(r'<tr><td>(\d+)</td><td>Hidden safety demo</td><td>draft / pending', moderation)
            ensure('Moderation draft is private pending', code == 200 and draft_match is not None)
            draft_id = draft_match.group(1)
            csrf_roster = re.search(r'name="csrf" value="([a-f0-9]{64})"', moderation)
            ensure('Performer roster form has CSRF', csrf_roster is not None)
            code, _ = req('/admin/moderation', {
                'csrf': csrf_roster.group(1), 'action': 'declare_performers',
                'content_id': draft_id, 'performer_count': '2'
            })
            ensure('Declaring performer roster redirects', code == 303)
            code, moderation = req('/admin/moderation')
            ensure('Performer records stay pending', code == 200 and 'Performer verification records' in moderation and 'Assertion received' not in moderation)
            code, _ = req('/admin/moderation', {
                'csrf': csrf_roster.group(1), 'action': 'declare_performers',
                'content_id': draft_id, 'performer_count': '1'
            })
            ensure('Duplicate roster refused', code >= 400)
            # Registration creates a pending member, not a verified adult.
            code, registration = req('/join')
            join_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', registration)
            ensure('Member join available after setup', code == 200 and join_token is not None)
            code, pending = req('/join', {'csrf': join_token.group(1), 'email': 'member@test.example', 'password': 'strong-example-member-password'})
            ensure('Signup remains pending', code == 200 and 'pending review' in pending)
            code, member_login = req('/members/login')
            member_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', member_login)
            ensure('Member login CSRF', code == 200 and member_token is not None)
            code, _ = req('/members/login', {'csrf': member_token.group(1), 'email': 'member@test.example', 'password': 'strong-example-member-password'})
            ensure('Member login redirects', code == 303)
            code, email_page = req('/members/email')
            ensure('Email settings available', code == 200 and 'Not confirmed' in email_page)
            code, bad_confirm = req('/members/email/confirm?token=invalid')
            ensure('Invalid email token rejected', code == 400)
            code, result = req('/members/email', {'csrf': 'incorrect'})
            ensure('Email resend requires CSRF', code == 403)
            # Real browser email confirmation: GET preview must not consume token.
            disposable_token = subprocess.check_output(php_fixture + ['issue-email'], cwd=root, text=True).strip()
            ensure('Fixture token is well-formed', re.fullmatch('[a-f0-9]{64}', disposable_token) is not None)
            code, preview = req('/members/email/confirm?token='+disposable_token)
            ensure('Confirmation GET only previews', code == 200 and 'Confirm email address' in preview)
            email_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', preview)
            ensure('Email confirmation has CSRF', email_csrf is not None)
            code, preview2 = req('/members/email/confirm?token='+disposable_token)
            ensure('GET preview is repeatable', code == 200 and 'Confirm email address' in preview2)
            code, bad = req('/members/email/confirm', {'token': disposable_token, 'csrf':'invalid'})
            ensure('Bad confirmation CSRF denied', code == 403)
            code, confirmed = req('/members/email/confirm', {'token': disposable_token, 'csrf': email_csrf.group(1)})
            ensure('Email confirmation succeeds once', code == 200 and 'email address is confirmed' in confirmed)
            code, replay = req('/members/email/confirm', {'token': disposable_token, 'csrf': email_csrf.group(1)})
            ensure('Email confirmation replay blocked', code == 400)
            code, email_page = req('/members/email')
            ensure('Email confirmation state visible', code == 200 and 'Confirmed' in email_page)
            code, portal = req('/members')
            ensure('Member portal reveals pending status only', code == 200 and 'pending' in portal and 'Private content' in portal)
            code, report_form = req('/members/report?id=' + draft_id)
            ensure('Member safety report form', code == 200 and 'Submit safety report' in report_form)
            report_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', report_form)
            ensure('Member report CSRF', report_token is not None)
            code, _ = req('/members/report', {'csrf': 'invalid', 'content_id': draft_id, 'reason': 'Consent review urgently needed'})
            ensure('Bad member report CSRF refused', code == 403)
            code, _ = req('/members/report', {'csrf': report_token.group(1), 'content_id': draft_id, 'reason': 'Consent review urgently needed'})
            ensure('Member report accepted', code == 303)
            code, moderation = req('/admin/moderation')
            ensure('Complaint present in admin queue', code == 200 and 'Consent review urgently needed' in moderation and 'quarantined / pending' in moderation)
            csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', moderation).group(1)
            code, _ = req('/admin/moderation', {'csrf': csrf, 'action': 'reject', 'content_id': draft_id, 'note': 'Reject for further safety review'})
            ensure('Admin rejection recorded', code == 303)
            code, moderation = req('/admin/moderation')
            ensure('Rejected content remains blocked', code == 200 and 'rejected / rejected' in moderation)
            code, body = req('/admin/logout')
            ensure('GET cannot sign out', code == 405)
            csrf_match = re.search(r'name="csrf" value="([a-f0-9]{64})"', body)
            # GET /admin/logout never includes the dashboard form, so fetch fresh token.
            _, dashboard = req('/admin')
            csrf_match = re.search(r'name="csrf" value="([a-f0-9]{64})"', dashboard)
            ensure('Logout CSRF hidden field', csrf_match is not None)
            code, body = req('/admin/logout', {'csrf': 'invalid'})
            ensure('Bad logout CSRF rejected', code == 403)
            code, body = req('/admin')
            ensure('Bad logout does not revoke session', code == 200)
            code, body = req('/admin/logout', {'csrf': csrf_match.group(1)})
            ensure('Valid logout redirects', code == 303)
            code, body = req('/admin')
            ensure('Logged-out session denied', code == 302)
            print(f'Passed {checks} real MySQL HTTP authentication checks.')
        finally:
            if server is not None:
                server.terminate()
                try:
                    server.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=3)
            if setup_started:
                subprocess.check_call(php_fixture + ['cleanup'], cwd=root)


if __name__ == '__main__':
    main()
