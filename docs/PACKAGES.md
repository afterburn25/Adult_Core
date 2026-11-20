# Signed add-ons and themes — Milestone 4

**Development feature, not a general PHP plugin sandbox.** Adult_Core M4 accepts **data-only** signed ZIP packages, stores them outside the web root, and records activation/selection. It **does not execute uploaded code, load extensions into frontend routes, render selected themes, or update/uninstall installed extensions** yet. Do not describe these as working photo/video plugins or production themes.

## Why these limits exist

Uploaded PHP plugins run with the same privileges as the website. Merely naming a plugin or disabling it in an admin panel does not sandbox its code. M4 therefore accepts only `.css`, `.json`, `.txt`, `.md`, `.png`, `.jpg`, `.jpeg`, and `.webp` under `payload/`; scripts, symlinks and unexpected archive entries are rejected. Additional sandboxing, API contracts, trust review and full upgrade/recovery procedures are prerequisites for executable extensions.

## Package format

```text
example.zip
├── package.json        # Exact signed JSON bytes (UTF-8)
├── package.sig         # Base64 of RSA/ECDSA SHA-256 signature over package.json bytes
└── payload/
    ├── readme.txt
    └── assets/theme.css
```

`package.json` fields:

```json
{
  "id": "example-addon",
  "name": "Example Add-on",
  "type": "module",
  "publisher": "example-author",
  "version": "1.0.0",
  "minimum_core_version": "0.1.0",
  "editions": ["lite", "pro"],
  "required_capabilities": ["fileinfo"],
  "dependencies": [],
  "files": [
    {"path": "readme.txt", "sha256": "<64-character lowercase SHA-256 hex>"}
  ]
}
```

For themes, change `type` to `theme`. The offline builder fills in the `files` array and checks file paths. Modules are accepted only when the installed edition, current capabilities, minimum core version, and **active** dependencies satisfy the manifest. Themes use the same compatibility contract; only one can be selected. Pro modules always refuse installation or activation on Lite.

## Create a publisher key (maintainers, offline)

```sh
mkdir -p /secure/signing-keys
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out /secure/signing-keys/example-author-private.pem
openssl pkey -in /secure/signing-keys/example-author-private.pem -pubout -out example-author.pem
```

Store the **private key only on an offline trusted development machine**. Never upload or commit the private key. Gitignore is **not** a substitute for keeping it out of the project. Administrators explicitly install the corresponding **public** key into `config/trusted-publishers/example-author.pem` via hosting file manager / SSH. The installer **never auto-trusts** an uploaded key. Only publisher IDs with a matching trusted public key can install packages.

## Build and install

```sh
# Example data-only module, run at your developer workstation:
php scripts/build-signed-package.php \
  examples/gallery-addon/manifest.json \
  examples/gallery-addon/payload \
  /secure/signing-keys/example-author-private.pem \
  /tmp/gallery-sample.zip
```

In a *completed and cleaned-up* installation, sign in as an administrator and open `/admin/packages`. Upload the ZIP and click **Verify and install**; the package begins disabled. Verify prerequisites, then click **Enable**. The selected/active state is stored in `storage/packages/registry.json`. Uploaded files live under `storage/packages/modules/<id>` or `storage/packages/themes/<id>` and remain private.

The shared-host core doesn't require the optional `ext-zip`. Package upload uses PHP's bundled `PharData` ZIP reader; if `phar` is disabled, package installation is unavailable, but Lite can still install and operate without add-ons. Maximum compressed package size: **8 MiB**; max uncompressed payload: **16 MiB**; max individual entry: **4 MiB**; max files: **80**. Larger packages and video content should not be distributed as plugin ZIPs.

## Security and state behavior

- Requires authenticated admin privileges, HTTPS (except local loopback), session and POST CSRF token, and the completed-installation-plus-deleted-`install/` gate.
- Signed manifest is checked against the operator-managed allowlist and exact SHA-256 hashes of every declared asset. Unexpected archive files, dangerous paths and symlinks are refused. The package is copied into private staging and verified again before being published; state updates are serialized using a file lock and an atomic rename.
- Enabled modules are checked for signature, installed file hashes, core version, edition, available capabilities and **transitive active dependencies** whenever `isRunnable()` is called. Corrupt packages fail closed.
- An invalid/disabled dependency prevents other dependent modules from qualifying. Disabling a module with enabled dependents is refused. Pro-only modules cannot be installed/activated on Lite.
- Nothing can be loaded directly from an uploaded ZIP. No untrusted CSS is served on the public site yet.
- There is **no package update/uninstall flow** or recovery UI. Replacing files manually will fail the integrity checks. Back up the installation before managing packages.
- A valid publisher signature indicates origin only, **not** that a package is safe or compliant. Future runtime plugins need code audit, isolation, versioned API and a secure update process.

## Tests

```sh
php tests/package_tests.php
php tests/run.php
python3 tests/http_smoke.py
```

The lifecycle test uses a generated **temporary RSA test key**, disposable ZIPs and temporary storage; no permanent signing keys are included. It covers signed ZIP verification, invalid signatures, unsigned/altered files, path traversal, edition checks, dependency activation, runtime rechecks, theme switching and key revocation. Real MySQL/cPanel and administrator-facing upload integration tests still require a compatible host.
