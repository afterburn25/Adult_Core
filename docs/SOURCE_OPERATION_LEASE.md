# Source-operation coordination lease (CLI-only development tool)

Adult Core still does **not** have a production-safe live updater. This operator-only facility protects a **single private coordination slot** against concurrent offline operators that voluntarily use the same slot. It is deliberately not site maintenance mode; it does not stop HTTP traffic, member changes, database writers, Pro workers, or uncontrolled filesystem access.

## Acquire once

Choose a new or existing owner-only coordination parent **outside and not an ancestor of the installed source tree and outside every web document root**. For example:

```sh
mkdir -m 700 /home/account/private-source-coordination
php scripts/source-operation-lease.php acquire \
  /home/account/Adult_Core \
  /home/account/private-source-coordination
```

The command creates an **exclusive** private `.adult-core-source-operation/` directory and a 0600 `lease.json` receipt. The command prints a random 64-character release token **once**. Store it immediately in an operator-only secrets vault. Avoid terminal transcripts and job logs, and do not pass the token in a process argument, URL, email or public issue. The record contains only the SHA-256 token hash and a fingerprint of the installed source root, not the raw token. The receipt explicitly states `site_maintenance_enforced: false` and `ready_for_activation: false`.

## Inspect and release

```sh
php scripts/source-operation-lease.php status \
  /home/account/Adult_Core /home/account/private-source-coordination
```

`status` never reveals a release token or its digest. It reports `not_acquired`, `operator_coordination_only`, or `incomplete_manual_recovery_required`. To release, deliver your stored token through **standard input**, not shell arguments:

```sh
php scripts/source-operation-lease.php release \
  /home/account/Adult_Core /home/account/private-source-coordination \
  < /path/to/operator-only-token-file
```

The release command verifies the exact site root, receipt structure, SHA-256 token hash, private modes and directory inventory before removing the lease. A wrong token, unexpected file, symlink, corrupted receipt or interrupted acquisition fails closed. **There is no automatic lease expiry, reentry, stale-lock deletion or automatic recovery.** If a receipt is incomplete, retain the evidence and use a separately reviewed recovery procedure; never just delete a lock during possible active work.

## Limits and verification

Existing staging/preflight scripts do not automatically acquire this lease; operators must coordinate voluntarily. The tool does not change the installation, license, database, authentication cookies or customer content. A coordination lease **does not mean** that PHP requests are paused or that a database restore/migration can be performed safely. Before any production updater is accepted, integrate site maintenance across web and workers, exclusive application-level locks, versioned migration checkpoints, backup/rollback and cPanel/VPS tests.

Regression: `php tests/source_operation_lease_tests.php` tests exclusive claims, token-gated release, malformed/stale state, unsafe path refusal, and independent CLI subprocesses. All ten production release gates remain open.
