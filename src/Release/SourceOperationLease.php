<?php

declare(strict_types=1);

namespace AdultCore\Release;

use RuntimeException;

/**
 * Offline operator coordination only. This is NOT website maintenance mode:
 * public requests, DB writers and Pro workers do not yet observe this lease.
 *
 * A private exclusive directory persists across processes and crashes.
 * Interrupted/unknown leases never expire or self-delete.
 */
final class SourceOperationLease
{
    private const DIRECTORY = '.adult-core-source-operation';
    private const RECORD = 'lease.json';
    private const LIMIT = 2048;

    /** @return array{status:string,created_utc:string,installed_root_sha256:string,release_token:string,site_maintenance_enforced:bool,ready_for_activation:bool} */
    public static function acquire(string $installedRoot, string $privateCoordinationRoot): array
    {
        $site = self::site($installedRoot);
        $parent = self::parent($privateCoordinationRoot, $site);
        $path = $parent . '/' . self::DIRECTORY;

        // mkdir is the authoritative atomic claim (not a read-then-write
        // lockfile). A crashed claim without a receipt remains blocking.
        if (!@mkdir($path, 0700)) {
            throw new RuntimeException('Source operation already held or requires manual recovery.');
        }
        if (!chmod($path, 0700)) {
            throw new RuntimeException('Could not secure source coordination lease directory.');
        }
        $token = bin2hex(random_bytes(32));
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'schema' => 'adult-core-source-operation-lease-v1',
            'status' => 'operator_coordination_only',
            'created_utc' => $created,
            'installed_root_sha256' => hash('sha256', $site),
            'token_sha256' => hash('sha256', $token),
            'site_maintenance_enforced' => false,
            'ready_for_activation' => false,
        ];
        $receipt = $path . '/' . self::RECORD;
        $handle = @fopen($receipt, 'x+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('Incomplete source lease requires manual recovery.');
        }
        try {
            $bytes = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (!chmod($receipt, 0600)
                || fwrite($handle, $bytes) !== strlen($bytes)
                || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Incomplete source lease requires manual recovery.');
            }
        } finally {
            fclose($handle);
        }
        // Never persist the raw release token in the lease directory.
        return [
            'status' => 'operator_coordination_only',
            'created_utc' => $created,
            'installed_root_sha256' => $record['installed_root_sha256'],
            'release_token' => $token,
            'site_maintenance_enforced' => false,
            'ready_for_activation' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function status(string $installedRoot, string $privateCoordinationRoot): array
    {
        $site = self::site($installedRoot);
        $parent = self::parent($privateCoordinationRoot, $site);
        $path = $parent . '/' . self::DIRECTORY;
        if (is_link($path)) {
            throw new RuntimeException('Source coordination lease path is a symbolic link.');
        }
        if (!file_exists($path)) {
            return [
                'schema' => 'adult-core-source-operation-lease-v1',
                'status' => 'not_acquired',
                'site_maintenance_enforced' => false,
                'ready_for_activation' => false,
            ];
        }
        if (!is_dir($path)) {
            throw new RuntimeException('Source coordination lease path is not a directory.');
        }
        $record = self::record($path, $site);
        if ($record === null) {
            return [
                'schema' => 'adult-core-source-operation-lease-v1',
                'status' => 'incomplete_manual_recovery_required',
                'site_maintenance_enforced' => false,
                'ready_for_activation' => false,
            ];
        }
        // Deliberately NEVER reveal token or its hash in status.
        return [
            'schema' => 'adult-core-source-operation-lease-v1',
            'status' => 'operator_coordination_only',
            'created_utc' => $record['created_utc'],
            'installed_root_sha256' => $record['installed_root_sha256'],
            'site_maintenance_enforced' => false,
            'ready_for_activation' => false,
        ];
    }

    /** Check ownership of an existing private lease without exposing its token. */
    public static function matches(
        string $installedRoot, string $privateCoordinationRoot, string $releaseToken
    ): bool {
        if (preg_match('/^[a-f0-9]{64}$/D', $releaseToken) !== 1) return false;
        return self::matchesTokenDigest(
            $installedRoot, $privateCoordinationRoot, hash('sha256', $releaseToken)
        );
    }

    /**
     * Compare a marker's already-hashed token with the intact private lease.
     * This reveals only a boolean and never prints or returns either hash.
     * It does NOT authorize a new operation, source update or cleanup.
     */
    public static function matchesTokenDigest(
        string $installedRoot, string $privateCoordinationRoot, string $tokenDigest
    ): bool {
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenDigest) !== 1) return false;
        $site = self::site($installedRoot);
        $parent = self::parent($privateCoordinationRoot, $site);
        $path = $parent . '/' . self::DIRECTORY;
        if (is_link($path) || !is_dir($path)) return false;
        $record = self::record($path, $site);
        return $record !== null && hash_equals($record['token_sha256'], $tokenDigest);
    }

    public static function release(
        string $installedRoot,
        string $privateCoordinationRoot,
        string $releaseToken
    ): void {
        if (!preg_match('/^[a-f0-9]{64}$/D', $releaseToken)) {
            throw new RuntimeException('Source coordination release token is invalid.');
        }
        $site = self::site($installedRoot);
        $parent = self::parent($privateCoordinationRoot, $site);
        $path = $parent . '/' . self::DIRECTORY;
        if (is_link($path) || !is_dir($path)) {
            throw new RuntimeException('Source coordination lease is missing or unsafe.');
        }
        $record = self::record($path, $site);
        if ($record === null || !hash_equals($record['token_sha256'], hash('sha256', $releaseToken))) {
            throw new RuntimeException('Refusing source lease release without its correct token and intact receipt.');
        }
        // Never discard the coordination lease while a site HTTP
        // source-maintenance marker is still blocking application routes.
        if (file_exists($site . '/storage/.source-update-maintenance')
            || is_link($site . '/storage/.source-update-maintenance')) {
            throw new RuntimeException('End source HTTP maintenance before releasing its lease.');
        }
        $entries = scandir($path);
        if ($entries === false || array_values(array_diff($entries, ['.', '..'])) !== [self::RECORD]) {
            throw new RuntimeException('Source coordination lease contains unexpected files.');
        }
        $receipt = $path . '/' . self::RECORD;
        // Acquire an advisory file lock to serialize cooperating release
        // processes. The exclusive directory is the authoritative claim.
        $handle = @fopen($receipt, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Source coordination lease receipt cannot be reopened.');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Source coordination lease is being inspected by another process.');
            }
            $verified = self::record($path, $site);
            if ($verified === null || !hash_equals($record['token_sha256'], $verified['token_sha256'])
                || !hash_equals($verified['token_sha256'], hash('sha256', $releaseToken))) {
                throw new RuntimeException('Source coordination lease changed before release.');
            }
            if (!unlink($receipt) || !rmdir($path)) {
                throw new RuntimeException('Could not safely release source coordination lease.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function site(string $installedRoot): string
    {
        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($installedRoot)) {
            throw new RuntimeException('Installed source root must be an existing real directory.');
        }
        foreach (['VERSION', 'public/index.php', 'src/Support/Autoload.php'] as $name) {
            $cursor = $root;
            $parts = explode('/', $name);
            foreach (array_slice($parts, 0, -1) as $part) {
                $cursor .= '/' . $part;
                if (is_link($cursor) || !is_dir($cursor)) {
                    throw new RuntimeException('Installed source contains an unsafe directory boundary.');
                }
            }
            $path = $cursor . '/' . end($parts);
            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException('Installed source is missing a required regular file.');
            }
        }
        return $root;
    }

    private static function parent(string $privateCoordinationRoot, string $site): string
    {
        $parent = realpath($privateCoordinationRoot);
        if ($parent === false || $parent === '/' || !is_dir($parent)
            || is_link($privateCoordinationRoot) || !is_writable($parent)
            || $parent === $site
            || str_starts_with($parent, $site . '/')
            || str_starts_with($site, $parent . '/')) {
            throw new RuntimeException('Coordination directory must be private and separate from installed source.');
        }
        $permissions = fileperms($parent);
        if (!is_int($permissions) || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Coordination parent must have 0700-or-stricter permissions.');
        }
        return $parent;
    }

    /** @return array<string,mixed>|null */
    private static function record(string $leaseDirectory, string $site): ?array
    {
        $dirMode = fileperms($leaseDirectory);
        if (!is_int($dirMode) || ($dirMode & 0077) !== 0) {
            return null;
        }
        $receipt = $leaseDirectory . '/' . self::RECORD;
        if (is_link($receipt) || !is_file($receipt)) {
            return null;
        }
        $permissions = fileperms($receipt);
        $bytes = filesize($receipt);
        if (!is_int($permissions) || ($permissions & 0077) !== 0
            || !is_int($bytes) || $bytes < 1 || $bytes > self::LIMIT) {
            return null;
        }
        $json = file_get_contents($receipt);
        if (!is_string($json)) return null;
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || count($data) !== 7
            || ($data['schema'] ?? null) !== 'adult-core-source-operation-lease-v1'
            || ($data['status'] ?? null) !== 'operator_coordination_only'
            || ($data['site_maintenance_enforced'] ?? null) !== false
            || ($data['ready_for_activation'] ?? null) !== false
            || ($data['installed_root_sha256'] ?? null) !== hash('sha256', $site)
            || !is_string($data['created_utc'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $data['created_utc'])
            || !is_string($data['token_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $data['token_sha256'])) {
            return null;
        }
        return $data;
    }
}
