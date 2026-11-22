# Signed previous-release rollback source material — development only

This tool establishes that a verified copy of the **old source release** remains available in a private, inert location while a separately verified newer candidate is staged. It is an audit, not a way to restore a broken website. A source-only rollback cannot undo database schema changes, payments, account mutations, private media changes, or running requests.

## Prepare two independent private stages

First retain the publisher's ZIP, manifest and detached signature for BOTH old and new unified Lite/Pro releases, plus a separately trusted public key. The installed site must match the signed old release exactly for runtime files, as described in [SIGNED_RUNTIME_TRANSITION.md](SIGNED_RUNTIME_TRANSITION.md). Create distinct private stage paths outside every HTTP document root and outside the installed application:

```sh
mkdir -m 700 /home/account/private-release-staging
php scripts/stage-source-release.php \
  /private/releases/old.zip /private/releases/old.manifest.json \
  /private/releases/old.manifest.sig /trusted/keys/approved-source-public.pem \
  /home/account/Adult_Core /home/account/private-release-staging/previous
php scripts/stage-source-release.php \
  /private/releases/new.zip /private/releases/new.manifest.json \
  /private/releases/new.manifest.sig /trusted/keys/approved-source-public.pem \
  /home/account/Adult_Core /home/account/private-release-staging/candidate
```

The paths must be initially absent. Both staged directories remain private, never accessible from the web and never served as active PHP source. The previous release stage is **not** automatically installed or copied onto a live site.

## Verify source recovery material without changing the site

```sh
php scripts/check-source-rollback-material.php \
  /home/account/Adult_Core \
  /home/account/private-release-staging/previous \
  /home/account/private-release-staging/candidate \
  /private/releases/old.zip /private/releases/old.manifest.json \
  /private/releases/old.manifest.sig \
  /private/releases/new.zip /private/releases/new.manifest.json \
  /private/releases/new.manifest.sig \
  /trusted/keys/approved-source-public.pem
```

The CLI first performs the **signed old/new runtime transition preflight** and then independently verifies the old private source stage. It refuses incomplete, tampered, missing or swapped previous-version bytes, source drift, or nested/same source stage directories. A passing report may say `previous_source_stage_verified: true` but must retain `rollback_implemented: false`, `ready_for_activation: false`, and `database_rollback_verified: false`.

## Gaps before a supported updater

Preserved source files are just one recovery dependency. A future release requires customer data and database backups with a tested restore window, verified migration reversibility, safe cPanel/VPS maintenance/activation, ownership and permissions, live-license/edition compatibility, and independent security and deployment audits. Those gates are **not** bypassed by a signed manifest or a clean source-stage audit. A protected database backup itself has its own independent acceptance workflow and is never opened by this command.

Regression: `php tests/signed_runtime_preflight_tests.php` (uses real synthetic-signed old/new ZIPs and disposable stage/install fixtures). The ten production release workstreams remain open.
