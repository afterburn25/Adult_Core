"""Opt-in disposable MySQL + real HTTP session integration test.

Run only with the AC_TEST_DB_* vars in docs/TESTING.md, against a NEW disposable
MySQL database. Sets up a temporary COPY of source; does not change the working
repository. The fixture deletes only its ac_ tables after the test.
"""
import base64
import hashlib
import hmac
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

            def req_binary(path):
                try:
                    resp = opener.open(f'http://127.0.0.1:{port}{path}', timeout=4)
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
                try: resp=opener.open(request,timeout=4)
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
                try: response=opener.open(request,timeout=4)
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
            code, _ = req('/admin/moderation', {
                'csrf':moderation_token.group(1),'action':'reject_draft_image',
                'media_id':rejected_media_id,'note':'Pending image rejected for safety review.'
            })
            ensure('Moderation can reject only one pending draft image',code == 303)
            code, _, _, rejected_binary=req_binary('/admin/moderation/image/'+rejected_media_id)
            ensure('Rejected image preview unavailable to moderator',
                   code == 404 and not rejected_binary.startswith(b'\x89PNG'))
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
            member_token = re.search(r'name="csrf" value="([a-f0-9]{64})"', member_login)
            ensure('Member login CSRF', code == 200 and member_token is not None)
            code, _ = req('/members/login', {'csrf': member_token.group(1), 'email': 'member@test.example', 'password': 'strong-example-member-password'})
            ensure('Member login redirects', code == 303)
            code, library = req('/members/library')
            ensure('Pending member sees neutral private library, no unverified content',
                   code == 200 and 'No content is currently available' in library
                   and 'Fixture gallery' not in library
                   and '<img' not in library)
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
            code, _, _, logged_out_preview = req_binary('/admin/moderation/image/' + pending_media_id)
            ensure('Logged-out administrator cannot retrieve pending image bytes',
                   code == 302 and not logged_out_preview.startswith(b'\x89PNG'))

            # Disposable test only: seed an independently guarded approved
            # gallery, confirm real binary delivery, then revoke consent.
            # This is NOT a real verification provider or public uploader.
            gallery_fixture = subprocess.check_output(
                php_fixture + ['stage-gallery'], cwd=root, text=True).strip()
            gallery_ids = re.fullmatch(r'([1-9][0-9]*):([1-9][0-9]*)', gallery_fixture)
            ensure('Disposable entitled gallery fixture created', gallery_ids is not None)
            gallery_id, media_id = gallery_ids.groups()
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
