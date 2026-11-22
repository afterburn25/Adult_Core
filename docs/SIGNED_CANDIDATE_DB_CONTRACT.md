# Publisher-signed candidate database contract review

Adult Core now includes `src/Database/release-schema-contract.json` inside **one** Lite/Pro source ZIP. The detached publisher signature authenticates this declaration just like the rest of the source archive. It is **not an executable migration** and does not grant production update permission.

## Offline read-only comparison

```sh
php scripts/review-candidate-schema.php \
  /private/previous.zip /private/previous.manifest.json /private/previous.sig \
  /private/candidate.zip /private/candidate.manifest.json /private/candidate.sig \
  /private/pinned-publisher-public.pem
```

Supply **two separately authenticated source archives** and a *previously pinned* publisher public key, never a key downloaded from the same untrusted release. The command checks both complete ZIP inventories with their independent signed manifests, requires a strictly newer candidate version, and verifies that both ZIPs contain a valid versioned `src/Database/release-schema-contract.json`. This file is runtime source, not a customer-controlled config, and must not be edited on installed sites.

The initial publisher declaration is:

```json
{
  "format": "adult-core-db-contract-v1",
  "family": "adult-core-mysql",
  "revision": 1,
  "declared_migrations": []
}
```

For a future version that **introduces schema migrations**, the maintainer must increase `revision` and append unique migration IDs in `declared_migrations` without deleting or rewriting earlier entries. The read-only reviewer rejects rollback of the declared revision, reordering/removing previous migration IDs, malformed IDs, unknown fields, forged signatures, tampered ZIP contents, and missing declarations. It returns only metadata, changed core PHP paths and safe migration IDs—never customer data.

Any declaration change, changed schema-related PHP implementation, or changed application PHP code forces `manual_database_compatibility_review_required: true`. Even CSS-only candidates retain `candidate_schema_compatibility_verified: false`, `migrations_executed: false`, `source_activation_authorized: false`, and `production_ready: false`, because publisher declarations cannot prove there is no dynamic SQL or external worker drift.

## Compare against the installed database

`SignedCandidateDbContract::inspectInstalled($root, $pdo, ...)` also invokes the existing signed installed-edition and MySQL/MariaDB migration-ledger auditor, checks that local `VERSION` agrees with the signed previous release, and refuses when any baseline-declared migration ID is absent from the actual database. This **does not** prove that every live source byte matches the prior signed ZIP: run the separate `SourceUpdatePreflight` and private signed stage verifier for that evidence.

The installed audit now re-reads the complete version/**applied_at** ledger and compares a deterministic SHA-256 against its first validated snapshot, refusing changed intermediate records even when the total record count is unchanged. It also **refuses a candidate-only migration ID that is already present in the active database**, because this can indicate a partially applied upgrade or mismatched source. The operator must investigate and use an independently reviewed recovery path; the checker never auto-deletes migration records. These two SELECT-only reads are **not** a persistent transaction, global writer lock, database migration compatibility proof, or authorization to activate source files.

Tests: `php tests/signed_candidate_db_contract_tests.php` uses disposable signed ZIPs and negative forgery cases; `tests/mysql_install.php` checks actual disposable MySQL 8 and MariaDB 10.11 migrations, missing baseline IDs, candidate additions and proper database fixture cleanup.

**Not yet implemented:** publisher-reviewed migration execution/rollback, transactional database writer drain, Pro worker quiescence, source activation, independent recovery drills, and cPanel/VPS acceptance. SQL DDL usually auto-commits; **do not cut over a production database from this report alone**. All ten release gates remain open.
