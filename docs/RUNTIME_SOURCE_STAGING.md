# Private runtime-only source staging (development)

Adult_Core distributes one signed source ZIP for Lite and Pro. The publisher package contains **source, installer, themes, modules, docs and tests**. Those files must not all be copied to a running website during an update. This offline tool produces a **separate, inert private staging directory** containing only immutable runtime source files selected from an independently verified full-source stage.

## How to prepare a runtime-only copy

First complete `scripts/stage-source-release.php` and `scripts/verify-source-stage.php` using the detached signed ZIP manifest and an independently trusted publisher public key. Keep the signed full source stage **outside every web document root**. Create a private parent for the runtime-only copy (`chmod 700`) outside both the installed application and source stage.

```sh
mkdir -m 700 /home/account/private-runtime-stages
php scripts/stage-runtime-source.php \
  /home/account/Adult_Core \
  /home/account/private-release-staging/candidate \
  /private/releases/new-release.zip \
  /private/releases/new-release.manifest.json \
  /private/releases/new-release.manifest.sig \
  /trusted/keys/approved-source-public.pem \
  /home/account/private-runtime-stages/runtime-candidate
```

All entries in the full source ZIP are independently authenticated before any runtime files are copied. The runtime-only staging allowlist comprises `VERSION`, `src/**`, `public/**`, and `config/editions/**`. Every staged runtime file is rehashed against the signed manifest, stored with owner-only permissions (0600 under 0700 directories), and recorded in a private `.ac-runtime-stage-complete.json` receipt. An incomplete copy retains `.ac-runtime-stage-pending` for manual diagnosis; destinations must be new and may never be overwritten.

Crucially, the runtime stage **never includes** `install/index.php`, `install/`, modules, themes, scripts, tests, docs, `.github`, `config/license.php`, `config/database.php`, private storage, customer media, or any local customer configuration. This separation avoids accidentally recreating the removed browser installer or replacing locally managed content.

## Important limits

**This staged subset is not a complete runnable website or production updater.** Themes and modules have independent edition/license/migration requirements. Nothing switches the current HTTP document root, modifies installed files, checks live license compatibility, authorizes production operation, coordinates database changes, or performs automatic rollback. The report must say `ready_for_activation: false` and `install_authorized: false` even on success.

For future activation work, use the earlier signed transition preflight and independent previous-release source checks. Additional atomic activation, database backup/restore, installation locks and real Lite cPanel/Pro VPS acceptance are still required. The ten production release workstreams remain open.

Regression tests: `php tests/signed_runtime_preflight_tests.php`, which builds and signs disposable unified old/new releases, verifies the full source stage, and asserts installer exclusion, file hashes, private permissions, refusal of unsafe destinations, and preservation of customer data.
