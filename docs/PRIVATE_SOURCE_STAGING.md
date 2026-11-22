# Private authenticated release staging (development only)

Adult_Core remains one self-hosted Lite/Pro product, with the license controlling the edition. This operation stages signed source files into a private non-live directory without modifying the website, customer data or software license.

## Operator command

Create a new parent directory outside every HTTP document root and outside the installed application. Restrict it to the operating account (`chmod 700`). Obtain the release ZIP, detached manifest and signature, and independently trusted public signing key.

```sh
mkdir -m 700 /home/account/private-release-staging
php scripts/stage-source-release.php \
  /private/releases/adult-core.zip \
  /private/releases/adult-core.manifest.json \
  /private/releases/adult-core.manifest.sig \
  /trusted/keys/approved-source-public.pem \
  /home/account/Adult_Core \
  /home/account/private-release-staging/next-release
```

The final destination must not yet exist. The command verifies the signature and every archive hash before writing, and then rehashes copied files with private 0700 directory and 0600 file permissions. A complete stage has `.ac-stage-complete.json` and no `.ac-stage-pending`; interrupted or incomplete stages retain the pending marker for manual inspection. Never serve the staged PHP files via HTTP.

**This is not a production updater or atomic swap.** It never changes live files, runs migrations, changes editions, grants rights, reads private customer files, switches webroots or provides rollback. A successfully staged ZIP is not production approval. All ten release workstreams remain open.

Test with `php tests/private_source_stage_tests.php` using disposable fixtures, PHP 8.3+, OpenSSL and ext-zip.
