# Signed runtime transition preflight (offline development tool)

This command connects the earlier private source-stage verification to a **trusted baseline of the currently installed release**. It is a read-only diagnostic. It does **not** perform an update, verify licensing, replace code, drain requests, run schema migrations, copy customer files, roll back a deployment, or authorize public launch.

## Trust requirements

Before running it, retain a separately authenticated source ZIP, detached manifest, detached signature, and pinned publisher public key for **both the installed baseline and the newer candidate**. The installed application must still match the signed baseline byte-for-byte for every selected runtime file. Local modifications or unknown executable PHP under `src/` or `public/` cause a refusal. A complete candidate must already have been copied to a separately verified, private staging directory outside all HTTP document roots.

```sh
php scripts/preflight-source-transition.php \
  /home/account/Adult_Core \
  /home/account/private-release-staging/next-release \
  /private/releases/installed.zip \
  /private/releases/installed.manifest.json \
  /private/releases/installed.manifest.sig \
  /private/releases/new-release.zip \
  /private/releases/new-release.manifest.json \
  /private/releases/new-release.manifest.sig \
  /trusted/keys/approved-source-public.pem
```

Only `VERSION`, `src/**`, `public/**`, and `config/editions/**` are included in the **proposed runtime inventory**. Other package files remain part of signed-release authentication, but are not proposed for this source transition. In particular, a removed `install/index.php` must **never** be recreated by an updater; local customer configuration (`config/license.php`, `config/database.php`, and other secrets), storage, themes, add-on modules, tests, documentation, and maintainer tooling are excluded. Modules and themes need their own independent signed lifecycle and migrations.

The check refuses same-version or older candidates, changes to current signed runtime files, path/symlink conflicts, unexpected executable PHP, and candidates that would remove a signed runtime file. It reauthenticates the candidate ZIP, reruns staged-tree verification, and reports exact prospective runtime additions and replacements. For customer privacy, it does not enumerate or inspect unrelated private storage files.

## Acceptance and limitations

```sh
php tests/signed_runtime_preflight_tests.php
php tests/private_source_stage_verifier_tests.php
php tests/release_distribution_tests.php
```

The report intentionally exposes `ready_for_activation: false`, `rollback_implemented: false`, `licensing_checked: false`, and `migration_compatibility_checked: false`. It is not an approval gate that can flip those flags. A fully supported updater still requires secure host maintenance locking, license and compatibility checks, source and database snapshots, commit/rollback coordination, safe source activation under active requests, deployment evidence and independent review. All ten production release workstreams remain open.
