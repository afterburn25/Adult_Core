"""Opt-in disposable MySQL + real HTTP session integration test.

Run only with the AC_TEST_DB_* vars in docs/TESTING.md, against a NEW disposable
MySQL database. Sets up a temporary COPY of source; does not change the working
repository. The fixture deletes only its ac_ tables after the test.
"""
import base64
import hashlib
import hmac
import json
import http.cookiejar
import struct
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
# Disposable CI hosts can take several seconds to apply 20+ additive schema
# migrations. This is only a test transport deadline, not a production timeout.
HTTP_TIMEOUT = 12


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
                    resp = opener.open(f'http://127.0.0.1:{port}{path}', data=payload, timeout=HTTP_TIMEOUT)
                except urllib.error.HTTPError as err:
                    resp = err
                with resp:
                    return resp.status, resp.read().decode('utf-8', errors='replace')

            def req_binary(path):
                try:
                    resp = opener.open(f'http://127.0.0.1:{port}{path}', timeout=HTTP_TIMEOUT)
                except urllib.error.HTTPError as err:
                    resp = err
                with resp:
                    return (resp.status, resp.headers.get('Content-Type', ''),
                            resp.headers.get('Cache-Control', ''), resp.read())

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
            code, _, login_cache, _=req_binary('/admin/login')
            ensure('Administrator login and CSRF tokens are not cacheable',
                   code==200 and 'no-store' in login_cache)
            csrf_match = re.search(r'name="csrf" value="([a-f0-9]{64})"', body)
            ensure('Login CSRF hidden field', csrf_match is not None)
            csrf = csrf_match.group(1)
            code, body = req('/admin/login', {'email': 'owner@example.test', 'password': 'bad-password', 'csrf': csrf})
            ensure('Incorrect password rejected', code == 401 and 'Invalid email or password' in body)
            code, body = req('/admin')
            ensure('Failed login cannot view dashboard', code == 302)
            code, body = req('/admin/login', {'email': 'owner@example.test', 'password': 'strong-example-admin-password', 'csrf': csrf})
            ensure('Correct credentials redirect to dashboard', code == 303)
            # PHP session ID cookie is persistent (7-day maximum), HttpOnly
            # and SameSite=Strict. No password is ever in the cookie.
            admin_cookies=[cookie for cookie in opener.handlers if isinstance(cookie,urllib.request.HTTPCookieProcessor)]
            browser_cookies=list(admin_cookies[0].cookiejar) if admin_cookies else []
            session_cookie=next((cookie for cookie in browser_cookies if cookie.name=='adultcore_admin'),None)
            ensure('Admin session cookie survives browser restart and expires',
                   session_cookie is not None and session_cookie.expires is not None
                   and session_cookie.expires>int(time.time())+86400
                   and session_cookie.has_nonstandard_attr('HttpOnly'))
            restart_jar=http.cookiejar.CookieJar()
            for cookie in browser_cookies:
                restart_jar.set_cookie(cookie)
            restart_browser=urllib.request.build_opener(NoRedirect,
                urllib.request.HTTPCookieProcessor(restart_jar))
            try:
                restarted=restart_browser.open(f'http://127.0.0.1:{port}/admin',timeout=HTTP_TIMEOUT)
            except urllib.error.HTTPError as err:
                restarted=err
            with restarted:
                ensure('Recreated browser uses persistent opaque cookie without re-login',
                       restarted.status==200 and b'HTTP Fixture' in restarted.read())
            code, body = req('/admin')
            ensure('Dashboard loads authenticated', code == 200 and 'HTTP Fixture &lt;script&gt;alert(1)&lt;/script&gt;' in body and '<script>alert(1)</script>' not in body)
            ensure('Edition visible', 'LITE' in body)
            code, license_page = req('/admin/license')
            code, _, license_cache, _=req_binary('/admin/license')
            ensure('Private license and edition administration is not cacheable',
                   code==200 and 'no-store' in license_cache)
            ensure('Licensed admin configuration accessible', code == 200 and 'SOFTWARE-ADULT-CORE' in license_page)
            ensure('License key is never shown on admin page', 'ABCD-EF12-3456-7890' not in license_page)
            ensure('License replacement makes billing cancellation responsibilities explicit',
                   'does not cancel an existing monthly subscription' in license_page
                   and 'temporary link to the main site' in license_page)
            license_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', license_page)
            ensure('License changes require CSRF', license_csrf is not None)
            ensure('Manual issuer check is a separate CSRF-protected POST',
                   'name="license_action" value="check"' in license_page and 'Check license now' in license_page)
            code, _ = req('/admin/license', {'csrf': 'invalid','license_action':'check'})
            ensure('Manual issuer check rejects invalid CSRF without API call', code == 403)
            code, _ = req('/admin/license', {'csrf': license_csrf.group(1),'license_action':'unknown'})
            ensure('Unknown license mutation rejected before vendor API call', code == 422)
            code, upgrade_page = req('/admin/upgrade')
            ensure('Edition upgrade form is authenticated and identifies current Lite edition',
                   code == 200 and 'Upgrade Lite to Pro' in upgrade_page and 'LITE' in upgrade_page)
            ensure('Upgrade requires password and verified backup confirmation',
                   'name="admin_password"' in upgrade_page and 'name="backup_confirm"' in upgrade_page)
            ensure('Purchase placeholder points only to Cajun Web Pro main site',
                   'href="https://cajunwebpro.com"' in upgrade_page)
            code, _ = req('/admin/upgrade', {
                'csrf':'bad', 'pro_license_key':'PRO-MONTH-2026-TEST',
                'admin_password':'strong-example-admin-password','backup_confirm':'1','upgrade_confirm':'UPGRADE'
            })
            ensure('Pro upgrade refuses invalid CSRF', code == 403)
            code, _ = req('/admin/upgrade', {
                'csrf': license_csrf.group(1),'pro_license_key':'PRO-MONTH-2026-TEST',
                'admin_password':'strong-example-admin-password','upgrade_confirm':'UPGRADE'
            })
            ensure('Pro upgrade refuses missing backup acknowledgment', code == 422)
            code, _ = req('/admin/upgrade', {
                'csrf': license_csrf.group(1),'pro_license_key':'PRO-MONTH-2026-TEST',
                'admin_password':'wrong-password','backup_confirm':'1','upgrade_confirm':'UPGRADE'
            })
            ensure('Pro upgrade refuses incorrect password', code == 422)
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
            ensure('Authenticated setup page shows missing migrations without claiming readiness',
                   code == 200 and 'Apply additive migrations' in setup
                   and 'Page revision protection' in setup
                   and 'Page navigation settings' in setup
                   and 'Private page revision history' in setup
                   and 'Pending' in setup)
            setup_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', setup)
            ensure('Setup has CSRF token', setup_token is not None)
            code, _ = req('/admin/setup', {'csrf': setup_token.group(1)})
            ensure('Schema setup redirects after migrations', code == 303)
            code, branding=req('/admin/site')
            brand_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',branding)
            brand_revision=re.search(r'name="revision" value="(\d+)"',branding)
            ensure('Local owner can edit public branding without a new password',
                   code==200 and 'Site identity' in branding
                   and brand_csrf is not None and brand_revision is not None
                   and 'name="site_name"' in branding
                   and 'name="manager_password"' not in branding)
            code, _=req('/admin/site',{
                'csrf':'bad','revision':brand_revision.group(1),
                'site_name':'Unauthorized brand'
            })
            ensure('Site branding change requires owner session CSRF',code==403)
            code, invalid_brand=req('/admin/site',{
                'csrf':brand_csrf.group(1),'revision':brand_revision.group(1),
                'site_name':'Bad\x00Name'
            })
            ensure('NUL controls in site name are rejected',code==200
                   and 'valid single-line text' in invalid_brand)
            code, _=req('/admin/site',{
                'csrf':brand_csrf.group(1),'revision':brand_revision.group(1),
                'site_name':'Owner <Club> & More'
            })
            ensure('Owner changes local site brand without password re-entry',code==303)
            code, stale_brand=req('/admin/site',{
                'csrf':brand_csrf.group(1),'revision':brand_revision.group(1),
                'site_name':'Stale overwritten brand'
            })
            ensure('Stale owner tab cannot overwrite a newly changed site name',
                   code==200 and 'Site name changed; reload before saving.' in stale_brand)
            code, updated_brand=req('/admin/site')
            ensure('Owner brand form escapes markup and reflects revision',
                   code==200 and 'Owner &lt;Club&gt; &amp; More' in updated_brand
                   and 'name="revision" value="2"' in updated_brand)
            code, health=req('/admin/health')
            ensure('Owner can inspect local health without receiving private data or launch approval',
                   code==200 and 'Local checks:' in health
                   and 'Public webroot isolation' in health
                   and 'Monitored additive migrations' in health
                   and 'Local license/edition consistency' in health
                   and 'Member email delivery configuration' in health
                   and 'Configured does not mean email delivery has been tested' in health
                   and 'Production release is still blocked' in health
                   and 'password_hash' not in health)
            code, _, health_headers, _=req_binary('/admin/health')
            ensure('Private local diagnostics cannot be browser cached',
                   code==200 and 'no-store' in health_headers)
            code, redacted_json=req('/admin/health?format=json')
            local_report=json.loads(redacted_json) if code==200 else {}
            ensure('Redacted local health JSON export never claims production approval',
                   code==200 and local_report.get('schema')=='adult-core-local-health-v1'
                   and local_report.get('production_ready') is False
                   and isinstance(local_report.get('checks'),list)
                   and any(check.get('name')=='Member email delivery configuration'
                           and check.get('pass') is False
                           for check in local_report.get('checks',[]))
                   and 'sender@example.test' not in redacted_json
                   and 'password_hash' not in redacted_json
                   and 'ABCD-EF12-3456-7890' not in redacted_json
                   and 'owner@example.test' not in redacted_json)
            code, bad_health_format=req('/admin/health?format=provider-approved')
            ensure('Health export forbids unsupported or untrusted report formats',
                   code==422 and 'Invalid diagnostics format' in bad_health_format)
            code, _=req('/admin/health',{'csrf':brand_csrf.group(1)})
            ensure('Read-only local health refuses POST',code==405)
            code, work_dashboard=req('/admin')
            ensure('Owner dashboard reflects only local safe work counters after site setup',
                   code==200 and 'Local work overview' in work_dashboard
                   and 'Pending local members' in work_dashboard
                   and 'Unpublished website drafts' in work_dashboard
                   and 'Open safety reports' in work_dashboard
                   and 'Submitted private text plans' in work_dashboard
                   and 'A pending member account is not independently age-verified' in work_dashboard
                   and 'password_hash' not in work_dashboard)
            code, migrated_setup=req('/admin/setup')
            ensure('Site setup reports individual page migrations as installed',
                   code==200
                   and '<td>Local member suspension audit</td><td>Installed</td>' in migrated_setup
                   and 'All listed local schema migrations have been recorded.' in migrated_setup
                   and '<td>Page revision protection</td><td>Installed</td>' in migrated_setup
                   and '<td>Page navigation settings</td><td>Installed</td>' in migrated_setup
                   and '<td>Private page revision history</td><td>Installed</td>' in migrated_setup
                   and '<td>Local site identity</td><td>Installed</td>' in migrated_setup)
            # Two independent staff sessions ensure a suspended-and-restored
            # moderator's OLD cookie stays revoked even without an intervening
            # HTTP request while the database account was suspended.
            code, staff_page = req('/admin/staff')
            staff_token = re.search(r'name="csrf" value="([a-f0-9]{64})"',staff_page)
            ensure('Owner can access CSRF-protected staff management after setup',
                   code == 200 and staff_token is not None
                   and 'Moderation team' in staff_page)
            ensure('Initial login unlocks staff changes without another password',
                   'name="manager_password"' not in staff_page
                   and 'value="create"' in staff_page)
            manager_password='strong-example-admin-password'
            new_staff_email='new-staff@example.test'
            initial_staff_password='first-long-staff-password'
            next_staff_password='restored-long-staff-password'
            code, _=req('/admin/staff',{
                'csrf':'invalid','action':'create','email':new_staff_email,
                'new_password':initial_staff_password,})
            ensure('Staff creation requires manager CSRF',code == 403)
            code, refused=req('/admin/staff',{
                'csrf':staff_token.group(1),'action':'verify',
                'manager_password':'incorrect-manager-password'})
            ensure('Optional once-per-window manager reauthentication refuses wrong password',
                   code == 422 and 'Manager password confirmation failed.' in refused)
            code, _=req('/admin/staff',{
                'csrf':staff_token.group(1),'action':'verify',
                'manager_password':manager_password})
            ensure('Manager can renew the authorization window once',code == 303)
            code, unlocked_page=req('/admin/staff')
            ensure('Renewal permits routine staff changes without password fields',
                   code == 200 and 'name="manager_password"' not in unlocked_page)
            code, _=req('/admin/staff',{
                'csrf':staff_token.group(1),'action':'create','email':new_staff_email,
                'new_password':initial_staff_password,'role':'owner'})
            ensure('Staff can only be provisioned as a restricted moderator',code == 303)
            code, staff_page=req('/admin/staff')
            new_staff_row=re.search(
                r'<tr><td>(\d+)</td><td>new-staff@example.test</td><td>active</td><td>Not enrolled</td>',staff_page)
            ensure('Staff dashboard lists restricted moderator and private create audit',
                   code == 200 and new_staff_row is not None
                   and 'created' in staff_page
                   and initial_staff_password not in staff_page)
            new_staff_id=new_staff_row.group(1)
            def make_staff_browser():
                browser=urllib.request.build_opener(NoRedirect,
                    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                def make_req(path,data=None):
                    payload=urllib.parse.urlencode(data).encode() if data is not None else None
                    try:
                        response=browser.open(f'http://127.0.0.1:{port}{path}',data=payload,timeout=HTTP_TIMEOUT)
                    except urllib.error.HTTPError as err:
                        response=err
                    with response:
                        return response.status,response.read().decode('utf-8',errors='replace')
                return make_req
            staff_req=make_staff_browser()
            stale_staff_req=make_staff_browser()
            for browser_req in (staff_req,stale_staff_req):
                code, login_form=browser_req('/admin/login')
                csrf_match=re.search(r'name="csrf" value="([a-f0-9]{64})"',login_form)
                ensure('Staff session has independent login CSRF',code == 200 and csrf_match is not None)
                code, _=browser_req('/admin/login',{
                    'csrf':csrf_match.group(1),'email':new_staff_email,
                    'password':initial_staff_password})
                ensure('Owner-created moderator signs into restricted workspace',code == 303)
            code, staff_queue=staff_req('/admin/moderation')
            ensure('Temporary moderator credentials require personal rotation before review',code == 302)
            code, first_security=staff_req('/admin/security')
            first_change_token=re.search(r'name="csrf" value="([a-f0-9]{64})"',first_security)
            ensure('Temporary moderator can reach own password change only',
                   code == 200 and first_change_token is not None
                   and 'value="change_password"' in first_security)
            first_personal_password='moderator-personal-first-password'
            code, _=staff_req('/admin/security',{
                'csrf':first_change_token.group(1),'action':'change_password',
                'password':initial_staff_password,'new_password':first_personal_password})
            ensure('Initial temporary credential is rotated before content review',code == 303)
            # Both browsers must sign in afresh with the PERSONAL password;
            # the second browser is kept idle through later suspension.
            for browser_req in (staff_req,stale_staff_req):
                code, login_form=browser_req('/admin/login')
                changed_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',login_form)
                ensure('Temporary sessions do not survive initial rotation',
                       code == 200 and changed_csrf is not None)
                code, _=browser_req('/admin/login',{
                    'csrf':changed_csrf.group(1),'email':new_staff_email,
                    'password':first_personal_password})
                ensure('New personal moderator credentials allow restricted login',code == 303)
            code, staff_queue=staff_req('/admin/moderation')
            ensure('Moderator with personal password gains restricted review permission',code == 200)
            code, _=req('/admin/staff',{
                'csrf':staff_token.group(1),'action':'suspend','staff_id':new_staff_id,
                })
            ensure('Manager can suspend only a moderator',code == 303)
            code, _=staff_req('/admin/moderation')
            ensure('Suspended moderator active session is denied',code == 302)
            # The same old password must NEVER count as a credential reset.
            # Extra failed staff step-up attempts are intentionally omitted
            # here because the endpoint has a strict five-attempt rate bound.
            code, _=req('/admin/staff',{
                'csrf':staff_token.group(1),'action':'restore','staff_id':new_staff_id,
                'new_password':next_staff_password,})
            ensure('Restoring staff rotates password and session revocation epoch',code == 303)
            code, _=stale_staff_req('/admin/moderation')
            ensure('Restoration cannot resurrect pre-suspension session cookie',code == 302)
            code, staff_form=staff_req('/admin/login')
            new_staff_token=re.search(r'name="csrf" value="([a-f0-9]{64})"',staff_form)
            ensure('Restored moderator must perform a fresh sign-in',
                   code == 200 and new_staff_token is not None)
            code, _=staff_req('/admin/login',{
                'csrf':new_staff_token.group(1),'email':new_staff_email,
                'password':initial_staff_password})
            ensure('Restoring moderator invalidated previous password',code == 401)
            code, _=staff_req('/admin/login',{
                'csrf':new_staff_token.group(1),'email':new_staff_email,
                'password':next_staff_password})
            ensure('Restored moderator signs in using only new credential',code == 303)
            code, staff_security=staff_req('/admin/security')
            change_token=re.search(r'name="csrf" value="([a-f0-9]{64})"',staff_security)
            ensure('Moderator can see own password rotation form',
                   code == 200 and change_token is not None
                   and 'value="change_password"' in staff_security)
            code, _=staff_req('/admin/security',{
                'csrf':'invalid','action':'change_password',
                'password':next_staff_password,'new_password':'personal-new-moderator-password'})
            ensure('Moderator password change refuses invalid CSRF',code == 403)
            code, _=staff_req('/admin/security',{
                'csrf':change_token.group(1),'action':'change_password',
                'password':'wrong-current-password','new_password':'personal-new-moderator-password'})
            ensure('Moderator password rotation rejects wrong current password',code == 422)
            code, _=staff_req('/admin/security',{
                'csrf':change_token.group(1),'action':'change_password',
                'password':next_staff_password,'new_password':'personal-new-moderator-password'})
            ensure('Moderator rotates own credentials and signs out',code == 303)
            code, _=staff_req('/admin/moderation')
            ensure('Changing moderator password invalidates current session',code == 302)
            code, staff_page=req('/admin/staff')
            ensure('Staff private audit includes creation, suspension and restoration',
                   code == 200 and all(x in staff_page for x in ('created','suspended','restored'))
                   and next_staff_password not in staff_page)
            subprocess.check_output(php_fixture + ['seed-moderator'],cwd=root)
            # Distinct cookie jar models a second human moderator. Neither
            # browser shares an administrator session or CSRF token.
            mod_opener=urllib.request.build_opener(NoRedirect,
                urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            def mod_req(path, data=None):
                payload=urllib.parse.urlencode(data).encode() if data is not None else None
                try:
                    response=mod_opener.open(f'http://127.0.0.1:{port}{path}',data=payload,timeout=HTTP_TIMEOUT)
                except urllib.error.HTTPError as err:
                    response=err
                with response:
                    return response.status,response.read().decode('utf-8',errors='replace')
            code, mod_login=mod_req('/admin/login')
            mod_token=re.search(r'name="csrf" value="([a-f0-9]{64})"',mod_login)
            ensure('Moderator sees separate CSRF-protected login',code == 200 and mod_token is not None)
            code, _=mod_req('/admin/login', {
                'csrf':mod_token.group(1),'email':'moderator@example.test',
                'password':'strong-example-moderator-password'
            })
            ensure('Moderator role signs into restricted moderation route',code == 303)
            code, mod_home=mod_req('/admin')
            ensure('Moderator dashboard redirects to restricted review',code == 302)
            code, mod_queue=mod_req('/admin/moderation')
            ensure('Moderator can view protected review queue but not admin navigation',
                   code == 200 and 'Content moderation' in mod_queue
                   and 'name="action" value="new_draft"' in mod_queue
                   and 'href="/admin/security"' in mod_queue
                   and 'href="/admin/tiers"' not in mod_queue
                   and 'href="/admin/upgrade"' not in mod_queue)
            mod_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',mod_queue)
            ensure('Moderator review actions use CSRF',mod_csrf is not None)
            code, _=mod_req('/admin/moderation',{
                'csrf':mod_csrf.group(1),'action':'new_draft',
                'title':'Moderator restricted test draft'
            })
            ensure('Moderator can stage a private-only content draft',code == 303)
            for forbidden in ('/admin/staff','/admin/tiers','/admin/upgrade','/admin/license',
                              '/admin/pages','/admin/site','/admin/health','/admin/members/find','/admin/members/manage?id=1','/admin/packages','/admin/page/preview?id=1'):
                code, _=mod_req(forbidden)
                ensure('Moderator cannot enter privileged admin area '+forbidden,code == 403)
            code, mod_mfa=mod_req('/admin/security')
            ensure('Moderator may manage their own MFA',code == 200 and 'Two-factor authentication' in mod_mfa)
            subprocess.check_output(php_fixture + ['suspend-owner'],cwd=root)
            code, _ = req('/admin')
            ensure('Suspended administrator loses existing dashboard session',code == 302)
            code, suspended_signin = req('/admin/login')
            suspended_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',suspended_signin)
            ensure('Suspended account must sign in anew',code == 200 and suspended_csrf is not None)
            code, _ = req('/admin/login', {
                'email':'owner@example.test','password':'strong-example-admin-password',
                'csrf':suspended_csrf.group(1)
            })
            ensure('Suspended admin password cannot authenticate',code == 401)
            subprocess.check_output(php_fixture + ['reactivate-owner'],cwd=root)
            code, _ = req('/admin')
            ensure('Restoring account does not resurrect revoked session',code == 302)
            code, restored_login = req('/admin/login')
            restored_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',restored_login)
            ensure('Reactivated administrator gets a fresh login CSRF',restored_csrf is not None)
            code, _ = req('/admin/login', {
                'email':'owner@example.test','password':'strong-example-admin-password',
                'csrf':restored_csrf.group(1)
            })
            ensure('Reactivated administrator can sign in again',code == 303)
            code, tier_page = req('/admin/tiers')
            code, _, private_tier_cache, _=req_binary('/admin/tiers')
            ensure('Owner tier catalog is private and never cacheable',
                   code==200 and 'no-store' in private_tier_cache)
            ensure('Tier catalog loads for authenticated site administrator',
                   code == 200 and 'Create tier label' in tier_page
                   and 'A tier label is not proof of payment' in tier_page)
            tier_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"',tier_page)
            ensure('Tier catalog has CSRF-protected create form',tier_csrf is not None)
            code, _ = req('/admin/tiers', {
                'csrf':'invalid','action':'create','name':'Unsafe tier'
            })
            ensure('Tier creation refuses wrong CSRF',code == 403)
            code, _ = req('/admin/tiers', {
                'csrf':tier_csrf.group(1),'action':'create','name':' '
            })
            ensure('Tier creation rejects empty name',code == 422)
            initial_tier='Founders <script>alert(1)</script>'
            code, _ = req('/admin/tiers', {
                'csrf':tier_csrf.group(1),'action':'create','name':initial_tier
            })
            ensure('Tier label creation redirects without enabling payment',code == 303)
            code, tier_page = req('/admin/tiers')
            ensure('Stored tier labels are escaped in admin UI',
                   code == 200 and 'Founders &lt;script&gt;alert(1)&lt;/script&gt;' in tier_page
                   and 'Disabled (staging only)' in tier_page
                   and initial_tier not in tier_page)
            created_tier=re.search(r'name="tier_id" value="(\d+)"',tier_page)
            ensure('New tier has stable numeric ID',created_tier is not None)
            tier_id=created_tier.group(1)
            code, duplicate_tier = req('/admin/tiers', {
                'csrf':tier_csrf.group(1),'action':'create','name':initial_tier
            })
            ensure('Duplicate tier label refused',code == 422 and 'already exists' in duplicate_tier)
            code, stale_tier = req('/admin/tiers', {
                'csrf':tier_csrf.group(1),'action':'rename','tier_id':tier_id,
                'expected_name':'Old label','name':'Unauthorized overwrite'
            })
            ensure('Stale tier label rename refused',code == 422 and 'reload the catalog' in stale_tier)
            code, _ = req('/admin/tiers', {
                'csrf':tier_csrf.group(1),'action':'rename','tier_id':tier_id,
                'expected_name':initial_tier,'name':'Founders Circle'
            })
            ensure('Tier renamed in-place without changing stable ID',code == 303)
            code, renamed_tier=req('/admin/tiers')
            ensure('Renamed label displayed with original ID',
                   code == 200 and 'Founders Circle' in renamed_tier
                   and f'name="tier_id" value="{tier_id}"' in renamed_tier)
            code, _ = req('/admin/tiers', {
                'csrf':tier_csrf.group(1),'action':'deactivate','tier_id':tier_id
            })
            ensure('Admin tier catalog does not allow billing-affecting deactivation',code == 422)
            code, pages = req('/admin/pages')
            ensure('Pages admin lists draft homepage', code == 200 and 'Welcome' in pages)
            code, _, private_pages_cache, _=req_binary('/admin/pages')
            ensure('Owner-only saved page list forbids browser/proxy caching',
                   code==200 and 'no-store' in private_pages_cache)
            code, bad_pages=req('/admin/pages?before=not-an-id')
            ensure('Forged page list cursor fails without opening protected drafts',
                   code==422 and 'Invalid page list cursor' in bad_pages)
            code, write_directory=req('/admin/pages',{'csrf':'bad'})
            ensure('Page index never accepts a POST mutation',code==405)
            code, write_members=req('/admin/members',{'csrf':'bad'})
            ensure('Owner member directory remains read-only',code==405)
            code, bad_filter=req('/admin/pages?status=approved')
            ensure('Page status is never an adult-media approval action',
                   code==422 and 'Invalid page status' in bad_filter)
            code, editor = req('/admin/page')
            edit_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', editor)
            ensure('Page editor requires auth and token', code == 200 and edit_csrf is not None)
            ensure('Unsaved page preview is owner-only and visibly not published',
                   'id="page-live-title"' in editor
                   and 'id="page-live-body"' in editor
                   and 'Unsaved text preview' in editor
                   and 'Editing does not save or publish the page' in editor
                   and '<script defer src="/assets/page-editor.js"></script>' in editor)
            code, _ = req('/admin/page', {
                'csrf': edit_csrf.group(1), 'slug': 'safe-page',
                'title': 'Safe page', 'body': 'Hello <script>unsafe()</script>', 'status': 'published'
            })
            ensure('Page editor persists and redirects', code == 303)
            code, public = req('/p/safe-page')
            ensure('Published page escaped in frontend', code == 200 and '&lt;script&gt;' in public and '<script>unsafe()</script>' not in public)
            ensure('Owner-updated site brand is escaped in public page title and header',
                   'Owner &lt;Club&gt; &amp; More' in public
                   and 'Owner <Club> & More' not in public)
            # A published page may be intentionally unlisted, but that does
            # not grant secrecy: its direct URL still works, with escaped text.
            code, _=req('/admin/page',{
                'csrf':edit_csrf.group(1),'slug':'unlisted','title':'Unlisted public page',
                'body':'Public even without menu link','status':'published',
                'show_in_menu':'0','nav_position':'7'
            })
            ensure('Site owner can create a published unlisted page',code==303)
            code, unlisted_public=req('/p/unlisted')
            ensure('Unlisted page retains its independently published public URL',
                   code==200 and 'Unlisted public page' in unlisted_public
                   and 'href="/p/unlisted"' not in unlisted_public)
            code, saved_list=req('/admin/pages')
            unlisted_match=re.search(r'Unlisted public page</td><td>unlisted</td><td>published</td>'
                                      r'<td><a href="/admin/page\?id=(\d+)"',saved_list)
            ensure('Owner can reopen the unlisted page',code==200 and unlisted_match is not None)
            unlisted_id=unlisted_match.group(1)
            # An older open editor tab without m17 menu controls cannot
            # silently expose an unlisted link or reset menu ordering.
            code, legacy_edit=req('/admin/page?id='+unlisted_id,{
                'csrf':edit_csrf.group(1),'revision':'1',
                'slug':'unlisted','title':'Unlisted public page',
                'body':'Still public but unlisted','status':'published'
            })
            ensure('Legacy page form preserves menu settings when fields are absent',
                   code==303)
            code, still_unlisted=req('/p/safe-page')
            ensure('Unlisted page is not added to menu by old form',
                   code==200 and 'href="/p/unlisted"' not in still_unlisted)
            code, unlisted_form=req('/admin/page?id='+unlisted_id)
            unlisted_revision=re.search(r'name="revision" value="(\d+)"',unlisted_form)
            ensure('Unlisted page form retains explicit hidden navigation checkbox and order',
                   code==200 and unlisted_revision is not None
                   and 'name="show_in_menu" value="0"' in unlisted_form
                   and 'name="nav_position" type="number" min="0" max="999" value="7"' in unlisted_form)
            code, _=req('/admin/page?id='+unlisted_id,{
                'csrf':edit_csrf.group(1),'revision':unlisted_revision.group(1),
                'slug':'unlisted','title':'Unlisted public page','body':'Now listed',
                'status':'published','show_in_menu':'1','nav_position':'7'
            })
            ensure('Owner can opt page into the public menu without unpublishing',code==303)
            code, public=req('/p/safe-page')
            ensure('Published listed page appears in the public navigation',
                   code==200 and 'href="/p/unlisted"' in public)
            # A saved draft can be previewed under the existing administrator
            # session without making the draft visible to public visitors.
            code, _ = req('/admin/page', {
                'csrf':edit_csrf.group(1), 'slug':'secret-preview',
                'title':'Unpublished <b>preview</b>',
                'body':'Private <script>not_public()</script>',
                'status':'draft'
            })
            ensure('Unpublished draft page saved for admin preview',code == 303)
            code, _ = req('/p/secret-preview')
            ensure('Saved draft cannot be accessed on its public URL',code == 404)
            code, saved_pages = req('/admin/pages')
            draft_saved_match = re.search(
                r'<tr><td>Unpublished &lt;b&gt;preview&lt;/b&gt;</td><td>secret-preview</td><td>draft</td>'
                r'<td><a href="/admin/page\?id=(\d+)"',saved_pages)
            ensure('Saved draft lists private preview control',
                   code == 200 and draft_saved_match is not None
                   and 'Preview saved' in saved_pages)
            saved_draft_id=draft_saved_match.group(1)
            code, saved_preview = req('/admin/page/preview?id='+saved_draft_id)
            ensure('Authenticated saved draft renders safe public-site themed preview',
                   code == 200 and 'ADMIN PREVIEW — SAVED PAGE' in saved_preview
                   and 'Unpublished &lt;b&gt;preview&lt;/b&gt;' in saved_preview
                   and 'Private &lt;script&gt;not_public()&lt;/script&gt;' in saved_preview
                   and '<script>not_public()</script>' not in saved_preview)
            code, _, preview_cache, _=req_binary('/admin/page/preview?id='+saved_draft_id)
            ensure('Private draft preview is not browser/proxy cacheable',
                   code == 200 and 'no-store' in preview_cache)
            code, _ = req('/admin/page/preview?id=not-a-page')
            ensure('Malformed draft preview ID is neutral not-found',code == 404)
            code, _ = req('/admin/page/preview?id='+saved_draft_id,{'csrf':edit_csrf.group(1)})
            ensure('Private draft preview is read-only GET',code == 405)
            # Two page editor tabs cannot silently overwrite each other's saved
            # draft text, status, or public visibility.
            code, draft_form=req('/admin/page?id='+saved_draft_id)
            first_revision=re.search(r'name="revision" value="(\d+)"',draft_form)
            ensure('Existing page editor supplies an opaque monotonic revision',
                   code==200 and first_revision is not None)
            ensure('Unsaved draft preview renders saved private plain text escaped',
                   '<h4 id="page-live-title">Unpublished &lt;b&gt;preview&lt;/b&gt;</h4>' in draft_form
                   and 'Private &lt;script&gt;not_public()&lt;/script&gt;' in draft_form
                   and '<script>not_public()</script>' not in draft_form)
            draft_revision=first_revision.group(1)
            code, _=req('/admin/page?id='+saved_draft_id,{
                'csrf':edit_csrf.group(1),'revision':draft_revision,'slug':'secret-preview',
                'title':'Updated safe draft','body':'Author one wins','status':'draft'
            })
            ensure('First page editor tab saves revision',code==303)
            code, stale_page=req('/admin/page?id='+saved_draft_id,{
                'csrf':edit_csrf.group(1),'revision':draft_revision,'slug':'secret-preview',
                'title':'Stale public overwrite','body':'Should never publish','status':'published'
            })
            ensure('Stale page revision is refused rather than accidentally published',
                   code==200 and 'Page changed; reload before saving.' in stale_page)
            code, public_draft=req('/p/secret-preview')
            ensure('Concurrent stale publication attempt leaves private page unavailable',
                   code==404)
            code, updated_preview=req('/admin/page/preview?id='+saved_draft_id)
            ensure('Newest page text survives refused stale writer',
                   code==200 and 'Updated safe draft' in updated_preview
                   and 'Stale public overwrite' not in updated_preview)
            code, clone_form=req('/admin/page?id='+saved_draft_id)
            latest_revision=re.search(r'name="revision" value="(\d+)"',clone_form)
            ensure('Saved page exposes private draft duplicate form',
                   code==200 and latest_revision is not None
                   and 'name="action" value="duplicate"' in clone_form)
            code, _=req('/admin/page?id='+saved_draft_id,{
                'csrf':'incorrect','action':'duplicate',
                'revision':latest_revision.group(1),'new_slug':'private-safe-copy'
            })
            ensure('Private duplicate requires administrator CSRF',code==403)
            code, _=req('/admin/page?id='+saved_draft_id,{
                'csrf':edit_csrf.group(1),'action':'duplicate',
                'revision':draft_revision,'new_slug':'stale-copy'
            })
            ensure('Private duplicate denies a stale source revision',code==200)
            code, private_copy_resp=req('/admin/page?id='+saved_draft_id,{
                'csrf':edit_csrf.group(1),'action':'duplicate',
                'revision':latest_revision.group(1),'new_slug':'private-safe-copy'
            })
            ensure('Fresh duplicate creates an unpublished page only',code==303)
            code, copy_public=req('/p/private-safe-copy')
            ensure('Duplicated private draft cannot be reached on public URL',code==404)
            code, original_preview=req('/admin/page/preview?id='+saved_draft_id)
            ensure('Source page remains unchanged when copied',
                   code==200 and 'Updated safe draft' in original_preview)
            code, revision_form=req('/admin/page?id='+saved_draft_id)
            ensure('Owner may browse private page snapshots after two saves',
                   code==200 and 'Private page revision history' in revision_form
                   and 'name="action" value="restore"' in revision_form
                   and 'name="history_revision" value="1"' in revision_form
                   and 'Preview revision' in revision_form)
            historic_url='/admin/page/history?id='+saved_draft_id+'&revision=1'
            code, historical_text=req(historic_url)
            ensure('Historic snapshot is privately viewable with escaped original text',
                   code==200 and 'PRIVATE SAVED REVISION' in historical_text
                   and 'Unpublished &lt;b&gt;preview&lt;/b&gt;' in historical_text
                   and 'Private &lt;script&gt;not_public()&lt;/script&gt;' in historical_text
                   and '<script>not_public()</script>' not in historical_text)
            code, _, history_cache, _=req_binary(historic_url)
            ensure('Historical page text is marked private and never cacheable',
                   code==200 and 'no-store' in history_cache)
            code, historical_missing=req('/admin/page/history?id='+saved_draft_id+'&revision=999')
            ensure('Unknown historical versions never reveal current page text',code==404)
            code, historical_wrong_method=req(historic_url,{'csrf':edit_csrf.group(1)})
            ensure('Historical snapshot preview is read-only GET',code==405)
            code, _=req('/admin/page?id='+saved_draft_id,{
                'csrf':'bad','action':'restore','revision':'2','history_revision':'1'
            })
            ensure('History restoration must pass administrator CSRF',code==403)
            code, stale_history=req('/admin/page?id='+saved_draft_id,{
                'csrf':edit_csrf.group(1),'action':'restore',
                'revision':'1','history_revision':'1'
            })
            ensure('History restoration rejects invalid or replayed versions',
                   code==200 and 'Invalid saved page revision' in stale_history)
            code, _=req('/admin/page?id='+saved_draft_id,{
                'csrf':edit_csrf.group(1),'action':'restore',
                'revision':'2','history_revision':'1'
            })
            ensure('Earlier private draft content is restored as new draft revision',
                   code==303)
            code, restored_preview=req('/admin/page/preview?id='+saved_draft_id)
            ensure('Restored historic draft text is safely escaped, not published',
                   code==200 and 'Unpublished &lt;b&gt;preview&lt;/b&gt;' in restored_preview
                   and 'Private &lt;script&gt;not_public()&lt;/script&gt;' in restored_preview
                   and 'Updated safe draft' not in restored_preview)
            code, still_private=req('/p/secret-preview')
            ensure('History restoration cannot accidentally publish a saved draft',code==404)
            code, replay=req('/admin/page?id='+saved_draft_id,{
                'csrf':edit_csrf.group(1),'action':'restore',
                'revision':'2','history_revision':'1'
            })
            ensure('Replaying an old page-restore form does not overwrite newer text',
                   code==200 and 'Page changed; reload before restoring.' in replay)
            # Authenticated Visual Design Studio: correct theme and escaped homepage content.
            code, studio = req('/admin/design')
            ensure('Design Studio available after migration', code == 200 and 'Noir Atelier' in studio and 'Velvet Club' in studio)
            studio_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', studio)
            studio_revision=re.search(r'name="page_revision" value="(\d+)"',studio)
            ensure('Studio has anti-CSRF token and homepage revision',
                   studio_csrf is not None and studio_revision is not None)
            ensure('Owner can edit all three homepage information cards',
                   all(f'name="highlight_{i}_title"' in studio
                       and f'name="highlight_{i}_body"' in studio for i in (1,2,3)))
            ensure('Studio renders all three safely escaped live information-card previews',
                   all(f'id="preview-highlight-title-{i}"' in studio
                       and f'id="preview-highlight-body-{i}"' in studio
                       for i in (1,2,3)))
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
                'page_revision':studio_revision.group(1),
                'title':'Hello <studio>', 'body':'A safe and elegant welcome',
                'highlight_1_title':'Owner <welcome>',
                'highlight_1_body':'A & community note',
                'highlight_2_title':'Member accounts',
                'highlight_2_body':'Dedicated account access with privacy-first safeguards.',
                'highlight_3_title':'Made personal',
                'highlight_3_body':'Pages and styling curated by your site administrator.'
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
            code, home_editor=req('/admin/page?id='+home_id)
            home_revision=re.search(r'name="revision" value="(\d+)"',home_editor)
            ensure('Homepage editor carries current revision and locks reserved URL',
                   code==200 and home_revision is not None
                   and 'readonly aria-describedby="page-home-slug-note"' in home_editor)
            code, denied_home_rename=req('/admin/page?id='+home_id,{
                'csrf':studio_csrf.group(1),'revision':home_revision.group(1),
                'slug':'renamed-home','title':'Broken home','body':'Do not relocate homepage',
                'status':'published'
            })
            ensure('Owner may not rename or accidentally publish the homepage under another URL',
                   code==200 and 'homepage URL slug is reserved' in denied_home_rename)
            code, still_private_home=req('/')
            ensure('Rejected homepage rename retains private draft state',
                   code==503 and 'Broken home' not in still_private_home)
            code, _ = req('/admin/page?id='+home_id, {
                'csrf':studio_csrf.group(1),'id':home_id,'slug':'home',
                'revision':home_revision.group(1),
                'title':'Hello <studio>', 'body':'A safe and elegant welcome', 'status':'published'
            })
            ensure('Homepage publishing is separate explicit action', code == 303)
            code, public_home = req('/')
            ensure('Published homepage uses selected layout and escapes text',
                   code == 200 and 'theme-rose-ivory' in public_home and 'Hello &lt;studio&gt;' in public_home and 'Hello <studio>' not in public_home)
            ensure('Custom homepage information cards render as escaped public plain text',
                   'Owner &lt;welcome&gt;' in public_home
                   and 'A &amp; community note' in public_home
                   and 'Owner <welcome>' not in public_home)
            code, stale_studio=req('/admin/design',{
                'csrf':studio_csrf.group(1),'theme':'noir-gold','accent':'#d9ae75',
                'image_side':'right','image_size':'large','hero_asset':'none',
                'cutout_x':'50','cutout_y':'50','cutout_width':'80',
                'page_revision':studio_revision.group(1),
                'title':'Stale studio overwrite','body':'Must not replace newer page'
            })
            ensure('Stale Design Studio form rolls back its theme and homepage text',
                   code==422 and 'Homepage changed; reload Design Studio' in stale_studio)
            code, still_home=req('/')
            ensure('Stale studio POST did not change public page',code==200
                   and 'Hello &lt;studio&gt;' in still_home and 'Stale studio overwrite' not in still_home)
            code, unsigned_asset = req('/admin/design/asset/999')
            ensure('Authorized but missing uploaded image is 404', code == 404)
            # Real multipart uploads: a transparent PNG defaults private, even on a published homepage.
            import struct, zlib
            def sample_png():
                def chunk(name, data):
                    return struct.pack('>I',len(data))+name+data+struct.pack('>I',zlib.crc32(name+data)&0xffffffff)
                return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR',struct.pack('>IIBBBBB',2,2,8,6,0,0,0))
                        + chunk(b'IDAT',zlib.compress(b'\x00'+b'\xff\xaa\x99\x00'*2 + b'\x00'+b'\xff\xaa\x99\x00'*2)) + chunk(b'IEND',b''))
            def upload_design(public_ok, image_bytes=None):
                boundary='ACMultipartTestBoundary'
                items=[('csrf',studio_csrf.group(1))]
                if public_ok: items.append(('public_use_confirm','1'))
                parts=[]
                for key,value in items:
                    parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode())
                parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="design_image"; filename="cutout.png"\r\nContent-Type: image/png\r\n\r\n').encode()+(sample_png() if image_bytes is None else image_bytes)+b'\r\n')
                parts.append(f'--{boundary}--\r\n'.encode())
                request=urllib.request.Request(f'http://127.0.0.1:{port}/admin/design/upload',
                    data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
                try: resp=opener.open(request,timeout=HTTP_TIMEOUT)
                except urllib.error.HTTPError as err: resp=err
                with resp: return resp.status,resp.read().decode('utf-8', errors='replace')
            metadata = b'GPSComment=private'
            meta_chunk = (struct.pack('>I',len(metadata)) + b'tEXt' + metadata
                          + struct.pack('>I',zlib.crc32(b'tEXt'+metadata)&0xffffffff))
            clean_design = sample_png()
            code, _ = upload_design(True, clean_design[:33] + meta_chunk + clean_design[33:])
            ensure('Public decorative upload rejects EXIF/location-style PNG notes', code == 422)
            code, _ = upload_design(False)
            ensure('Private transparent PNG upload accepted', code == 303)
            code, studio = req('/admin/design')
            ids = re.findall(r'name="hero_asset" value="(\d+)"', studio)
            ensure('Private image appears in authenticated library', code == 200 and len(ids)>=1 and 'Private preview only' in studio)
            private_id=ids[0]
            private_revision=re.search(r'name="page_revision" value="(\d+)"',studio)
            ensure('Private image workspace carries refreshed homepage revision',
                   private_revision is not None)
            code, private_image=req('/admin/design/asset/'+private_id)
            ensure('Administrator can preview private image', code == 200 and 'PNG' in private_image)
            code, _ = req('/admin/design', {
                'csrf':studio_csrf.group(1),'theme':'velvet-plum','accent':'#f19ad0',
                'image_side':'right','image_size':'large','hero_asset':private_id,
                'cutout_x':'42','cutout_y':'38','cutout_width':'88',
                'page_revision':private_revision.group(1),
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
            public_revision=re.search(r'name="page_revision" value="(\d+)"',studio)
            ensure('Public-safe image workspace carries updated homepage revision',
                   public_revision is not None)
            ensure('Public-safe image is new and listed', code == 200 and public_id != private_id and 'Public-safe asset' in studio)
            code, _=req('/admin/design', {
                'csrf':studio_csrf.group(1),'theme':'noir-gold','accent':'#d9ae75',
                'image_side':'left','image_size':'medium','hero_asset':public_id,
                'cutout_x':'37','cutout_y':'71','cutout_width':'62',
                'page_revision':public_revision.group(1),
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
            code, _, private_moderation_cache, _=req_binary('/admin/moderation')
            ensure('Private reports and performer status cannot be browser/proxy cached',
                   code==200 and 'no-store' in private_moderation_cache)
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
            ensure('Draft image uploader offers a named draft selector',
                   f'<option value="{draft_id}">#{draft_id} — Hidden safety demo</option>' in moderation
                   and 'id="draft-image-content"' in moderation)
            ensure('Performer roster selector lists drafts with no roster',
                   f'id="roster-draft"' in moderation
                   and 'Choose a draft without a roster' in moderation)
            ensure('Future tier assignment is staging-only on the draft',
                   'name="assign_draft_tier"' not in moderation
                   and 'value="assign_draft_tier"' in moderation
                   and 'Staging only — no access granted' in moderation)
            code, _ = req('/admin/moderation', {
                'csrf':'bad','action':'assign_draft_tier','content_id':draft_id,
                'expected_tier_id':'','tier_id':tier_id
            })
            ensure('Future tier association requires moderator CSRF',code == 403)
            code, _ = req('/admin/moderation', {
                'csrf':moderation_token.group(1),'action':'assign_draft_tier',
                'content_id':draft_id,'expected_tier_id':'','tier_id':tier_id
            })
            ensure('Private draft accepts only staged future tier association',code == 303)
            code, moderation=req('/admin/moderation')
            ensure('Draft remains pending and the future tier is selected',
                   code == 200 and 'draft / pending' in moderation
                   and f'name="expected_tier_id" value="{tier_id}"' in moderation)
            code, stale_tier = req('/admin/moderation', {
                'csrf':moderation_token.group(1),'action':'assign_draft_tier',
                'content_id':draft_id,'expected_tier_id':'','tier_id':''
            })
            ensure('Stale draft tier association cannot overwrite',
                   code == 422 and 'Draft tier changed; reload' in stale_tier)
            code, invalid_tier = req('/admin/moderation', {
                'csrf':moderation_token.group(1),'action':'assign_draft_tier',
                'content_id':draft_id,'expected_tier_id':tier_id,'tier_id':'999999999'
            })
            ensure('Draft refuses nonexistent tier',
                   code == 422 and 'Choose an existing membership tier' in invalid_tier)
            # Draft edits require administrator CSRF and the exact previously
            # displayed title. A concurrent moderator edit must not overwrite.
            code, _ = req('/admin/moderation', {
                'csrf': 'wrong-token', 'action':'rename_draft',
                'content_id':draft_id,'expected_title':'Hidden safety demo',
                'title':'Unapproved change'
            })
            ensure('Private draft rename rejects invalid CSRF', code == 403)
            marked_title='Hidden safety demo <img src=x onerror=alert(1)>'
            code, _ = req('/admin/moderation', {
                'csrf': moderation_token.group(1),'action':'rename_draft',
                'content_id':draft_id,'expected_title':'Hidden safety demo',
                'title':marked_title
            })
            ensure('Private draft title can be edited without publishing', code == 303)
            code, moderation = req('/admin/moderation')
            ensure('Private title escaped and still unpublished',
                   code == 200 and '&lt;img src=x onerror=alert(1)&gt;' in moderation
                   and '<img src=x onerror=alert(1)>' not in moderation
                   and 'draft / pending' in moderation)
            code, stale_error = req('/admin/moderation', {
                'csrf': moderation_token.group(1),'action':'rename_draft',
                'content_id':draft_id,'expected_title':'Hidden safety demo',
                'title':'Overwritten stale title'
            })
            ensure('Stale draft title edit safely rejected',
                   code == 422 and 'Draft changed; reload the moderation queue' in stale_error)
            code, _ = req('/admin/moderation', {
                'csrf': moderation_token.group(1),'action':'rename_draft',
                'content_id':draft_id,'expected_title':marked_title,
                'title':'Hidden safety demo'
            })
            ensure('Private draft title can be revised with fresh expected title', code == 303)
            # Admin-only multipart upload can stage a private pending image
            # but can never bypass performer consent or moderation gates.
            def upload_draft(csrf_value, data, draft=draft_id):
                boundary='ACPrivateDraftImageBoundary'
                fields=[('csrf',csrf_value),('action','upload_draft_image'),('content_id',draft)]
                parts=[]
                for field,value in fields:
                    parts.append((f'--{boundary}\r\nContent-Disposition: form-data; '
                                  f'name="{field}"\r\n\r\n{value}\r\n').encode())
                parts.append((f'--{boundary}\r\nContent-Disposition: form-data; '
                              'name="draft_image"; filename="pending.png"\r\n'
                              'Content-Type: image/png\r\n\r\n').encode()+data+b'\r\n')
                parts.append(f'--{boundary}--\r\n'.encode())
                request=urllib.request.Request(
                    f'http://127.0.0.1:{port}/admin/moderation',
                    data=b''.join(parts),
                    headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
                try: response=opener.open(request,timeout=HTTP_TIMEOUT)
                except urllib.error.HTTPError as err: response=err
                with response:
                    return response.status,response.read().decode('utf-8',errors='replace')
            code, _ = upload_draft('bad-csrf', sample_png())
            ensure('Pending media upload requires valid admin CSRF', code == 403)
            code, _ = upload_draft(moderation_token.group(1), b'<?php echo "unsafe";')
            ensure('Pending media upload rejects non-image payload bytes', code == 422)
            # Metadata-bearing uploaded PNGs must fail before the private
            # storage move or media-row insertion, even for an authenticated moderator.
            privacy_png = sample_png()
            privacy_chunk = (struct.pack('>I', len(b'GPSComment=private')) + b'tEXt'
                             + b'GPSComment=private'
                             + struct.pack('>I', zlib.crc32(b'tEXtGPSComment=private') & 0xffffffff))
            privacy_png = privacy_png[:33] + privacy_chunk + privacy_png[33:]
            code, _ = upload_draft(moderation_token.group(1), privacy_png)
            ensure('Private draft upload refuses PNG location/text metadata',
                   code == 422 and 'Image contains metadata or an unsupported image structure.' in _)
            code, _ = upload_draft(moderation_token.group(1), sample_png())
            ensure('Valid draft image is staged privately without approval', code == 303)
            code, moderation = req('/admin/moderation')
            ensure('Admin queue shows image pending rather than approved',
                   code == 200 and 'Pending images' in moderation
                   and re.search(r'<td>'+re.escape(draft_id)
                       +r'</td><td>Hidden safety demo</td>.*?<td>1 pending</td>',moderation,
                       flags=re.DOTALL) is not None)
            pending_media_id=subprocess.check_output(
                php_fixture+['draft-media-id',draft_id],cwd=root,text=True).strip()
            ensure('Pending image has a private database ID',
                   re.fullmatch(r'[1-9][0-9]*',pending_media_id) is not None)
            code, private_draft = req('/admin/moderation/draft/'+draft_id)
            ensure('Moderator draft detail stays private and never leaks storage keys',
                   code == 200 and 'Private draft review' in private_draft
                   and 'Hidden safety demo' in private_draft
                   and f'href="/admin/moderation/image/{pending_media_id}"' in private_draft
                   and 'private_storage_key' not in private_draft
                   and f'src="/media/{pending_media_id}"' not in private_draft)
            code, restricted_draft = mod_req('/admin/moderation/draft/'+draft_id)
            ensure('Moderation-only account can review private draft but not publish it',
                   code == 200 and 'Private draft review' in restricted_draft
                   and 'approve" type="submit"' not in restricted_draft)
            code, _ = req('/admin/moderation/draft/'+draft_id,{'csrf':moderation_token.group(1)})
            ensure('Private draft detail rejects mutation methods',code == 405)
            code, _ = req('/admin/moderation/draft/999999999')
            ensure('Unknown private draft detail returns neutral 404',code == 404)
            code, moderation = req('/admin/moderation')
            ensure('Moderator sees a guarded preview link rather than a public media URL',
                   code == 200
                   and f'href="/admin/moderation/image/{pending_media_id}"' in moderation
                   and f'src="/media/{pending_media_id}"' not in moderation)
            code, preview_type, preview_cache, private_preview = req_binary(
                '/admin/moderation/image/' + pending_media_id)
            ensure('Moderator can privately inspect pending image with no-store headers',
                   code == 200 and preview_type.startswith('image/png')
                   and 'no-store' in preview_cache
                   and private_preview.startswith(b'\x89PNG\r\n\x1a\n'))
            # Reject another staged image independently, without deleting its
            # private evidence or affecting this still-pending image.
            code, _ = upload_draft(moderation_token.group(1), sample_png())
            ensure('Additional private draft image can be staged for review', code == 303)
            rejected_media_id=subprocess.check_output(
                php_fixture+['draft-media-id',draft_id],cwd=root,text=True).strip()
            ensure('Additional pending media has separate ID',rejected_media_id != pending_media_id)
            code, _ = req('/admin/moderation', {
                'csrf':'bad-token','action':'reject_draft_image',
                'media_id':rejected_media_id,'note':'Unsafe image reported for review.'
            })
            ensure('Individual media rejection requires CSRF',code == 403)
            code, _ = req('/admin/moderation', {
                'csrf':moderation_token.group(1),'action':'reject_draft_image',
                'media_id':rejected_media_id,'note':'short'
            })
            ensure('Individual media rejection requires meaningful reason',code == 422)
            reason='Pending image rejected <script>alert(1)</script>.'
            code, _ = req('/admin/moderation', {
                'csrf':moderation_token.group(1),'action':'reject_draft_image',
                'media_id':rejected_media_id,'note':reason
            })
            ensure('Moderation can reject only one pending draft image',code == 303)
            code, history = req('/admin/moderation?audit_content='+draft_id)
            ensure('Moderators can review private audited title and image changes',
                   code == 200 and 'draft_renamed' in history and 'image_rejected' in history
                   and 'Private moderation history for content #'+draft_id in history
                   and '&lt;script&gt;alert(1)&lt;/script&gt;' in history
                   and reason not in history)
            code, invalid_history = req('/admin/moderation?audit_content=not-a-number')
            ensure('Malformed private audit filter does not disclose another record',
                   code == 200 and 'Private moderation history for content #' not in invalid_history)
            code, _, _, rejected_binary=req_binary('/admin/moderation/image/'+rejected_media_id)
            ensure('Rejected image preview unavailable to moderator',
                   code == 404 and not rejected_binary.startswith(b'\x89PNG'))
            code, review_after_reject=req('/admin/moderation/draft/'+draft_id)
            ensure('Draft detail lists rejected image but no forbidden preview URL',
                   code == 200 and f'Image #{rejected_media_id}' in review_after_reject
                   and f'href="/admin/moderation/image/{rejected_media_id}"' not in review_after_reject
                   and f'href="/admin/moderation/image/{pending_media_id}"' in review_after_reject)
            code, _, _, retained_preview=req_binary('/admin/moderation/image/'+pending_media_id)
            ensure('Unrelated pending image remains privately previewable',
                   code == 200 and retained_preview.startswith(b'\x89PNG'))
            code, again = req('/admin/moderation', {
                'csrf':moderation_token.group(1),'action':'reject_draft_image',
                'media_id':rejected_media_id,'note':'Second attempt to reject image again.'
            })
            ensure('Rejected image cannot be reviewed twice',
                   code == 422 and 'Pending private draft image not found.' in again)
            code, moderation=req('/admin/moderation')
            ensure('Only one image remains pending in draft queue',
                   code == 200 and re.search(r'<td>'+re.escape(draft_id)
                       +r'</td><td>Hidden safety demo</td>.*?<td>1 pending</td>',moderation,
                       flags=re.DOTALL) is not None)
            code, _, _, missing_preview = req_binary('/admin/moderation/image/99999999')
            ensure('Unknown draft-image preview is unavailable',
                   code == 404 and not missing_preview.startswith(b'\x89PNG'))
            code, _ = req('/admin/moderation/image/' + pending_media_id, {'unsafe': 'POST'})
            ensure('Administrator draft-image preview accepts GET only', code == 405)
            code, _, _, anonymous_pending_media=req_binary('/media/'+pending_media_id)
            ensure('Private pending media cannot be accessed anonymously',
                   code == 403 and not anonymous_pending_media.startswith(b'\x89PNG'))
            csrf_roster = re.search(r'name="csrf" value="([a-f0-9]{64})"', moderation)
            ensure('Performer roster form has CSRF', csrf_roster is not None)
            code, _ = req('/admin/moderation', {
                'csrf': csrf_roster.group(1), 'action': 'declare_performers',
                'content_id': draft_id, 'performer_count': '2'
            })
            ensure('Declaring performer roster redirects', code == 303)
            code, moderation = req('/admin/moderation')
            ensure('Performer records stay pending', code == 200 and 'Performer verification records' in moderation and 'Assertion received' not in moderation)
            ensure('Roster selector no longer offers a draft that already has a roster',
                   f'<option value="{draft_id}">#{draft_id} — Hidden safety demo</option>' in moderation
                   and moderation.split('id="roster-draft"',1)[1].split('</select>',1)[0].find(
                       f'<option value="{draft_id}">') < 0)
            code, _ = req('/admin/moderation', {
                'csrf': csrf_roster.group(1), 'action': 'declare_performers',
                'content_id': draft_id, 'performer_count': '1'
            })
            ensure('Duplicate roster refused', code >= 400)
            # Private catalog never exposes unverified titles without a member session.
            code, anonymous_library = req('/members/library')
            ensure('Anonymous private library redirects to member sign-in',
                   code == 303 and 'Fixture gallery' not in anonymous_library)
            code, anonymous_detail = req(f'/members/library/{draft_id}')
            ensure('Anonymous gallery details require an authenticated member',
                   code == 303 and 'Private test draft' not in anonymous_detail)
            # Registration creates a pending member, not a verified adult.
            code, registration = req('/join')
            join_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', registration)
            ensure('Member join available after setup', code == 200 and join_token is not None)
            code, pending = req('/join', {'csrf': join_token.group(1), 'email': 'member@test.example', 'password': 'strong-example-member-password'})
            ensure('Signup remains pending', code == 200 and 'pending review' in pending)
            code, member_login = req('/members/login')
            code, _, member_login_cache, _=req_binary('/members/login')
            ensure('Login/session forms are never browser or proxy cacheable',
                   code==200 and 'no-store' in member_login_cache)
            member_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', member_login)
            ensure('Member login CSRF', code == 200 and member_token is not None)
            code, _ = req('/members/login', {'csrf': member_token.group(1), 'email': 'member@test.example', 'password': 'strong-example-member-password'})
            ensure('Member login redirects', code == 303)
            code, library = req('/members/library')
            ensure('Pending member sees neutral private library, no unverified content',
                   code == 200 and 'No content is currently available' in library
                   and 'Fixture gallery' not in library
                   and '<img' not in library)
            code, no_creator = req('/members/creator')
            ensure('Regular member has no automatic creator privileges',
                   code == 403 and 'save_draft' not in no_creator)
            code, rejected_post = req('/members/library', {'test': 'unsafe'})
            ensure('Member library forbids mutating HTTP methods', code == 405)
            code, forbidden_detail = req(f'/members/library/{draft_id}')
            ensure('Pending member does not see draft gallery detail',
                   code == 404 and 'Private test draft' not in forbidden_detail
                   and 'Protected gallery image' not in forbidden_detail)
            code, absent_detail = req('/members/library/999999999')
            ensure('Unknown gallery and denied gallery have identical external response',
                   code == 404 and absent_detail == forbidden_detail)
            code, malformed_detail = req('/members/library/1unsafe')
            ensure('Malformed gallery URL is not interpreted as a valid content ID',
                   code == 404 and 'Private test draft' not in malformed_detail)
            code, method_detail = req(f'/members/library/{draft_id}', {'unsafe': 'POST'})
            ensure('Private gallery detail only allows GET', code == 405)
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
            ensure('Member portal has only this account subscription records',
                   'Membership records' in portal
                   and 'No membership records are linked to this account.' in portal)
            # Local owner controls creator tools on THIS installation.
            # No creator application, public approval or vendor signup exists.
            code, creator_admin = req('/admin/creators')
            creator_token=re.search(r'name="csrf" value="([a-f0-9]{64})"',creator_admin)
            ensure('Local website owner sees creator controls after migration',
                   code == 200 and creator_token is not None
                   and 'Local creator accounts' in creator_admin
                   and 'No central creator service' not in creator_admin
                   and 'name="action" value="provision"' in creator_admin)
            code, _=mod_req('/admin/creators')
            ensure('Moderator cannot manage local creator privileges',code == 403)
            code, _=req('/admin/creators',{
                'csrf':'bad','action':'provision','email':'member@test.example',
                'display_name':'Cajun <script>alert(1)</script>'})
            ensure('Creator assignment requires administrator CSRF',code == 403)
            code, _=req('/admin/creators',{
                'csrf':creator_token.group(1),'action':'provision',
                'email':'member@test.example','display_name':'Cajun <script>alert(1)</script>'})
            ensure('Local owner grants private creator tools to confirmed member',code == 303)
            code, creator_admin = req('/admin/creators')
            creator_row=re.search(
                r'<tr><td>(\d+)</td><td>member@test.example</td>'
                r'<td>Cajun &lt;script&gt;alert\(1\)&lt;/script&gt;</td>'
                r'<td>enabled</td><td>0</td>',creator_admin)
            ensure('Creator list escapes user-supplied names and shows local grant',
                   code == 200 and creator_row is not None
                   and '<script>alert(1)</script>' not in creator_admin)
            creator_id=creator_row.group(1)
            code, empty_older=req('/admin/creators?before='+creator_id)
            ensure('Admin creator pagination uses a numeric keyset cursor',
                   code == 200 and '<td>member@test.example</td>' not in empty_older)
            code, member_home=req('/members')
            ensure('Enabled local creator sees draft workspace link',
                   code == 200 and 'href="/members/creator"' in member_home)
            code, creator_home=req('/members/creator')
            creator_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',creator_home)
            ensure('Creator workspace has only private text draft tools',
                   code == 200 and creator_csrf is not None
                   and 'Cajun &lt;script&gt;alert(1)&lt;/script&gt;' in creator_home
                   and 'name="action" value="save_draft"' in creator_home
                   and 'type="file"' not in creator_home
                   and 'Publish' not in creator_home)
            code, _=req('/members/creator',{
                'csrf':'wrong','action':'save_draft','draft_id':'','revision':'',
                'title':'Unsafe draft','summary':'A private creator planning note only.'})
            ensure('Creator draft save requires member CSRF',code == 403)
            draft_title='Private <b>clip</b>'
            draft_summary='This is only a <script>alert(1)</script> private planning note.'
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':'','revision':'','title':draft_title,'summary':draft_summary})
            ensure('Member-created content is text-only and private',code == 303)
            code, creator_home=req('/members/creator')
            private_id_match=re.search(r'name="draft_id" value="(\d+)"',creator_home)
            ensure('Creator can safely view only own escaped drafts',
                   code == 200 and private_id_match is not None
                   and 'Private &lt;b&gt;clip&lt;/b&gt;' in creator_home
                   and '&lt;script&gt;alert(1)&lt;/script&gt;' in creator_home
                   and '<script>alert(1)</script>' not in creator_home)
            creator_draft_id=private_id_match.group(1)
            code, stale=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':creator_draft_id,'revision':'999',
                'title':'Overwritten stale draft',
                'summary':'A stale revision must not overwrite the creator draft.'})
            ensure('Creator updates reject stale draft revisions',
                   code == 422 and 'Draft changed; reload your workspace.' in stale)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':creator_draft_id,'revision':'1',
                'title':'New private title','summary':'This remains a private text-only plan.'})
            ensure('Creator can edit the original private draft with matching revision',
                   code == 303)
            code, creator_admin=req('/admin/creators')
            ensure('Owner sees only numeric private draft counts, not private text',
                   code == 200 and re.search(
                       r'<td>'+re.escape(creator_id)
                       +r'</td><td>member@test.example</td>.*?<td>enabled</td><td>1</td>',
                       creator_admin,flags=re.DOTALL) is not None
                   and 'New private title' not in creator_admin
                   and f'href="/admin/creators/drafts/{creator_id}"' in creator_admin)
            code, owner_plans=req('/admin/creators/drafts/'+creator_id)
            ensure('Local owner can privately review escaped creator planning text',
                   code == 200 and 'Private creator plans' in owner_plans
                   and 'New private title' in owner_plans
                   and 'Status: private, unpublished.' in owner_plans
                   and '<script>alert(1)</script>' not in owner_plans)
            code, _=mod_req('/admin/creators/drafts/'+creator_id)
            ensure('Moderator cannot inspect local creator owner-only plans',code == 403)
            code, unknown_plans=req('/admin/creators/drafts/999999999')
            ensure('Unknown local creator plans have no metadata leakage',
                   code == 404 and 'New private title' not in unknown_plans)
            code, _=req('/admin/creators/drafts/'+creator_id,{
                'csrf':creator_token.group(1),'draft_id':creator_draft_id})
            ensure('Owner private creator draft inspection is GET-only',code == 405)
            code, _=req('/admin/creators',{
                'csrf':creator_token.group(1),'action':'suspend',
                'creator_id':creator_id,'revision':'1'})
            ensure('Local owner can revoke creator planning access',code == 303)
            code, creator_denied=req('/members/creator')
            ensure('Suspended creator loses access to saved draft text immediately',
                   code == 403 and 'New private title' not in creator_denied)
            code, suspended_owner_plans=req('/admin/creators/drafts/'+creator_id)
            ensure('Site owner retains private plans for review after creator suspension',
                   code == 200 and 'New private title' in suspended_owner_plans
                   and 'suspended' in suspended_owner_plans)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':'','revision':'','title':'Attempt while suspended',
                'summary':'Suspended creator cannot save another private draft.'})
            ensure('Suspended creator cannot write new drafts',code == 403)
            code, stale_status=req('/admin/creators',{
                'csrf':creator_token.group(1),'action':'restore',
                'creator_id':creator_id,'revision':'1'})
            ensure('Stale admin creator status transitions fail closed',
                   code == 422 and 'Creator status changed' in stale_status)
            code, _=req('/admin/creators',{
                'csrf':creator_token.group(1),'action':'restore',
                'creator_id':creator_id,'revision':'2'})
            ensure('Local owner can restore creator planning privileges',code == 303)
            code, restored_creator=req('/members/creator')
            ensure('Restoring creator does not lose private drafts or publish them',
                   code == 200 and 'New private title' in restored_creator
                   and 'Status: private' in restored_creator)
            code, _=req('/admin/creators',{
                'csrf':creator_token.group(1),'action':'rename',
                'creator_id':creator_id,'revision':'2',
                'display_name':'Stale creator name'})
            ensure('Local creator display name stale update safely rejected',code == 422)
            code, _=req('/admin/creators',{
                'csrf':creator_token.group(1),'action':'rename',
                'creator_id':creator_id,'revision':'3',
                'display_name':'Owner renamed <Creator>'})
            ensure('Owner changes local creator label without another password',code == 303)
            code, renamed_creator=req('/members/creator')
            ensure('Local member workspace displays escaped owner-updated creator label',
                   code == 200 and 'Owner renamed &lt;Creator&gt;' in renamed_creator
                   and 'New private title' in renamed_creator
                   and 'Status: private' in renamed_creator)
            # Submission moves only a private TEXT plan to this site's
            # restricted moderation queue. It never publishes media.
            code, initial_review=mod_req('/admin/creator-review')
            review_token=re.search(r'name="csrf" value="([a-f0-9]{64})"',initial_review)
            ensure('Local moderator can enter creator text-review queue',
                   code == 200 and 'No submitted private plans' in initial_review)
            code, invalid_cursor=mod_req('/admin/creator-review?after=invalid*cursor')
            ensure('Malformed text-review pagination cursor is rejected safely',
                   code == 422 and 'Invalid private review queue cursor.' in invalid_cursor)
            code, normal_queue=mod_req('/admin/creator-review')
            ensure('Malformed cursor does not change the regular queue',
                   code == 200 and 'No submitted private plans' in normal_queue)
            code, _=req('/members/creator',{
                'csrf':'bad','action':'submit_review',
                'draft_id':creator_draft_id,'revision':'2'})
            ensure('Creator submission requires member CSRF',code == 403)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'submit_review',
                'draft_id':creator_draft_id,'revision':'2'})
            ensure('Creator submits private text plan for moderator feedback',code == 303)
            code, submitted_creator=req('/members/creator')
            ensure('Submitted creator draft remains unpublished and cannot be edited',
                   code == 200 and 'Status: submitted' in submitted_creator
                   and 'editing is locked' in submitted_creator
                   and 'name="action" value="submit_review"' not in submitted_creator)
            code, locked_edit=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':creator_draft_id,'revision':'3',
                'title':'Tamper with locked plan',
                'summary':'This submitted planning draft should remain immutable.'})
            ensure('Creator cannot edit pending submitted plan',code == 422)
            code, review_queue=mod_req('/admin/creator-review')
            review_token=re.search(r'name="csrf" value="([a-f0-9]{64})"',review_queue)
            ensure('Moderator sees only the submitted local text-plan details',
                   code == 200 and review_token is not None
                   and 'New private title' in review_queue
                   and 'name="decision"' in review_queue
                   and 'value="changes"' in review_queue
                   and 'value="rejected"' in review_queue
                   and 'value="approved"' not in review_queue)
            code, _=mod_req('/admin/creator-review',{
                'csrf':'wrong','draft_id':creator_draft_id,
                'revision':'3','decision':'changes',
                'feedback':'Please improve this private story outline.'})
            ensure('Moderator creator feedback requires CSRF',code == 403)
            code, rejected_approval=mod_req('/admin/creator-review',{
                'csrf':review_token.group(1),'draft_id':creator_draft_id,
                'revision':'3','decision':'approved',
                'feedback':'The reviewer may not approve or publish this plan.'})
            ensure('Moderator cannot approve a creator text plan',
                   code == 422 and 'Invalid creator review decision.' in rejected_approval)
            feedback='Please remove <script>alert(1)</script> from the plan and clarify your story.'
            code, _=mod_req('/admin/creator-review',{
                'csrf':review_token.group(1),'draft_id':creator_draft_id,
                'revision':'3','decision':'changes','feedback':feedback})
            ensure('Moderator requests private creator revisions',code == 303)
            code, changes_page=req('/members/creator')
            ensure('Requested changes show only escaped feedback to creator',
                   code == 200 and 'Status: changes' in changes_page
                   and 'Private moderator feedback' in changes_page
                   and '&lt;script&gt;alert(1)&lt;/script&gt;' in changes_page
                   and '<script>alert(1)</script>' not in changes_page
                   and 'Your private review feedback history' in changes_page)
            code, direct_resubmit=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'submit_review',
                'draft_id':creator_draft_id,'revision':'4'})
            ensure('Changes must be saved before resubmission',code == 422)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':creator_draft_id,'revision':'4',
                'title':'Revised private plan',
                'summary':'This updated plan contains no public media or identity documents.'})
            ensure('Creator can revise text after private feedback',code == 303)
            code, revised_page=req('/members/creator')
            ensure('Saving changed draft returns it to private and clears feedback',
                   code == 200 and 'Status: private' in revised_page
                   and 'Private moderator feedback:' not in revised_page
                   and 'Revised private plan' in revised_page)
            ensure('Review feedback history survives edits without unsafe script rendering',
                   'Your private review feedback history' in revised_page
                   and '&lt;script&gt;alert(1)&lt;/script&gt;' in revised_page
                   and '<script>alert(1)</script>' not in revised_page)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'submit_review',
                'draft_id':creator_draft_id,'revision':'5'})
            ensure('Creator can resubmit revised private plan',code == 303)
            code, second_queue=mod_req('/admin/creator-review')
            ensure('Revised plan is privately queued with no approval affordance',
                   code == 200 and 'Revised private plan' in second_queue
                   and 'value="approved"' not in second_queue)
            code, stale_review=mod_req('/admin/creator-review',{
                'csrf':review_token.group(1),'draft_id':creator_draft_id,
                'revision':'3','decision':'rejected',
                'feedback':'Stale moderator input should not override newer creator text.'})
            ensure('Stale moderator review revisions are rejected',code == 422)
            code, _=mod_req('/admin/creator-review',{
                'csrf':review_token.group(1),'draft_id':creator_draft_id,
                'revision':'6','decision':'rejected',
                'feedback':'Rejected: this private plan needs a different concept.'})
            ensure('Moderator can reject submitted private text',code == 303)
            code, final_creator=req('/members/creator')
            ensure('Rejected creator plan remains private and noneditable',
                   code == 200 and 'Status: rejected' in final_creator
                   and 'Rejected: this private plan' in final_creator
                   and 'Revised private plan' in final_creator
                   and 'name="action" value="submit_review"' not in final_creator)
            ensure('Creator privately sees both past review decisions after rejection',
                   'Your private review feedback history' in final_creator
                   and 'Submitted revision #3' in final_creator
                   and 'Submitted revision #6' in final_creator
                   and '&lt;script&gt;alert(1)&lt;/script&gt;' in final_creator)
            code, rejected_edit=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':creator_draft_id,'revision':'7',
                'title':'Attempt to bypass rejection',
                'summary':'Rejected private plans should not be editable.'})
            ensure('Rejected private creator draft cannot be edited',code == 422)
            code, owner_review=req('/admin/creators/drafts/'+creator_id)
            ensure('Owner retains text, feedback and local numeric review history after rejection',
                   code == 200 and 'Status: rejected, unpublished.' in owner_review
                   and 'Rejected: this private plan' in owner_review
                   and '<h2>Text-review history</h2>' in owner_review
                   and '<td>submitted</td>' in owner_review
                   and '<td>rejected</td>' in owner_review)
            ensure('Owner can review two independent private feedback snapshots',
                   '<h2>Private review feedback history</h2>' in owner_review
                   and 'Submitted revision #3' in owner_review
                   and 'Submitted revision #6' in owner_review
                   and '&lt;script&gt;alert(1)&lt;/script&gt;' in owner_review
                   and '<script>alert(1)</script>' not in owner_review)
            code, none_pending=mod_req('/admin/creator-review')
            ensure('Reviewed plan no longer appears in submitted queue',
                   code == 200 and 'No submitted private plans' in none_pending)
            # A creator may withdraw a new submitted plan before any
            # moderator decision. Other actor and stale changes stay denied.
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':'','revision':'',
                'title':'Withdrawable private plan',
                'summary':'This privately saved plan may be withdrawn before a review decision.'})
            ensure('Member can create another private plan for withdrawal',code == 303)
            code, second_creator_page=req('/members/creator')
            withdraw_match=re.search(r'name="draft_id" value="(\d+)"',second_creator_page)
            ensure('New private plan has a per-account draft ID',
                   code == 200 and withdraw_match is not None)
            withdraw_draft_id=withdraw_match.group(1)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'submit_review',
                'draft_id':withdraw_draft_id,'revision':'1'})
            ensure('New creator plan enters pending private review',code == 303)
            code, withdraw_queue=mod_req('/admin/creator-review')
            ensure('Withdrawal fixture appears only in local moderator queue',
                   code == 200 and 'Withdrawable private plan' in withdraw_queue)
            code, pending_withdraw=req('/members/creator')
            ensure('Submitted creator sees withdrawal action while review is pending',
                   code == 200 and 'name="action" value="withdraw_review"' in pending_withdraw)
            code, _=req('/members/creator',{
                'csrf':'bad','action':'withdraw_review',
                'draft_id':withdraw_draft_id,'revision':'2'})
            ensure('Withdrawal requires member CSRF',code == 403)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'withdraw_review',
                'draft_id':withdraw_draft_id,'revision':'2'})
            ensure('Creator can withdraw undecided private submission',code == 303)
            code, after_withdraw=req('/members/creator')
            ensure('Withdrawn plan is editable, remains private and retains draft text',
                   code == 200 and 'Withdrawable private plan' in after_withdraw
                   and 'Status: private' in after_withdraw
                   and 'Save private changes' in after_withdraw
                   and 'Withdraw submitted text plan' not in after_withdraw)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'withdraw_review',
                'draft_id':withdraw_draft_id,'revision':'3'})
            ensure('Repeated withdrawal is denied',code == 422)
            code, withdrawn_review=mod_req('/admin/creator-review',{
                'csrf':review_token.group(1),'draft_id':withdraw_draft_id,
                'revision':'2','decision':'changes',
                'feedback':'Review must not resume a withdrawn private plan.'})
            ensure('Moderator cannot review a withdrawn private submission',
                   code == 422 and 'Only submitted private plans can be reviewed.' in withdrawn_review)
            # Reversible private archive is not deletion or moderation
            # approval; only this creator can archive an unsent plan.
            code, _=req('/members/creator',{
                'csrf':'wrong','action':'archive_draft',
                'draft_id':withdraw_draft_id,'revision':'3'})
            ensure('Archiving private planning text requires member CSRF',code == 403)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'archive_draft',
                'draft_id':withdraw_draft_id,'revision':'3'})
            ensure('Creator archives a private unsent text plan without deletion',code == 303)
            code, archived_view=req('/members/creator')
            ensure('Archived creator plan stays private and offers restoration',
                   code == 200 and 'Withdrawable private plan' in archived_view
                   and 'Status: archived' in archived_view
                   and 'name="action" value="restore_draft"' in archived_view
                   and 'not deleted' in archived_view)
            code, archived_edit=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'save_draft',
                'draft_id':withdraw_draft_id,'revision':'4',
                'title':'Edit an archived plan',
                'summary':'Archived text content must not be editable until restored.'})
            ensure('Archived draft cannot be directly edited',code == 422)
            code, archived_submit=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'submit_review',
                'draft_id':withdraw_draft_id,'revision':'4'})
            ensure('Archived draft cannot bypass private submission gate',code == 422)
            code, stale_archive=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'restore_draft',
                'draft_id':withdraw_draft_id,'revision':'3'})
            ensure('Stale restore revision fails',code == 422)
            code, _=req('/members/creator',{
                'csrf':creator_csrf.group(1),'action':'restore_draft',
                'draft_id':withdraw_draft_id,'revision':'4'})
            ensure('Creator restores their own archived text for editing',code == 303)
            code, restored_private=req('/members/creator')
            ensure('Restored creator plan is private, not public, and editable',
                   code == 200 and 'Withdrawable private plan' in restored_private
                   and 'Status: private' in restored_private
                   and 'name="action" value="archive_draft"' in restored_private)
            code, owner_events=req('/admin/creators/drafts/'+creator_id)
            ensure('Owner sees retained numeric archived/restored creator event history',
                   code == 200 and '<td>archived</td>' in owner_events
                   and '<td>restored</td>' in owner_events
                   and 'Withdrawable private plan' in owner_events)
            code, _=req('/p/private-clip')
            ensure('Private creator draft has no public page route',code == 404)
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
            code, _, _, rejected_preview = req_binary('/admin/moderation/image/' + pending_media_id)
            ensure('Rejecting a draft revokes private administrator preview',
                   code == 404 and not rejected_preview.startswith(b'\x89PNG'))
            code, blocked = upload_draft(moderation_token.group(1), sample_png())
            ensure('Rejected content cannot accept additional private image uploads',
                   code == 422 and 'Only private, unreviewed drafts accept images' in blocked)
            code, moderation = req('/admin/moderation')
            ensure('Rejected content remains blocked', code == 200 and 'rejected / rejected' in moderation)
            # Real authenticated two-factor enrollment, verification, recovery and replay.
            code, security = req('/admin/security')
            ensure('MFA settings available to installed administrator', code == 200 and 'Set up authenticator' in security)
            security_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', security).group(1)
            code, refused = req('/admin/security', {'csrf':'bad','action':'begin','password':'strong-example-admin-password'})
            ensure('MFA enrollment requires CSRF', code == 403)
            code, refused = req('/admin/security', {'csrf':security_csrf,'action':'begin','password':'wrong-password'})
            ensure('MFA enrollment requires correct administrator password', code == 422)
            code, _ = req('/admin/security', {'csrf':security_csrf,'action':'begin','password':'strong-example-admin-password'})
            ensure('Authorized MFA setup redirects', code == 303)
            code, setup = req('/admin/security')
            secret_match = re.search(r'<code>([A-Z2-7]{32})</code>', setup)
            ensure('Setup key displayed privately after password verification',code==200 and secret_match is not None)
            secret = base64.b32decode(secret_match.group(1))
            def totp(secret_bytes):
                counter = int(time.time()//30)
                digest = hmac.new(secret_bytes, struct.pack('>Q',counter),hashlib.sha1).digest()
                offset = digest[-1] & 15
                value = (int.from_bytes(digest[offset:offset+4],'big') & 0x7fffffff) % 1000000
                return f'{value:06d}'
            otp = totp(secret)
            code, _ = req('/admin/security', {'csrf':'bad','action':'confirm','factor':otp})
            ensure('MFA confirmation requires CSRF', code == 403)
            code, recovery_page = req('/admin/security', {'csrf':security_csrf,'action':'confirm','factor':otp})
            pre = re.search(r'<pre class="mfa-recovery-codes">([A-F0-9\n]+)</pre>', recovery_page)
            codes = pre.group(1).splitlines() if pre else []
            ensure('MFA enrollment confirms and displays ten one-time recovery codes',code==200 and len(codes)==10)
            ensure('MFA page does not expose plaintext secret after activation',secret_match.group(1) not in recovery_page)
            code, dashboard = req('/admin')
            ensure('Fully verified session retains dashboard access',code==200)
            logout_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"',dashboard).group(1)
            code, _=req('/admin/logout',{'csrf':logout_csrf})
            ensure('MFA enabled administrator can sign out',code==303)
            code, signin = req('/admin/login')
            signin_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',signin).group(1)
            code, _=req('/admin/login',{'csrf':signin_csrf,'email':'owner@example.test','password':'strong-example-admin-password'})
            ensure('MFA enrolled password redirects only to second factor',code==303)
            code, _=req('/admin')
            ensure('Password-only session cannot open dashboard',code==302)
            code, challenge = req('/admin/mfa')
            challenge_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',challenge).group(1)
            ensure('MFA challenge form present',code==200 and 'Complete sign in' in challenge)
            code, _=req('/admin/mfa',{'csrf':'bad','factor':codes[0]})
            ensure('MFA challenge requires CSRF',code==403)
            code, _=req('/admin/mfa',{'csrf':challenge_csrf,'factor':otp})
            ensure('Enrollment TOTP time step cannot be replayed',code==401)
            code, _=req('/admin/mfa',{'csrf':challenge_csrf,'factor':codes[0]})
            ensure('Single-use recovery code completes sign in',code==303)
            code, _=req('/admin')
            ensure('Recovered MFA session reaches dashboard',code==200)
            _,dashboard=req('/admin')
            logout_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',dashboard).group(1)
            req('/admin/logout',{'csrf':logout_csrf})
            code, signin=req('/admin/login')
            signin_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',signin).group(1)
            req('/admin/login',{'csrf':signin_csrf,'email':'owner@example.test','password':'strong-example-admin-password'})
            code, challenge=req('/admin/mfa')
            challenge_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',challenge).group(1)
            code, _=req('/admin/mfa',{'csrf':challenge_csrf,'factor':codes[0]})
            ensure('Consumed recovery code rejected on second login',code==401)
            code, _=req('/admin/mfa',{'csrf':challenge_csrf,'factor':codes[1]})
            ensure('Different unused recovery code signs in',code==303)
            code, security=req('/admin/security')
            security_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',security).group(1)
            ensure('Recovery use is reported without exposing codes',code==200 and 'Remaining recovery codes:</strong> 8' in security)
            code, _=req('/admin/security',{'csrf':security_csrf,'action':'disable','password':'wrong-password','factor':codes[2]})
            ensure('MFA cannot be disabled with wrong password',code==422)
            code, _=req('/admin/security',{'csrf':security_csrf,'action':'disable','password':'strong-example-admin-password','factor':codes[2]})
            ensure('Reauthenticated MFA disable works',code==303)
            code, dashboard=req('/admin')
            ensure('Security settings disable retains admin session',code==200)
            logout_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',dashboard).group(1)
            req('/admin/logout',{'csrf':logout_csrf})
            code, signin=req('/admin/login')
            signin_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',signin).group(1)
            code, _=req('/admin/login',{'csrf':signin_csrf,'email':'owner@example.test','password':'strong-example-admin-password'})
            ensure('Password sign in resumes only after verified MFA disable',code==303)
            code, dashboard=req('/admin')
            ensure('Dashboard recovered after disabling MFA',code==200)

            # End-to-end navigation of >50 pages using the actual authenticated
            # owner UI; no fixture draft is published or available publicly.
            subprocess.check_output(php_fixture+['seed-pages'],cwd=root)
            code, first_pages=req('/admin/pages')
            older_link=re.search(r'href="/admin/pages\?before=(\d+)"',first_pages)
            ensure('Owner page dashboard exposes next-page link with 51 private drafts',
                   code==200 and older_link is not None
                   and 'Bulk fixture page 50' in first_pages)
            code, older_pages=req('/admin/pages?before='+older_link.group(1))
            ensure('Owner can reach older saved pages and return to newest',
                   code==200 and 'Back to newest pages' in older_pages
                   and '<td>home</td>' in older_pages
                   and 'Bulk fixture page 00' in older_pages)
            code, still_draft=req('/p/bulk-fixture-00')
            ensure('Paginated private synthetic page remains unpublished',
                   code==404)
            code, bad_cursor=req('/admin/pages?before=0')
            ensure('Zero numeric page cursor refused without browsing content',
                   code==422 and 'Invalid page list cursor' in bad_cursor)
            code, found_pages=req('/admin/pages?q=Bulk%20fixture%20page%2000')
            ensure('Owner page search locates one matching private title only',
                   code==200 and 'Bulk fixture page 00' in found_pages
                   and 'Bulk fixture page 01' not in found_pages)
            code, draft_filtered=req('/admin/pages?status=draft')
            draft_next=re.search(r'href="/admin/pages\?before=(\d+)&amp;status=draft"',draft_filtered)
            ensure('Owner draft-status filter preserves private paging',
                   code==200 and draft_next is not None
                   and 'Bulk fixture page 50' in draft_filtered
                   and '<td>safe-page</td>' not in draft_filtered)
            code, all_search=req('/admin/pages?q=Bulk%20fixture%20page')
            filtered_next=re.search(r'href="/admin/pages\?before=(\d+)&amp;q=Bulk%20fixture%20page"',all_search)
            ensure('Private text search preserves filter in paginated navigation',
                   code==200 and filtered_next is not None)
            code, filtered_tail=req('/admin/pages?before='+filtered_next.group(1)
                                    +'&q=Bulk%20fixture%20page')
            ensure('Filtered second page reaches final saved draft',
                   code==200 and 'Bulk fixture page 00' in filtered_tail)
            code, invalid_search=req('/admin/pages?q=%00')
            ensure('Control characters in local page search are refused',
                   code==422 and 'Invalid page search' in invalid_search)

            subprocess.check_output(php_fixture+['seed-members'],cwd=root)
            code, member_list=req('/admin/members')
            older_members=re.search(r'href="/admin/members\?before=(\d+)"',member_list)
            ensure('Owner can paginate read-only directory beyond 50 member accounts',
                   code==200 and older_members is not None
                   and 'bulk-member-50@example.test' in member_list
                   and 'password_hash' not in member_list)
            email_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',member_list)
            ensure('Owner email lookup is private CSRF POST, not a GET query',
                   code==200 and email_csrf is not None
                   and 'method="post" action="/admin/members/find"' in member_list)
            code, denied_email_get=req('/admin/members/find')
            ensure('Private member email search refuses GET requests',code==405)
            code, denied_email_csrf=req('/admin/members/find',{
                'csrf':'invalid','email':'bulk-member-50@example.test'})
            ensure('Private member email search requires CSRF',code==403)
            code, matched_email=req('/admin/members/find',{
                'csrf':email_csrf.group(1),'email':'BULK-MEMBER-50@EXAMPLE.TEST'})
            ensure('Exact email POST finds only a local member, with no credential disclosure',
                   code==200 and 'bulk-member-50@example.test' in matched_email
                   and 'Review account safety' in matched_email
                   and 'password_hash' not in matched_email)
            code, missing_email=req('/admin/members/find',{
                'csrf':email_csrf.group(1),'email':'unknown-local-member@example.test'})
            ensure('Unknown member email returns safe empty lookup result',
                   code==200 and 'No local member account matched' in missing_email)
            code, invalid_email=req('/admin/members/find',{
                'csrf':email_csrf.group(1),'email':'bad\x00@example.test'})
            ensure('Malformed email lookup is refused',code==422 and 'Invalid email lookup' in invalid_email)
            sample_id=re.search(r'<tr><td>(\d+)</td><td>bulk-member-50@example\.test</td>',member_list)
            ensure('Read-only owner member directory exposes numeric local IDs',
                   sample_id is not None)
            code, exact_member=req('/admin/members?member_id='+sample_id.group(1))
            ensure('Owner can find one exact local account without scanning every page',
                   code==200 and 'bulk-member-50@example.test' in exact_member
                   and 'bulk-member-49@example.test' not in exact_member
                   and 'Back to all local members' in exact_member)
            code, default_member_search=req('/admin/members?status=all&member_id=')
            ensure('Submitting empty optional account ID keeps all-member directory usable',
                   code==200 and 'bulk-member-50@example.test' in default_member_search)
            code, wrong_local_status=req('/admin/members?member_id='+sample_id.group(1)
                                         +'&status=suspended')
            ensure('Exact local ID search respects status filter',
                   code==200 and 'bulk-member-50@example.test' not in wrong_local_status)
            code, bad_lookup=req('/admin/members?member_id=0')
            ensure('Zero account ID rejected',code==422 and 'Invalid numeric member lookup' in bad_lookup)
            code, mixed_lookup=req('/admin/members?member_id='+sample_id.group(1)
                                   +'&before=2')
            ensure('Member lookup cannot mix exact ID with cursor',code==422)
            code, older_list=req('/admin/members?before='+older_members.group(1))
            ensure('Owner reaches older local members via numeric cursor',
                   code==200 and 'bulk-member-00@example.test' in older_list
                   and 'Back to newest members' in older_list)
            code, suspended_list=req('/admin/members?status=suspended')
            ensure('Owner status filter includes locally suspended accounts only',
                   code==200 and 'bulk-member-49@example.test' in suspended_list
                   and 'bulk-member-50@example.test' not in suspended_list)
            code, bad_member_cursor=req('/admin/members?before=0')
            ensure('Invalid member cursor rejected',code==422
                   and 'Invalid directory cursor' in bad_member_cursor)
            code, bad_member_filter=req('/admin/members?status=verified')
            ensure('Unverified account cannot be represented as certified member',
                   code==422 and 'Invalid member filter' in bad_member_filter)
            # Owner-manageable local member account state is NOT age, performer
            # consent or paid entitlement. A strong manager step-up is separate
            # from the normal long-lived encrypted administrator session.
            member_manage='/admin/members/manage?id='+sample_id.group(1)
            code, safety_page=req(member_manage)
            safety_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"', safety_page)
            ensure('Owner can review only one local member safety record',
                   code==200 and safety_csrf is not None
                   and 'bulk-member-50@example.test' in safety_page
                   and 'Private safety audit' in safety_page
                   and 'Local account status:' in safety_page
                   and 'name="action" value="activate"' not in safety_page
                   and 'name="action" value="approve"' not in safety_page)
            # Separate member browser, with its own member-only cookie jar.
            # The owner browser's admin cookie is never shared with members.
            member_test_jar=http.cookiejar.CookieJar()
            member_test_opener=urllib.request.build_opener(NoRedirect,
                urllib.request.HTTPCookieProcessor(member_test_jar))
            def independent_member_req(path, data=None, browser=None):
                payload=urllib.parse.urlencode(data).encode() if data is not None else None
                opener_for_member=browser if browser is not None else member_test_opener
                try:
                    response=opener_for_member.open(
                        f'http://127.0.0.1:{port}{path}',
                        data=payload,timeout=HTTP_TIMEOUT)
                except urllib.error.HTTPError as err:
                    response=err
                with response:
                    return response.status,response.read().decode('utf-8',errors='replace')
            code, member_login_form=independent_member_req('/members/login')
            member_login_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',member_login_form)
            ensure('Separate local member browser can authenticate pending account',
                   code==200 and member_login_csrf is not None)
            code, _=independent_member_req('/members/login',{
                'csrf':member_login_csrf.group(1),
                'email':'bulk-member-50@example.test',
                'password':'disposable-fixture-long-password'
            })
            ensure('Synthetic pending local member signs in without gaining adult media',
                   code==303)
            code, initial_member_account=independent_member_req('/members')
            ensure('Pending account session is active before owner safety action',
                   code==200 and 'bulk-member-50@example.test' in initial_member_account
                   and 'Account status: <strong>pending</strong>' in initial_member_account)
            # Save old cookie to replay AFTER the owner restores the account;
            # visiting while suspended may itself revoke an active session.
            stale_member_jar=http.cookiejar.CookieJar()
            for old_cookie in member_test_jar:
                stale_member_jar.set_cookie(old_cookie)
            stale_member_browser=urllib.request.build_opener(NoRedirect,
                urllib.request.HTTPCookieProcessor(stale_member_jar))
            code, no_token=req(member_manage,{
                'csrf':'bad','member_id':sample_id.group(1),
                'action':'suspend','expected_status':'pending',
                'reason':'Potential local account security issue'
            })
            ensure('Changing member safety status requires CSRF', code==403)
            code, mismatch=req(member_manage,{
                'csrf':safety_csrf.group(1),'member_id':'9999999',
                'action':'suspend','expected_status':'pending',
                'reason':'Member ID mismatch must be refused'
            })
            ensure('Member safety form cannot target a different member ID',code==422)
            code, _=req(member_manage,{
                'csrf':safety_csrf.group(1),'member_id':sample_id.group(1),
                'action':'verify','manager_password':'strong-example-admin-password'
            })
            ensure('One manager password verification opens a short safety window',
                   code==303)
            code, unlocked=req(member_manage)
            unlocked_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"', unlocked)
            ensure('Manager may suspend pending local account without repeated passwords',
                   code==200 and unlocked_csrf is not None
                   and 'name="action" value="suspend"' in unlocked
                   and 'name="manager_password"' not in unlocked)
            code, _=req(member_manage,{
                'csrf':unlocked_csrf.group(1),'member_id':sample_id.group(1),
                'action':'suspend','expected_status':'pending',
                'reason':'Policy concern <script>private()</script> pending review'
            })
            ensure('Owner suspension succeeds only through protected POST',code==303)
            code, suspended=req(member_manage)
            ensure('Owner sees escaped private suspension reason and pending-only restoration',
                   code==200 and '&lt;script&gt;private()&lt;/script&gt;' in suspended
                   and '<script>private()</script>' not in suspended
                   and 'name="action" value="restore_pending"' in suspended
                   and 'name="expected_status" value="suspended"' in suspended)
            code, kicked_member=independent_member_req('/members')
            ensure('Suspending member immediately invalidates authenticated member account session',
                   code==303 and 'bulk-member-50@example.test' not in kicked_member)
            code, stale_member=req(member_manage,{
                'csrf':unlocked_csrf.group(1),'member_id':sample_id.group(1),
                'action':'suspend','expected_status':'pending',
                'reason':'A stale member status cannot be overwritten'
            })
            ensure('Stale member safety form rejected without lost status audit',
                   code==422 and 'Member account changed; reload before continuing.' in stale_member)
            code, _=req(member_manage,{
                'csrf':unlocked_csrf.group(1),'member_id':sample_id.group(1),
                'action':'restore_pending','expected_status':'suspended',
                'reason':'Restore account only to pending for follow-up'
            })
            ensure('Suspended local member can return only to pending state',code==303)
            code, restored=req(member_manage)
            ensure('Both local safety decisions remain audited without activation',
                   code==200 and '<td>pending</td><td>suspended</td>' in restored
                   and '<td>suspended</td><td>pending</td>' in restored
                   and 'name="action" value="suspend"' in restored
                   and 'name="expected_status" value="pending"' in restored)
            code, old_cookie_after_restore=independent_member_req(
                '/members',browser=stale_member_browser)
            ensure('Old pre-suspension cookie cannot revive after pending-only restore',
                   code==303 and 'bulk-member-50@example.test' not in old_cookie_after_restore)
            code, twice=req(member_manage,{
                'csrf':unlocked_csrf.group(1),'member_id':sample_id.group(1),
                'action':'restore_pending','expected_status':'suspended',
                'reason':'Repeated restoration should not activate member'
            })
            ensure('Replayed restore may not promote or alter pending member',
                   code==422 and 'Member account changed; reload before continuing.' in twice)
            extra_audit_member=subprocess.check_output(
                php_fixture+['seed-member-safety-audit'],cwd=root,text=True).strip()
            ensure('Private safety fixture uses the same local member ID',
                   extra_audit_member==sample_id.group(1))
            code, long_audit=req(member_manage)
            older_audit=re.search(r'id='+sample_id.group(1)
                                  +r'&amp;audit_before=(\d+)',long_audit)
            ensure('Old local member security actions remain accessible after 20 changes',
                   code==200 and older_audit is not None
                   and 'Disposable security review 10' in long_audit
                   and 'Policy concern &lt;script&gt;private()&lt;/script&gt;' not in long_audit)
            code, old_audit=req(member_manage+'&audit_before='+older_audit.group(1))
            ensure('Owner can navigate to original escaped suspension reason',
                   code==200 and 'Back to newest member safety actions' in old_audit
                   and 'Policy concern &lt;script&gt;private()&lt;/script&gt;' in old_audit)
            code, denied_audit=req(member_manage+'&audit_before=invalid')
            ensure('Invalid local member safety audit cursor denied',
                   code==422 and 'Invalid member safety audit cursor' in denied_audit)

            # Signed-in members can rotate their own password; neither site
            # ownership nor age/payment entitlement is inferred by signing in.
            code, rotated_login=independent_member_req('/members/login')
            rotated_login_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',rotated_login)
            ensure('Restored pending member can sign in anew using own credentials',
                   code==200 and rotated_login_csrf is not None)
            code, _=independent_member_req('/members/login',{
                'csrf':rotated_login_csrf.group(1),
                'email':'bulk-member-50@example.test',
                'password':'disposable-fixture-long-password'
            })
            ensure('Restored member must authenticate again after suspended cookie revocation',
                   code==303)
            code, personal_account=independent_member_req('/members')
            ensure('Local member profile offers password security without entitlement grant',
                   code==200 and 'href="/members/password"' in personal_account
                   and 'Account status: <strong>pending</strong>' in personal_account)
            old_rotation_jar=http.cookiejar.CookieJar()
            for session_cookie_before_rotation in member_test_jar:
                old_rotation_jar.set_cookie(session_cookie_before_rotation)
            old_rotation_browser=urllib.request.build_opener(NoRedirect,
                urllib.request.HTTPCookieProcessor(old_rotation_jar))
            code, password_form=independent_member_req('/members/password')
            password_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',password_form)
            ensure('Self-service password form requires signed-in member session',
                   code==200 and password_csrf is not None
                   and 'name="current_password"' in password_form
                   and 'name="confirm_password"' in password_form)
            code, _=independent_member_req('/members/password',{
                'csrf':'invalid','current_password':'disposable-fixture-long-password',
                'new_password':'disposable-member-rotated-password',
                'confirm_password':'disposable-member-rotated-password'
            })
            ensure('Member password security refuses missing CSRF',code==403)
            code, denied_rotation=independent_member_req('/members/password',{
                'csrf':password_csrf.group(1),
                'current_password':'incorrect-member-password',
                'new_password':'disposable-member-rotated-password',
                'confirm_password':'disposable-member-rotated-password'
            })
            ensure('Member cannot rotate password without knowing current credentials',
                   code==422 and 'Current member password is incorrect.' in denied_rotation)
            code, same_rotation=independent_member_req('/members/password',{
                'csrf':password_csrf.group(1),
                'current_password':'disposable-fixture-long-password',
                'new_password':'disposable-fixture-long-password',
                'confirm_password':'disposable-fixture-long-password'
            })
            ensure('Reusing exact member password is refused',
                   code==422 and 'Choose a different member password.' in same_rotation)
            code, _=independent_member_req('/members/password',{
                'csrf':password_csrf.group(1),
                'current_password':'disposable-fixture-long-password',
                'new_password':'disposable-member-rotated-password',
                'confirm_password':'disposable-member-rotated-password'
            })
            ensure('Own-account password rotation signs out all prior sessions',
                   code==303)
            code, signed_out=independent_member_req('/members')
            ensure('Successful member password change revokes current cookie',code==303)
            code, stale_password_session=independent_member_req(
                '/members',browser=old_rotation_browser)
            ensure('Old member cookie cannot revive after password rotation',code==303)
            code, new_login=independent_member_req('/members/login')
            new_login_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',new_login)
            ensure('Member sees one-time signed-in session password change confirmation',
                   code==200 and new_login_csrf is not None
                   and 'Your member password was updated.' in new_login)
            code, repeat_login=independent_member_req('/members/login?changed=1')
            ensure('Forged success URL cannot replay one-time password change confirmation',
                   code==200 and 'Your member password was updated.' not in repeat_login)
            code, old_password_denied=independent_member_req('/members/login',{
                'csrf':new_login_csrf.group(1),
                'email':'bulk-member-50@example.test',
                'password':'disposable-fixture-long-password'
            })
            ensure('Old member credential is rejected after rotation',code==400)
            code, _=independent_member_req('/members/login',{
                'csrf':new_login_csrf.group(1),
                'email':'bulk-member-50@example.test',
                'password':'disposable-member-rotated-password'
            })
            ensure('New credential authenticates same pending local member',code==303)
            code, updated_member=independent_member_req('/members')
            ensure('Self-service rotation does not grant verified/paid adult access',
                   code==200 and 'Account status: <strong>pending</strong>' in updated_member
                   and 'Private content and paid access remain unavailable' in updated_member)
            ensure('Account offers member-controlled all-device sign-out',
                   'href="/members/sessions"' in updated_member)
            separate_device_jar=http.cookiejar.CookieJar()
            separate_device_browser=urllib.request.build_opener(NoRedirect,
                urllib.request.HTTPCookieProcessor(separate_device_jar))
            code, device_login_form=independent_member_req(
                '/members/login',browser=separate_device_browser)
            separate_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',device_login_form)
            ensure('Independent second member device has its own login session',
                   code==200 and separate_csrf is not None)
            code, _=independent_member_req('/members/login',{
                'csrf':separate_csrf.group(1),
                'email':'bulk-member-50@example.test',
                'password':'disposable-member-rotated-password'
            },browser=separate_device_browser)
            ensure('Local member can sign in separately on another device',code==303)
            code, other_authenticated=independent_member_req(
                '/members',browser=separate_device_browser)
            ensure('Other device is independently signed in before revocation',
                   code==200 and 'Account status: <strong>pending</strong>' in other_authenticated)
            code, sessions_form=independent_member_req('/members/sessions')
            sessions_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',sessions_form)
            ensure('Only signed-in member sees current-password device security form',
                   code==200 and sessions_csrf is not None
                   and 'name="current_password"' in sessions_form
                   and 'Sign out all member sessions' in sessions_form)
            code, _=independent_member_req('/members/sessions',{
                'csrf':'invalid','current_password':'disposable-member-rotated-password'})
            ensure('All-device member session revocation requires CSRF',code==403)
            code, wrong_device_password=independent_member_req('/members/sessions',{
                'csrf':sessions_csrf.group(1),'current_password':'not-current-password'})
            ensure('All-device member revocation refuses incorrect password',
                   code==422 and 'Current member password is incorrect.' in wrong_device_password)
            code, still_signed_in=independent_member_req(
                '/members',browser=separate_device_browser)
            ensure('Denied device action leaves independently authenticated browser active',
                   code==200)
            code, _=independent_member_req('/members/sessions',{
                'csrf':sessions_csrf.group(1),
                'current_password':'disposable-member-rotated-password'})
            ensure('Verified all-device sign-out succeeds without a password change',
                   code==303)
            code, self_signed_out=independent_member_req('/members')
            code_other, other_signed_out=independent_member_req(
                '/members',browser=separate_device_browser)
            ensure('Session generation revokes current and separate signed-in browsers',
                   code==303 and code_other==303)
            code, device_success_login=independent_member_req('/members/login')
            device_login_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',
                                        device_success_login)
            ensure('A one-use server notice confirms actual device revocation',
                   code==200 and device_login_csrf is not None
                   and 'All existing member sessions were signed out.' in device_success_login
                   and 'Your password was not changed.' in device_success_login)
            code, replay_device_notice=independent_member_req('/members/login?signed_out=1')
            ensure('GET parameters and a second view cannot forge session revocation',
                   code==200 and 'All existing member sessions were signed out.' not in replay_device_notice)
            code, _=independent_member_req('/members/login',{
                'csrf':device_login_csrf.group(1),
                'email':'bulk-member-50@example.test',
                'password':'disposable-member-rotated-password'})
            ensure('All-device signout preserved existing member password',code==303)
            code, back_pending=independent_member_req('/members')
            ensure('All-device signout does not verify age or grant paid adult access',
                   code==200 and 'Account status: <strong>pending</strong>' in back_pending
                   and 'Private content and paid access remain unavailable' in back_pending)

            subprocess.check_output(php_fixture+['seed-content-queue'],cwd=root)
            code, content_first=req('/admin/moderation')
            content_next=re.search(r'href="/admin/moderation\?before_content=(\d+)"',content_first)
            ensure('Moderation dashboard can reach beyond 50 private content records',
                   code==200 and content_next is not None
                   and 'Bulk moderation 50' in content_first)
            code, content_older=req('/admin/moderation?before_content='+content_next.group(1))
            ensure('Older private moderation drafts remain visible for local review',
                   code==200 and 'Bulk moderation 00' in content_older
                   and 'Back to newest content records' in content_older)
            code, content_public=req('/p/bulk-moderation-00')
            ensure('Moderation pagination does not create a public page',code==404)
            code, content_invalid=req('/admin/moderation?before_content=not-an-id')
            ensure('Forged moderation content cursor fails without changing review state',
                   code==422 and 'Invalid moderation queue cursor' in content_invalid)
            subprocess.check_output(php_fixture+['seed-reports'],cwd=root)
            code, report_first=req('/admin/moderation')
            report_next=re.search(r'href="/admin/moderation\?report_after=(0%3A\d+)"',report_first)
            ensure('Open reports are first and older ones remain reachable',
                   code==200 and report_next is not None
                   and 'Disposable open report 50' in report_first
                   and 'Disposable resolved report' not in report_first)
            code, report_older=req('/admin/moderation?report_after='+report_next.group(1))
            ensure('Oldest open report and resolved report survive priority ordering',
                   code==200 and 'Disposable open report 00' in report_older
                   and 'Disposable resolved report' in report_older
                   and 'Back to newest reports' in report_older)
            code, report_invalid=req('/admin/moderation?report_after=1%3A0')
            ensure('Malformed report queue cursor denied without changing report state',
                   code==422 and 'Invalid private report queue cursor' in report_invalid)
            subprocess.check_output(php_fixture+['seed-performers'],cwd=root)
            code, performer_first=req('/admin/moderation')
            performer_next=re.search(r'href="/admin/moderation\?performer_before=(\d+)"',performer_first)
            ensure('Local moderator can paginate pending performer summaries',
                   code==200 and performer_next is not None
                   and 'Older performer records' in performer_first)
            code, performer_second=req('/admin/moderation?performer_before='+performer_next.group(1))
            ensure('Oldest performer summary remains reachable without any approval',
                   code==200 and 'Back to newest performer records' in performer_second
                   and 'Assertion received' not in performer_second
                   and 'Pending' in performer_second)
            code, performer_invalid=req('/admin/moderation?performer_before=not-a-number')
            ensure('Invalid performer cursor fails closed',code==422
                   and 'Invalid performer summary cursor' in performer_invalid)
            binary_ids=subprocess.check_output(php_fixture+['seed-images'],cwd=root,text=True).strip()
            ids_match=re.fullmatch(r'([1-9][0-9]*):([1-9][0-9]*)',binary_ids)
            ensure('51 private pending test images created with numeric references only',
                   ids_match is not None)
            first_image,last_image=ids_match.groups()
            code, images_first=req('/admin/moderation')
            image_next=re.search(r'href="/admin/moderation\?image_before=(\d+)"',images_first)
            ensure('Pending private images list has authenticated older-page link',
                   code==200 and image_next is not None
                   and f'href="/admin/moderation/image/{last_image}"' in images_first
                   and f'href="/admin/moderation/image/{first_image}"' not in images_first)
            code, images_second=req('/admin/moderation?image_before='+image_next.group(1))
            ensure('Oldest unapproved image is still reachable for authenticated moderation',
                   code==200 and f'href="/admin/moderation/image/{first_image}"' in images_second
                   and 'Back to newest pending images' in images_second)
            code, invalid_image_cursor=req('/admin/moderation?image_before=invalid')
            ensure('Malformed pending-image cursor fails closed',
                   code==422 and 'Invalid pending image queue cursor' in invalid_image_cursor)
            audit_fixture_id=subprocess.check_output(php_fixture+['seed-audit'],
                                                     cwd=root,text=True).strip()
            ensure('Synthetic private moderation audit has numeric content ID',
                   re.fullmatch(r'[1-9][0-9]*',audit_fixture_id) is not None)
            audit_path='/admin/moderation?audit_content='+audit_fixture_id
            code, audit_first=req(audit_path)
            audit_next=re.search(r'Older audit actions</a>',audit_first)
            audit_cursor=re.search(r'audit_content='+audit_fixture_id
                                   +r'&amp;audit_before=(\d+)',audit_first)
            ensure('Local moderation audit includes older-page navigation',
                   code==200 and audit_next is not None
                   and audit_cursor is not None
                   and 'Disposable moderator audit 50' in audit_first
                   and 'Disposable moderator audit 00' not in audit_first)
            code, audit_older=req(audit_path+'&audit_before='+audit_cursor.group(1))
            ensure('Historical private moderator notes remain accessible after 50 entries',
                   code==200 and 'Disposable moderator audit 00' in audit_older
                   and 'Back to newest audit actions' in audit_older)
            code, audit_invalid=req(audit_path+'&audit_before=0')
            ensure('Forged moderation audit cursor is rejected',
                   code==422 and 'Invalid audit history cursor' in audit_invalid)
            private_history_id=subprocess.check_output(php_fixture+['seed-page-history'],
                                                       cwd=root,text=True).strip()
            ensure('Private history test fixture returns a numeric page ID',
                   re.fullmatch(r'[1-9][0-9]*',private_history_id) is not None)
            history_path='/admin/page?id='+private_history_id
            code, versions_first=req(history_path)
            history_cursor=re.search(r'Older page revisions</a>',versions_first)
            history_next=re.search(r'id='+private_history_id
                                   +r'&amp;history_before=(\d+)',versions_first)
            ensure('Private page history continues beyond its first 20 saved versions',
                   code==200 and history_cursor is not None and history_next is not None
                   and '<td>History version 23</td>' in versions_first
                   and '<td>History version 1</td>' not in versions_first)
            code, versions_older=req(history_path+'&history_before='+history_next.group(1))
            ensure('Oldest editable page revision remains inspectable to owner',
                   code==200 and '<td>History version 1</td>' in versions_older
                   and 'Back to latest page revisions' in versions_older
                   and 'history_revision" value="1"' in versions_older)
            code, invalid_versions=req(history_path+'&history_before=bad')
            ensure('Invalid page history cursor is denied',
                   code==422 and 'Invalid page history cursor' in invalid_versions)
            current_history_csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',versions_older)
            ensure('Older-version restore uses current authenticated owner CSRF token',
                   current_history_csrf is not None)
            code, _=req(history_path,{
                'csrf':current_history_csrf.group(1),'action':'restore',
                'revision':'23','history_revision':'1'
            })
            ensure('Owner can restore oldest private text after 23 saves',code==303)
            code, restored_history=req(history_path)
            ensure('Oldest revision restored as a new private draft, never auto-published',
                   code==200 and 'History version 1' in restored_history
                   and 'name="revision" value="24"' in restored_history
                   and 'Restore is always a new unpublished draft' in restored_history)
            code, public_history=req('/p/long-history-fixture')
            ensure('Restored old draft is never public',code==404)

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
            code, anonymous_page_preview = req('/admin/page/preview?id='+saved_draft_id)
            ensure('Logged-out administrator cannot view private saved drafts',
                   code == 302 and 'not_public()' not in anonymous_page_preview)
            code, anonymous_history=req('/admin/page/history?id='+saved_draft_id+'&revision=1')
            ensure('Logged-out historical text preview remains private',
                   code==302 and 'not_public()' not in anonymous_history)
            code, _, _, logged_out_preview = req_binary('/admin/moderation/image/' + pending_media_id)
            ensure('Logged-out administrator cannot retrieve pending image bytes',
                   code == 302 and not logged_out_preview.startswith(b'\x89PNG'))
            code, anonymous_draft = req('/admin/moderation/draft/'+draft_id)
            ensure('Logged-out administrator cannot view private draft details',
                   code == 302 and 'Hidden safety demo' not in anonymous_draft)

            # Disposable test only: seed an independently guarded approved
            # gallery, confirm real binary delivery, then revoke consent.
            # This is NOT a real verification provider or public uploader.
            gallery_fixture = subprocess.check_output(
                php_fixture + ['stage-gallery'], cwd=root, text=True).strip()
            gallery_ids = re.fullmatch(r'([1-9][0-9]*):([1-9][0-9]*)', gallery_fixture)
            ensure('Disposable entitled gallery fixture created', gallery_ids is not None)
            gallery_id, media_id = gallery_ids.groups()
            code, member_records = req('/members')
            ensure('Member can view their own synthetic subscription record without payment claim',
                   code == 200 and 'Staged fixture membership' in member_records
                   and 'Disabled for access' in member_records
                   and 'not proof of a completed payment' in member_records)
            code, approved_catalog = req('/members/library')
            ensure('Authorized member sees only approved gallery title',
                   code == 200 and 'Approved local fixture album' in approved_catalog
                   and f'href="/members/library/{gallery_id}"' in approved_catalog)
            code, approved_detail = req('/members/library/' + gallery_id)
            ensure('Authorized member can open guarded gallery detail',
                   code == 200 and 'Approved local fixture album' in approved_detail
                   and f'src="/media/{media_id}"' in approved_detail
                   and 'Protected gallery image' in approved_detail)
            code, content_type, cache_control, png_bytes = req_binary('/media/' + media_id)
            ensure('Protected image bytes delivered with private non-cacheable headers',
                   code == 200 and content_type.startswith('image/png')
                   and 'no-store' in cache_control
                   and png_bytes.startswith(b'\x89PNG\r\n\x1a\n'))
            code, _, _, pending_image_bytes = req_binary('/media/'+pending_media_id)
            ensure('Even verified active members cannot retrieve unreviewed draft images',
                   code == 404 and not pending_image_bytes.startswith(b'\x89PNG'))

            subprocess.check_output(php_fixture + ['revoke-gallery', gallery_id], cwd=root)
            code, revoked_catalog = req('/members/library')
            ensure('Revoked performer consent removes gallery from member library',
                   code == 200 and 'Approved local fixture album' not in revoked_catalog)
            code, revoked_detail = req('/members/library/' + gallery_id)
            ensure('Quarantined gallery detail fails closed without revealing title',
                   code == 404 and 'Approved local fixture album' not in revoked_detail)
            code, _, _, revoked_image = req_binary('/media/' + media_id)
            ensure('Revocation also immediately denies direct private media HTTP requests',
                   code == 404 and not revoked_image.startswith(b'\x89PNG'))
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
