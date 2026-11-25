<?php

declare(strict_types=1);

namespace AdultCore\Backup;

use AdultCore\Installer\InstallationState;
use RuntimeException;

/**
 * Read-only semantic verification of an encrypted portable backup.
 * No secrets, recovered files, or database rows leave this process.
 */
final class BackupVerifier
{
    private const MAX_FILES = 50000;
    private const MAX_BYTES = 20 * 1024 * 1024 * 1024;
    private const MAX_ROWS = 3000000;
    private const MAX_CHUNK = 65536;

    /** @return array{source_version:string,edition:string,tables:int,rows:int,files:int,file_bytes:int,sha256:string} */
    public function verify(string $archive, string $key, ?string $expectedInstalledAppKey = null): array
    {
        return $this->inspect($archive, $key, $expectedInstalledAppKey, false);
    }

    /**
     * Internal vault comparison: an opaque, backup-key-scoped site binding.
     * Never print this value or expose it in the normal verification report.
     * The raw application key never leaves the verifier.
     *
     * @return array<string,mixed>
     */
    public function verifyForVault(string $archive, string $key, ?string $expectedInstalledAppKey = null): array
    {
        return $this->inspect($archive, $key, $expectedInstalledAppKey, true);
    }

    private function inspect(
        string $archive, string $key, ?string $expectedInstalledAppKey, bool $vaultBinding
    ): array {
        if ($expectedInstalledAppKey !== null
            && preg_match('/^[a-f0-9]{64}$/D', $expectedInstalledAppKey) !== 1) {
            throw new RuntimeException('Installed site identity input is invalid.');
        }
        if (is_link($archive) || !is_file($archive)) {
            throw new RuntimeException('Verification requires a regular private archive.');
        }
        $phase = 0;
        $meta = null;
        $tables = [];
        $paths = [];
        $stats = ['tables' => 0, 'rows' => 0, 'files' => 0, 'file_bytes' => 0];
        $current = null;
        $planned = 0;
        $count = 0;
        $hash = null;
        $sensitive = [];
        $finished = false;
        $capture = ['config/app-key.php' => 4096, 'storage/install-state.json' => 4096];

        StreamArchive::read($archive, $key, static function (array $r) use (
            &$phase, &$meta, &$tables, &$paths, &$stats, &$current, &$planned, &$count,
            &$hash, &$sensitive, &$finished, $capture
        ): void {
            $type = $r['type'];
            if ($finished) throw new RuntimeException('Unexpected data after backup completion.');
            if ($type === 'meta') {
                if ($phase !== 0 || !is_string($r['source_version'] ?? null)
                    || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,79}$/D', $r['source_version']) !== 1
                    || !in_array($r['edition'] ?? null, ['lite', 'pro'], true)
                    || !is_int($r['tables'] ?? null) || $r['tables'] < 5 || $r['tables'] > 250) {
                    throw new RuntimeException('Invalid backup metadata.');
                }
                $meta = $r;
                $phase = 1;
            } elseif ($type === 'table') {
                if ($phase !== 1 || !is_string($r['name'] ?? null) || !is_string($r['ddl'] ?? null)
                    || !self::tableName($r['name']) || isset($tables[$r['name']]) || count($tables) >= 250
                    || strlen($r['ddl']) > 150000
                    || preg_match('~^CREATE TABLE `'.preg_quote($r['name'], '~').'`\s*\(~D', $r['ddl']) !== 1
                    || !str_contains($r['ddl'], 'ENGINE=InnoDB') || str_contains($r['ddl'], ';')) {
                    throw new RuntimeException('Invalid backup schema.');
                }
                $tables[$r['name']] = true;
                $stats['tables']++;
            } elseif ($type === 'row') {
                if (!in_array($phase, [1, 2], true) || $stats['tables'] !== $meta['tables']
                    || !is_string($r['table'] ?? null) || !isset($tables[$r['table']])
                    || !is_array($r['fields'] ?? null) || array_is_list($r['fields'])
                    || count($r['fields']) < 1 || count($r['fields']) > 200) {
                    throw new RuntimeException('Invalid backup database row.');
                }
                foreach ($r['fields'] as $column => $field) {
                    if (!self::identifier($column) || ($field !== null && (!is_string($field) || base64_decode($field, true) === false))) {
                        throw new RuntimeException('Invalid backup database column.');
                    }
                }
                $phase = 2;
                if (++$stats['rows'] > self::MAX_ROWS) throw new RuntimeException('Backup row limit exceeded.');
            } elseif ($type === 'file_start') {
                if (!in_array($phase, [1, 2, 3], true) || $current !== null
                    || !is_string($r['path'] ?? null) || !SiteBackup::safePath($r['path'])
                    || isset($paths[$r['path']]) || count($paths) >= self::MAX_FILES
                    || !is_int($r['bytes'] ?? null) || $r['bytes'] < 0 || $r['bytes'] > self::MAX_BYTES) {
                    throw new RuntimeException('Invalid backup private-file entry.');
                }
                $phase = 3;
                $current = $r['path'];
                $paths[$current] = true;
                $count = 0;
                $planned = $r['bytes'];
                if (isset($capture[$current]) && $planned > $capture[$current]) {
                    throw new RuntimeException('Signed installation material too large.');
                }
                $hash = hash_init('sha256');
                if (isset($capture[$current])) $sensitive[$current] = '';
            } elseif ($type === 'file_chunk') {
                if ($phase !== 3 || $current === null || ($r['path'] ?? null) !== $current
                    || !is_string($r['data'] ?? null)) {
                    throw new RuntimeException('Unordered backup file content.');
                }
                $chunk = base64_decode($r['data'], true);
                if (!is_string($chunk) || strlen($chunk) < 1 || strlen($chunk) > self::MAX_CHUNK
                    || $count + strlen($chunk) > $planned) {
                    throw new RuntimeException('Invalid backup file data.');
                }
                $count += strlen($chunk);
                hash_update($hash, $chunk);
                if (isset($capture[$current])) $sensitive[$current] .= $chunk;
            } elseif ($type === 'file_end') {
                if ($phase !== 3 || $current === null || ($r['path'] ?? null) !== $current
                    || !is_string($r['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $r['sha256']) !== 1
                    || $count !== $planned || !hash_equals(hash_final($hash), $r['sha256'])) {
                    throw new RuntimeException('Backup file checksum mismatch.');
                }
                $stats['files']++;
                $stats['file_bytes'] += $count;
                if ($stats['file_bytes'] > self::MAX_BYTES) throw new RuntimeException('Backup media limit exceeded.');
                $current = null;
                $hash = null;
            } elseif ($type === 'end') {
                if ($current !== null || $meta === null || $stats['tables'] !== $meta['tables']) {
                    throw new RuntimeException('Incomplete backup.');
                }
                foreach ($stats as $name => $value) {
                    if (($r[$name] ?? null) !== $value) throw new RuntimeException('Backup record totals disagree.');
                }
                $finished = true;
            } else {
                throw new RuntimeException('Unsupported backup record type.');
            }
        });

        foreach (['config/app-key.php', 'config/license.php', 'storage/install-state.json', 'storage/license-cache.json'] as $essential) {
            if (!isset($paths[$essential])) throw new RuntimeException('Backup is missing critical installation files.');
        }
        if (!$finished || !isset($sensitive['config/app-key.php'], $sensitive['storage/install-state.json'])) {
            throw new RuntimeException('Backup did not complete.');
        }
        $source = $sensitive['config/app-key.php'];
        if (preg_match('/return\s+[\'\"]([a-f0-9]{64})[\'\"]\s*;/', $source, $match) !== 1
            || !InstallationState::valid($sensitive['storage/install-state.json'], $match[1])) {
            throw new RuntimeException('Backup contains invalid signed installation state.');
        }
        // An archive from a different installation can have the SAME version
        // and edition. Compare the signed installation's private random app key
        // without returning either key or its hash in the verification output.
        if ($expectedInstalledAppKey !== null
            && !hash_equals($expectedInstalledAppKey, $match[1])) {
            throw new RuntimeException('Recovery archive belongs to a different installed site.');
        }
        $installed = json_decode($sensitive['storage/install-state.json'], true, 8, JSON_THROW_ON_ERROR);
        if (($installed['edition'] ?? null) !== $meta['edition']) {
            throw new RuntimeException('Backup installation edition differs from metadata.');
        }
        $digest = hash_file('sha256', $archive);
        if (!is_string($digest)) throw new RuntimeException('Unable to hash verified backup.');
        $report = ['source_version' => $meta['source_version'], 'edition' => $meta['edition']]
            + $stats + ['sha256' => $digest];
        if ($vaultBinding) {
            // Scoped to this encryption key, so different installations
            // sharing a backup key still have different retention identities.
            $report['vault_site_binding'] = hash_hmac(
                'sha256', "adult-core-backup-vault-site-v1\n".$match[1], hex2bin($key)
            );
        }
        return $report;
    }

    private static function identifier(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name) === 1;
    }

    private static function tableName(string $name): bool
    {
        return preg_match('/^ac_[a-z0-9_]{1,60}$/D', $name) === 1;
    }
}
