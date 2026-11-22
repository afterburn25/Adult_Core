# Offline signed source releases (development)

Adult_Core uses **one ZIP for Lite and Pro**, with the verified Cajun Web Pro license selecting the edition during installation. The existing build script writes a SHA-256 checksum; a checksum by itself is **not** an authenticity guarantee. The offline detached signing tools in this document add a distinct authenticity check without claiming that an automatic updater, live server acceptance, or production release is ready.

## Maintainer workflow (offline)

Keep the release-signing private key **outside** the repository, public document root, and hosting deployment. Provision and securely retain an approved RSA/EC OpenSSL signing key and distribute its *public* verification key via a separate trusted channel. Protect it against unapproved signing or replacement.

Build a source archive outside the project, sign its exact contents, then separately verify the detached signature:

```sh
php scripts/build-release.php /private/releases/adult-core.zip
php scripts/sign-source-release.php \
  /private/releases/adult-core.zip \
  /private/keys/source-signing-key.pem \
  /private/releases/adult-core.manifest.json \
  /private/releases/adult-core.manifest.sig
php scripts/verify-source-release.php \
  /private/releases/adult-core.zip \
  /private/releases/adult-core.manifest.json \
  /private/releases/adult-core.manifest.sig \
  /trusted/keys/approved-source-public.pem
```

The signer never accepts an existing manifest/signature destination and refuses keys or outputs inside the source tree. The verifier requires an **independently trusted public key** (never a key bundled in the untrusted ZIP), verifies the raw manifest's OpenSSL SHA-256 signature, then streams and hashes **every** ZIP file. It rejects changed, omitted or extra entries, version mismatches, unsafe paths, case-insensitive path collisions, secret paths, non-regular Unix entries, truncated files, and ZIPs exceeding bounded entry/size limits. It does not extract, execute, update, migrate, contact Cajun Web Pro, or grant any adult-content permission.

If any signature or ZIP check fails, reject the download; never install by ignoring the warning or trusting only the separate SHA-256 file. Retain the detached manifest, detached signature, the independently approved public key fingerprint, and release change-control records.

## Acceptance and explicit limitations

```sh
php tests/signed_source_release_tests.php
php tests/release_distribution_tests.php
php tests/release_security_tests.php
```

This closes only a **development-stage offline source archive verification increment**. There is **no implemented signed atomic updater**, no automatic rollback of deployed executable files, no signer custody or release-channel policy certification, and no permission to upload signed executable add-ons. Production acceptance still requires staging cPanel/VPS deployment and restore drills, independent security/legal review, live approved adult-compliance and payment integration, and completion of all ten release workstreams. Never bundle private signing keys, license keys, customer data, config secrets or installed storage in release packages.
