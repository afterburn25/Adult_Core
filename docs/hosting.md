# Hosting requirements for Lite and Pro

## Lite / shared hosting

1. Put the application **above** the domain's public document root (example `~/Adult_Core/`) and set the domain root to `~/Adult_Core/public/`. Hosting that cannot safely configure this is **not supported**.
2. Use PHP 8.3+ with `pdo_mysql`, `json`, `openssl`, `fileinfo`, `session`. The installer must be able to create private files in `storage/` and `config/`.
3. Set up an HTTPS certificate; both installer POSTs and all administrator routes require HTTPS (except loopback development). Configure and test private PHP session storage under `storage/sessions/` with a writable owner-only directory.
4. Create a fresh empty MySQL/MariaDB database and a DB user with CREATE/INSERT/SELECT privileges plus other schema permissions as needed, using cPanel's MySQL interface.
5. Visit `/install/`, inspect Lite/Pro selection, enter DB+admin settings, and finish. On success the site shows a cleanup-required page.
6. **Manually delete the entire top-level `install/` directory** using the hosting File Manager/SSH. No web-accessible removal action is provided.
7. After cleanup, the M3 administrator sign-in form lives at `/admin/login`; the read-only dashboard is at `/admin`. The public website `/` is still an HTTP 503 placeholder. **Do not use this development version for a public adult website**. Real MySQL/cPanel HTTPS acceptance tests are still pending.

## Pro / VPS

Pro requires all Lite features **plus** a verified local Pro agent/persistent process. `scripts/pro-agent-demo.py` is demonstration-only and does not provide production video/transcoding/streaming services. A real production Pro service and secure provisioning are M7.

## Troubleshooting

- **Installer says PDO MySQL missing:** enable `pdo_mysql` for your PHP version with your hosting provider.
- **Document root check fails:** ensure your virtual host points to the literal `public/` directory rather than the repo root or another symlinked directory.
- **Existing Adult Core tables:** installer never overwrites prior data. Recover manually with a fresh empty DB or a trusted backup.
- **Site locked after installation:** this is by design. Delete the entire top-level `install/` folder, not only the installer index file.
- **Invalid installation state:** restore trustworthy matching marker/app key/database config; don't hand-create an unsigned marker.
- **Shared hosting PHP cron, payments and media:** upcoming milestones. Verify the host allows the planned adult content before deployment.

## M4 package-upload hosting support

The Lite edition does not require the optional `ext-zip` module; the M4 ZIP importer uses `PharData`, so `ext-phar`/ZIP archive support must be available **only to use package uploads**. The admin file-upload limit is 8 MiB and is also constrained by the host's `upload_max_filesize` and `post_max_size`. Install trusted publisher **public** PEM keys in private `config/trusted-publishers/` using cPanel File Manager (or an approved secure method), never in `public/`. Never install the signing private key on a site host. FTP/cPanel setups must preserve the `public/` document-root isolation and mandatory deletion of `install/`. Real Apache/cPanel authenticated upload tests remain pending.
