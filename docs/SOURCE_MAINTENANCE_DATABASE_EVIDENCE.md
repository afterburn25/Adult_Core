# Token-owned source-maintenance database evidence (read-only)

**Development-only local maintenance diagnostic. NOT an update or a database write drain.**

`SourceMaintenanceDatabaseEvidence::inspect()` combines three existing, independently
verified controls **in one read-only inspection**: an operator's private source lease
and intact source HTTP maintenance marker, a nonblocking exclusive local
`SourceHttpRequestLock`, and a signed installed Lite/Pro MySQL/MariaDB edition
and schema-migration ledger. It reads the actual migration ledger twice and
rejects changed edition, record count, SHA-256 ledger fingerprint, or number
of private `ac_` InnoDB tables. No SQL records, user data or credentials are
included in its output.

## Run only during an existing operator-owned source-maintenance session

First acquire an owner-only source-operation lease and enter source HTTP maintenance
as described in [SOURCE_HTTP_MAINTENANCE.md](SOURCE_HTTP_MAINTENANCE.md).
On the installed host, use the **same** current lease token over STDIN:

```sh
php scripts/check-source-maintenance-db.php \
  /home/account/Adult_Core \
  /home/account/private-source-coordination \
  < /private/keys/source-lease-token
```

The CLI takes database credentials only from the installed site's private
`config/database.php` via `Connection::open()`. It does not accept DSNs,
passwords or an operator token on the command line. The command rejects
absent, wrong or malformed tokens, a missing/damaged/foreign-owned source
maintenance marker, unfinished installation, incorrect signed Lite/Pro database
edition, malformed migration rows, an existing PDO transaction, unsafe schema
or a currently held *cooperating local* HTTP/Pro/worker shared lock.

On a valid result, the output includes the installed edition, local `ac_` table
count, ledger size, and redacted deterministic ledger hash. The report's
`two_ledger_reads_identical_at_scan` is an observation only, and
`cooperating_local_guard_exclusive_at_scan` guarantees only **one host's
cooperating file lock**, not persistent exclusion once the command returns.

## What the evidence does NOT prove

- It does **not** block independent MySQL clients, cron jobs, database
  transactions, replicated nodes or any writer that bypasses the local lock
- It does **not** run inside a persistent database read transaction or hold an
  SQL lock; the database can change between and immediately after its SELECTs
- It does **not** prove candidate migration safety, external Pro worker drain,
  FFmpeg shutdown, protected media readiness, backup consistency, atomic
  cutover, rollback or production hosting acceptance
- The report always sets `independent_database_writers_stopped`,
  `database_writers_quiesced`, `external_pro_workers_quiesced`,
  `candidate_schema_compatibility_verified`, `ready_for_activation` and
  `production_ready` to `false`

The existing source lease and maintenance marker must remain under the operator's
separate control. This diagnostic must never automatically clear them.

**Regression:** the disposable `tests/mysql_install.php` checks actual
MySQL 8 / MariaDB 10.11 installed Lite DBs, signed marker ownership, local
shared-lock denial, mismatched database edition, CLI token handling, stable
ledger fingerprint, and recovery to the original installed fixture. Never
run that test against a real customer database.

All ten production release workstreams remain OPEN.
