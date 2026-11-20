# Lite → Pro upgrade — Adult_Core development build

## What the upgrade does

This is **one universal installation**, not separate Lite and Pro websites. The existing published pages, Design Studio themes, uploaded cutouts, memberships, verification/moderation history and user data remain in the same MySQL tables and private directories. The upgrade changes `ac_installations.edition` from `lite` to `pro`, the installed licensed SKU/key and its signed cache, and the HMAC-signed installation marker; the original `installed_at` value is preserved. It **does not** start Pro video streaming or execute new module code.

## Before starting

1. Back up the complete MySQL database, private storage, and configuration **outside the server**, and verify the restore procedure on a staging copy. The temporary snapshot below only covers **three metadata files**; it is not a general backup. Schedule a maintenance window and restrict concurrent uploads/administrator writes.
2. Obtain a separate Cajun Web Pro key for either `SOFTWARE-ADULT-CORE-PRO` (one-time) or `SOFTWARE-ADULT-CORE-PRO-MONTH` (monthly). Register it for the installation's existing domain. Cajun Web Pro handles renewal, expiry, cancellation and payment failure, not Adult_Core. Purchase-page URL is the placeholder https://cajunwebpro.com.
3. Provision or migrate the **existing installation files and database** to a compliant Pro host, if necessary. The server must pass actual `pro_agent`, `persistent_process` and other edition manifest checks. Do not clone a running site across domains or bypass the agent.
4. Confirm install folder has already been manually deleted and the site's signed installation gate is ready.

## Administrator workflow

Open `/admin/upgrade` over HTTPS as an admin with `site.settings.manage`. The form displays host readiness; if missing, the action is disabled. Enter the **new Pro key**, reenter your current admin password, tick the backup verification, and type `UPGRADE`. The action checks CSRF and rate limits, verifies the current signed Lite installation matches the existing DB, and validates the new key/domain against Cajun Web Pro. It refuses a Lite key, mismatched domain, ambiguous/transient API response, missing Pro service or inconsistent DB. No edition is chosen through the form.

## In-place transition and crash recovery

After verification, the server takes a private restricted-permission, HMAC-authenticated snapshot of `config/license.php`, `storage/license-cache.json`, `storage/install-state.json` in `storage/.edition-upgrade-backup/`, then writes `storage/.edition-upgrade-pending` before updating the DB. **All HTTP access locks** while that file is present. Upon verified success, the marker is removed and backup files cleaned; installed Lite data remains intact in-place, but the Pro key and edition are active.

Ordinary write failures invoke best-effort automatic rollback of the edition and the three original files. If PHP crashes midway, the site remains locked. After checking your trusted external backup and server DB access, execute from the **project root on the server terminal**:

```bash
php scripts/recover-edition-upgrade.php --rollback
```

The recovery script is CLI-only and verifies the snapshot signature and file hashes before restoring Lite. It will not migrate, overwrite or recover user/content tables. **Do not manually delete the pending file**, remove the backup or edit the signed marker. If CLI recovery fails, keep the site offline and restore your verified external backup before involving a developer. Re-run all MySQL/host checks and publish only when the site is consistent.

## Test and release limits

The code includes deterministic license API/host capability substitutes in unit and disposable database tests; production uses the real Cajun Web Pro HTTPS API and authenticated local Pro agent. No live paid checkout, renewal settlement, cPanel/VPS installation, Pro video runtime, malware audit or full disaster recovery has been certified. This is a development feature and **not** a production-ready upgrade promise.
