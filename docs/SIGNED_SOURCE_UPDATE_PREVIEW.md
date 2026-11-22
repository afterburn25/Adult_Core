# Read-only source update preview (development)

A maintainer can compare a separately authenticated source release ZIP with the **application source root** of an existing self-hosted Adult_Core installation. The result is a diagnostic only: no files are extracted, replaced or deleted, and no database, license, payment, member, session, media or configuration data is modified.

```sh
php scripts/preview-source-update.php \
  /private/releases/adult-core.zip \
  /private/releases/adult-core.manifest.json \
  /private/releases/adult-core.manifest.sig \
  /trusted/keys/approved-source-public.pem \
  /srv/Adult_Core --json
```

The tool first validates the detached SHA-256 signature with a separately trusted public key and authenticates **every** candidate ZIP file. Only then does it compare source paths against the existing application root, refusing any installed directory or file symlink it would otherwise traverse. It reports candidate source files that are unchanged, missing (`would_add`), or different (`would_replace`). Only signed source paths appear in its output; unlisted customer files are not inspected.

**This is not a production updater or complete update plan.** It does not assess deletions, compatibility, required migrations, rollback, backups, real license authorization, downgrade policy, active PHP workers, live hosting or compliance. Never treat a green preview as permission to deploy. All ten production release workstreams remain open.

Regression: `php tests/signed_source_update_preview_tests.php` runs with a real generated unified ZIP, synthetic signing keys and disposable source fixtures. Requires PHP 8.3+, OpenSSL and ext-zip.
