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
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'],
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
            code, license_page = req('/admin/license')
            ensure('Licensed admin configuration accessible', code == 200 and 'SOFTWARE-ADULT-CORE' in license_page)
            ensure('License key is never shown on admin page', 'ABCD-EF12-3456-7890' not in license_page)
            license_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', license_page)
            ensure('License changes require CSRF', license_csrf is not None)
            code, _ = req('/admin/license', {'csrf': 'invalid','license_key':'ABCD-EF12-3456-7890','license_domain':'127.0.0.1'})
            ensure('Invalid license CSRF rejected', code == 403)
            code, _ = req('/admin/license', {'csrf': license_csrf.group(1),'license_key':'short','license_domain':'127.0.0.1'})
            ensure('Malformed license replacement rejected', code == 422)
            # Removing the private license blocks real authenticated pages, not repair.
            installed_license = root / 'config/license.php'
            private_contents = installed_license.read_bytes()
            installed_license.unlink()
            try:
                code, locked = req('/admin')
                ensure('Authenticated dashboard locks without valid license', code == 503 and 'license authorization required' in locked)
                code, repair = req('/admin/license')
                ensure('Authenticated license repair works while blocked', code == 200 and 'license' in repair.lower())
                code, locked = req('/p/anything')
                ensure('Public content locks without valid license', code == 503)
            finally:
                installed_license.write_bytes(private_contents)

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
            # Authenticated Visual Design Studio: correct theme and escaped homepage content.
            code, studio = req('/admin/design')
            ensure('Design Studio available after migration', code == 200 and 'Noir Atelier' in studio and 'Velvet Club' in studio)
            studio_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', studio)
            ensure('Studio has anti-CSRF token', studio_csrf is not None)
            ensure('Live drag/resize slider controls are rendered',
                   all(f'name="{field}"' in studio for field in ('cutout_x','cutout_y','cutout_width')))
            code, invalid= req('/admin/design', {
                'csrf':studio_csrf.group(1), 'theme':'noir-gold','accent':'#d9ae75',
                'image_side':'right','image_size':'large','hero_asset':'none',
                'cutout_x':'101','cutout_y':'50','cutout_width':'80',
                'title':'Rejected coordinates','body':'Must not save'
            })
            ensure('Out-of-range placement POST is rejected', code == 422)
            code, invalid= req('/admin/design', {
                'csrf':studio_csrf.group(1), 'theme':'noir-gold','accent':'#d9ae75',
                'image_side':'right','image_size':'large','hero_asset':'none',
                'cutout_x':'50','cutout_y':'50','cutout_width':'80%;body{display:none}',
                'title':'CSS injection','body':'Must not save'
            })
            ensure('CSS injection in placement is rejected', code == 422)
            code, bad = req('/admin/design', {
                'csrf':'invalid', 'theme':'noir-gold', 'accent':'#d9ae75',
                'image_side':'right', 'image_size':'large', 'hero_asset':'none',
                'cutout_x':'50','cutout_y':'50','cutout_width':'80',
                'title':'Unsafe', 'body':'should not save'
            })
            ensure('Design Studio POST requires CSRF', code == 403)
            code, _ = req('/admin/design', {
                'csrf':studio_csrf.group(1), 'theme':'rose-ivory', 'accent':'#ae758b',
                'image_side':'left', 'image_size':'medium', 'hero_asset':'none',
                'cutout_x':'24','cutout_y':'70','cutout_width':'61',
                'title':'Hello <studio>', 'body':'A safe and elegant welcome'
            })
            ensure('Valid design saves with redirect', code == 303)
            code, private_home = req('/')
            ensure('Homepage remains draft until published', code == 503)
            code, designcss = req('/design.css')
            ensure(f'Validated custom palette delivered (HTTP {code}, body={designcss[:180]!r})',
                   code == 200 and '#ae758b' in designcss and '--cutout-x:24%' in designcss
                   and '--cutout-y:70%' in designcss and '--cutout-width:61%' in designcss)
            code, noasset = req('/design/asset/12')
            ensure('Unknown or unselected image refused', code == 404)
            # Regular page editor exclusively controls the homepage publishing status.
            code, pages = req('/admin/pages')
            home_match = re.search(r'(?s)<tr><td>Hello &lt;studio&gt;</td><td>home</td><td>draft</td><td><a href="/admin/page\?id=(\d+)"', pages)
            ensure('Design saves escaped homepage but keeps draft', code == 200 and home_match is not None)
            home_id=home_match.group(1)
            code, _ = req('/admin/page?id='+home_id, {
                'csrf':studio_csrf.group(1),'id':home_id,'slug':'home',
                'title':'Hello <studio>', 'body':'A safe and elegant welcome', 'status':'published'
            })
            ensure('Homepage publishing is separate explicit action', code == 303)
            code, public_home = req('/')
            ensure('Published homepage uses selected layout and escapes text',
                   code == 200 and 'theme-rose-ivory' in public_home and 'Hello &lt;studio&gt;' in public_home and 'Hello <studio>' not in public_home)
            code, unsigned_asset = req('/admin/design/asset/999')
            ensure('Authorized but missing uploaded image is 404', code == 404)
            # Real multipart uploads: a transparent PNG defaults private, even on a published homepage.
            import struct, zlib
            def sample_png():
                def chunk(name, data):
                    return struct.pack('>I',len(data))+name+data+struct.pack('>I',zlib.crc32(name+data)&0xffffffff)
                return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR',struct.pack('>IIBBBBB',2,2,8,6,0,0,0))
                        + chunk(b'IDAT',zlib.compress(b'\x00'+b'\xff\xaa\x99\x00'*2 + b'\x00'+b'\xff\xaa\x99\x00'*2)) + chunk(b'IEND',b''))
            def upload_design(public_ok):
                boundary='ACMultipartTestBoundary'
                items=[('csrf',studio_csrf.group(1))]
                if public_ok: items.append(('public_use_confirm','1'))
                parts=[]
                for key,value in items:
                    parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode())
                parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="design_image"; filename="cutout.png"\r\nContent-Type: image/png\r\n\r\n').encode()+sample_png()+b'\r\n')
                parts.append(f'--{boundary}--\r\n'.encode())
                request=urllib.request.Request(f'http://127.0.0.1:{port}/admin/design/upload',
                    data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
                try: resp=opener.open(request,timeout=4)
                except urllib.error.HTTPError as err: resp=err
                with resp: return resp.status,resp.read().decode('utf-8', errors='replace')
            code, _ = upload_design(False)
            ensure('Private transparent PNG upload accepted', code == 303)
            code, studio = req('/admin/design')
            ids = re.findall(r'name="hero_asset" value="(\d+)"', studio)
            ensure('Private image appears in authenticated library', code == 200 and len(ids)>=1 and 'Private preview only' in studio)
            private_id=ids[0]
            code, private_image=req('/admin/design/asset/'+private_id)
            ensure('Administrator can preview private image', code == 200 and 'PNG' in private_image)
            code, _ = req('/admin/design', {
                'csrf':studio_csrf.group(1),'theme':'velvet-plum','accent':'#f19ad0',
                'image_side':'right','image_size':'large','hero_asset':private_id,
                'cutout_x':'42','cutout_y':'38','cutout_width':'88',
                'title':'Member studio','body':'Private image stays hidden'
            })
            ensure('Private image selected in visual editor', code == 303)
            code, public_home = req('/')
            ensure('Public page does not expose unapproved cutout', code == 200 and f'/design/asset/{private_id}' not in public_home)
            code, hidden_image = req('/design/asset/'+private_id)
            ensure('Unapproved selected cutout refused publicly', code == 404)
            code, _ = upload_design(True)
            ensure('Explicit safe decorative upload accepted', code == 303)
            code, studio = req('/admin/design')
            ids=re.findall(r'name="hero_asset" value="(\d+)"',studio)
            public_id=ids[0]
            ensure('Public-safe image is new and listed', code == 200 and public_id != private_id and 'Public-safe asset' in studio)
            code, _=req('/admin/design', {
                'csrf':studio_csrf.group(1),'theme':'noir-gold','accent':'#d9ae75',
                'image_side':'left','image_size':'medium','hero_asset':public_id,
                'cutout_x':'37','cutout_y':'71','cutout_width':'62',
                'title':'Private boutique','body':'Design publishing is explicit'
            })
            ensure('Explicit approved image selection saved', code == 303)
            code, public_home = req('/')
            ensure('Public homepage points only to approved selected image',
                   code==200 and f'/design/asset/{public_id}' in public_home and f'/design/asset/{private_id}' not in public_home
                   and 'class="cutout-positioner"' in public_home)
            code, final_css = req('/design.css')
            ensure('Saved drag and resize geometry reaches published site',
                   code == 200 and '--cutout-x:37%' in final_css and '--cutout-y:71%' in final_css
                   and '--cutout-width:62%' in final_css)
            code, public_image = req('/design/asset/'+public_id)
            ensure('Approved selected image served', code == 200 and 'PNG' in public_image)
            code, old_image = req('/design/asset/'+private_id)
            ensure('Old private image stays inaccessible', code == 404)
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
            # Anonymous recovery must not disclose accounts and must not consume tokens on GET.
            code, recovery_form = req('/members/recover')
            recover_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', recovery_form)
            ensure('Recovery form and CSRF available', code == 200 and recover_csrf is not None)
            code, reply = req('/members/recover', {'csrf':recover_csrf.group(1),'email':'nobody@test.example'})
            ensure('Unknown email gets neutral response', code == 200 and 'If the address belongs' in reply)
            code, reply = req('/members/recover', {'csrf':recover_csrf.group(1),'email':'member@test.example'})
            ensure('Known email gets identical neutral response', code == 200 and 'If the address belongs' in reply)
            reset_token = subprocess.check_output(php_fixture + ['issue-recovery'], cwd=root, text=True).strip()
            ensure('Fixture reset token well formed', re.fullmatch('[a-f0-9]{64}',reset_token) is not None)
            code, reset_form = req('/members/recover/reset?token='+reset_token)
            reset_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', reset_form)
            ensure('Recovery GET shows form without consuming', code == 200 and reset_csrf is not None)
            code, repeat_preview = req('/members/recover/reset?token='+reset_token)
            ensure('Recovery GET does not consume token', code == 200 and 'Choose a new password' in repeat_preview)
            code, invalid_csrf = req('/members/recover/reset', {'csrf':'bad','token':reset_token,'password':'new-strong-example-password','confirm_password':'new-strong-example-password'})
            ensure('Reset POST requires CSRF',code==403)
            code, mismatch = req('/members/recover/reset', {'csrf':reset_csrf.group(1),'token':reset_token,'password':'new-strong-example-password','confirm_password':'different-strong-example'})
            ensure('Password confirmation enforced',code==422)
            code, changed = req('/members/recover/reset', {'csrf':reset_csrf.group(1),'token':reset_token,'password':'new-strong-example-password','confirm_password':'new-strong-example-password'})
            ensure('Reset succeeds',code==200 and 'Password changed' in changed)
            code, stale_csrf = req('/members/recover/reset', {'csrf':reset_csrf.group(1),'token':reset_token,'password':'other-strong-example-password','confirm_password':'other-strong-example-password'})
            ensure('Session rotation invalidated old CSRF',code==403)
            code, preview_after = req('/members/recover/reset?token='+reset_token)
            fresh_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', preview_after)
            ensure('Replay probe obtains fresh CSRF',code==200 and fresh_csrf is not None)
            code, replay = req('/members/recover/reset', {'csrf':fresh_csrf.group(1),'token':reset_token,'password':'other-strong-example-password','confirm_password':'other-strong-example-password'})
            ensure('Reset token single use',code==400)
            code, locked = req('/members')
            ensure('Password reset invalidated old session',code==303)
            code, signin = req('/members/login')
            login_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"',signin)
            code, denied = req('/members/login',{'csrf':login_csrf.group(1),'email':'member@test.example','password':'strong-example-member-password'})
            ensure('Old password no longer accepted',code==400)
            code, permitted = req('/members/login',{'csrf':login_csrf.group(1),'email':'member@test.example','password':'new-strong-example-password'})
            ensure('New password accepted',code==303)
            code, portal = req('/members')
            ensure('Recovered member remains pending',code==200 and 'pending' in portal)
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
