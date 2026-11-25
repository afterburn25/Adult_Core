<?php

declare(strict_types=1);

namespace AdultCore\Backup;

use RuntimeException;
use Throwable;

/**
 * Copy an authenticated backup into an operator-provided PRIVATE vault.
 * A directory path does not prove offsite storage: the operator must mount/sync it.
 */
final class BackupVault
{
    private const PREFIX = 'adult-core-';
    private const MATCH = '/^adult-core-\d{8}T\d{6}Z(?:\.\d{6})?-[a-f0-9]{12}\.acfull$/D';

    public function __construct(private readonly string $siteRoot) {}

    /** @return array{archive:string,sha256:string,retained:int,pruned:int,skipped_invalid:int} */
    public function ship(
        string $source, string $vaultDirectory, string $key, int $retain = 7,
        ?string $expectedInstalledAppKey = null, ?string $expectedArchiveSha256 = null
    ): array {
        if ($retain < 1 || $retain > 365) throw new RuntimeException('Vault retention must be between 1 and 365 verified archives.');
        $root = realpath($this->siteRoot);
        $vault = realpath($vaultDirectory);
        $sourceFile = realpath($source);
        if ($root === false || $vault === false || $sourceFile === false || !is_dir($vault)
            || is_link($vaultDirectory) || $vault === $root || str_starts_with($vault, $root.'/')
            || $sourceFile === $vault || str_starts_with($sourceFile, $vault.'/')
            || is_link($source) || !is_file($source)) {
            throw new RuntimeException('Use a regular source and a separate private vault directory outside Adult_Core.');
        }
        $mode = fileperms($vault);
        if (!is_int($mode) || ($mode & 0077) !== 0) {
            throw new RuntimeException('Vault must not be accessible to other users (chmod 0700).');
        }
        $verifier = new BackupVerifier();
        $original = $verifier->verifyForVault($source, $key, $expectedInstalledAppKey);
        if ($expectedArchiveSha256 !== null && !hash_equals($expectedArchiveSha256, $original['sha256'])) {
            throw new RuntimeException('Staging archive differs from the previously verified backup.');
        }
        $lockPath = $vault.'/.adult-core-vault.lock';
        $lock = @fopen($lockPath, 'x+b');
        if (!is_resource($lock)) throw new RuntimeException('Vault is busy or has a stale lock; inspect before retrying.');
        @chmod($lockPath, 0600);
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Vault is already locked.');
            $name = self::PREFIX.(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z.u').'-'.bin2hex(random_bytes(6)).'.acfull';
            $temporary = $vault.'/.adult-core-upload-'.bin2hex(random_bytes(8)).'.partial';
            $src = @fopen($source, 'rb');
            if (!is_resource($src)) throw new RuntimeException('Cannot read verified backup.');
            try {
                $target = @fopen($temporary, 'x+b');
                if (!is_resource($target)) throw new RuntimeException('Cannot create private vault staging file.');
                @chmod($temporary, 0600);
                try {
                    while (!feof($src)) {
                        $block = fread($src, 65536);
                        if (!is_string($block) || ($block === '' && !feof($src))) throw new RuntimeException('Backup transfer read failed.');
                        if ($block === '') break;
                        $length = strlen($block);
                        $offset = 0;
                        while ($offset < $length) {
                            $wrote = fwrite($target, substr($block, $offset));
                            if (!is_int($wrote) || $wrote < 1) throw new RuntimeException('Backup transfer write failed.');
                            $offset += $wrote;
                        }
                    }
                    if (!fflush($target)) throw new RuntimeException('Backup vault flush failed.');
                    if (function_exists('fsync') && !fsync($target)) throw new RuntimeException('Backup vault sync failed.');
                } finally { fclose($target); }
            } finally { fclose($src); }

            $verified = $verifier->verifyForVault($temporary, $key, $expectedInstalledAppKey);
            if (!hash_equals($original['sha256'], $verified['sha256'])
                || !hash_equals($original['vault_site_binding'], $verified['vault_site_binding'])) {
                throw new RuntimeException('The vault copy differs from the verified source.');
            }
            $final = $vault.'/'.$name;
            if (file_exists($final) || is_link($final) || !rename($temporary, $final)) {
                throw new RuntimeException('Unable to publish the private vault copy.');
            }
            $temporary = null;
            @chmod($final, 0600);
            // The new verified archive is durable before any older archive is considered for pruning.
            $entries = scandir($vault);
            if (!is_array($entries)) throw new RuntimeException('Cannot inspect vault inventory.');
            $candidates = [];
            foreach ($entries as $entry) {
                if (!preg_match(self::MATCH, $entry)) continue;
                $path = $vault.'/'.$entry;
                if (is_link($path) || !is_file($path)) continue;
                $candidates[] = $entry;
            }
            rsort($candidates, SORT_STRING);
            // A same-second random suffix must never cause the newly verified copy to be pruned.
            $candidates = array_values(array_diff($candidates, [$name]));
            array_unshift($candidates, $name);
            $pruned = 0;
            $skipped = 0;
            // Only authenticated archives of this exact installation count
            // towards its retention budget. Foreign sites and corrupt records
            // are preserved even if they use the same offline encryption key.
            $sameSiteRetained = 1;
            foreach (array_slice($candidates, 1) as $entry) {
                $path = $vault.'/'.$entry;
                try {
                    $historic = $verifier->verifyForVault($path, $key);
                    if (!hash_equals($original['vault_site_binding'], $historic['vault_site_binding'])) {
                        $skipped++;
                        continue;
                    }
                } catch (Throwable) {
                    $skipped++;
                    continue;
                }
                if ($sameSiteRetained < $retain) {
                    $sameSiteRetained++;
                    continue;
                }
                // Refuse deletion if the name no longer identifies the same
                // bytes verified above. Vault owner-only permissions remain
                // a prerequisite; this is not a distributed storage lock.
                clearstatcache(true, $path);
                if (is_link($path) || !is_file($path)
                    || !hash_equals($historic['sha256'], (string)@hash_file('sha256', $path))
                    || !unlink($path)) {
                    $skipped++;
                } else {
                    $pruned++;
                }
            }
            return ['archive' => $final, 'sha256' => $verified['sha256'], 'retained' => count($candidates)-$pruned,
                'pruned' => $pruned, 'skipped_invalid' => $skipped];
        } finally {
            if ($temporary !== null && is_file($temporary)) @unlink($temporary);
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            @unlink($lockPath);
        }
    }
}
