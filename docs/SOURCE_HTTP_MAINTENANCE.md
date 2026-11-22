# Token-bound source-update HTTP maintenance (development only)

This tool extends Adult Core's existing installation gate to return HTTP **503** for dynamic application, member and administrator routes while a protected source-update marker is present. It requires the current operator-only `SourceOperationLease`. It **does not** stop static assets served directly by Apache/nginx, external scripts, database writers, background Pro workers or concurrent remote server processes; it does not constitute safe production source activation.

## 1. Prepare protected operator coordination

Do this in a restricted SSH/hosting terminal as the installed site's filesystem owner. The site must be fully installed with `install/` removed, its signed installed marker valid, and `storage/` restricted to owner access (0700). Choose a 0700 coordination directory **outside and not an ancestor of** the source tree and every web document root.

```sh
mkdir -m 700 /home/account/private-source-coordination
umask 077
php scripts/source-operation-lease.php acquire \
  /home/account/Adult_Core \
  /home/account/private-source-coordination
```

Save the printed `release_token` once to an operator-only secrets vault or a 0600 file outside all web roots. The token is never shown by `status`. Avoid logging, emailing or putting it in command-line arguments. **Do not lose the token**: interrupted and unowned markers deliberately do not self-expire.

## 2. Block dynamic HTTP access with the lease token

```sh
php scripts/source-http-maintenance.php begin \
  /home/account/Adult_Core /home/account/private-source-coordination \
  < /private/operator-source-token
php scripts/source-http-maintenance.php status \
  /home/account/Adult_Core /home/account/private-source-coordination
```

An owner-only `storage/.source-update-maintenance` is created exclusively. The front controller's `InstallationGate` handles this marker before site/admin routing, displays a generic maintenance screen, sends HTTP 503 and `Retry-After: 300`. An interrupted or symbolic-link marker still blocks application requests. The command must never be used to bypass a backup/edition/license pending-recovery state.

The coordination lease cannot be released while the marker exists, even with the right token. This prevents an operator from accidentally discarding the control token before dynamic HTTP is restored.

## 3. End HTTP maintenance, then release operator coordination

Only after confirming independent recovery status and deciding it is safe to reopen the site:

```sh
php scripts/source-http-maintenance.php end \
  /home/account/Adult_Core /home/account/private-source-coordination \
  < /private/operator-source-token
php scripts/source-operation-lease.php release \
  /home/account/Adult_Core /home/account/private-source-coordination \
  < /private/operator-source-token
```

End verifies the active lease, marker version, root identity, token hash and absence of higher-priority restore/upgrade/license blockers. A corrupt/incomplete marker or missing coordination lease remains blocking and requires a separate inspected recovery process. No automatic expiry or lock deletion is provided.

## Security and release limits

**Do not deploy code solely because HTTP maintenance is active.** This is an application front-controller gate, not an operating-system/filesystem lock or service-wide maintenance coordinator. It does not drain in-flight PHP requests, block directly served static files, pause worker queues, prevent database writes by separate commands, or guarantee atomic source activation/database rollback. The production gate remains open until worker/DB quiescence, approved migration and backup planning, live host acceptance and failure drills are complete.

Run `php tests/source_http_maintenance_tests.php` in a disposable environment to exercise private marker authenticity, 503 routing, CLI begin/end via stdin, lock precedence, symlink/corruption denial and preservation of the installed customer license. The unified Lite/Pro product continues to be licensed per independent self-hosted installation.

## Pro job receipt admission during maintenance

`ProJobAdmission` is now checked by `JobInbox::accept()` so new signed Pro receipts are refused while source, backup, edition or license recovery markers exist. This does not pause workers already running. See [PRO_JOB_MAINTENANCE.md](PRO_JOB_MAINTENANCE.md). Real worker shutdown and database-write coordination are still mandatory before deployment.
