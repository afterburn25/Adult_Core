<?php

declare(strict_types=1);

namespace AdultCore\Release;

use AdultCore\Installer\InstallationGate;
use RuntimeException;

/**
 * CLI-only operator-controlled front-controller maintenance marker.
 *
 * This blocks Adult Core's dynamic HTTP entry point through InstallationGate.
 * It DOES NOT stop static assets, external writers, DB jobs or Pro workers,
 * and never authorizes source activation/migration.
 */
final class SourceUpdateMaintenance
{
    private const MARKER = '.source-update-maintenance';
    private const LIMIT = 2048;

    /** @return array<string,mixed> */
    public static function begin(
        string $installedRoot, string $privateCoordinationRoot, string $releaseToken
    ): array {
        $root = self::site($installedRoot);
        if (!SourceOperationLease::matches($root, $privateCoordinationRoot, $releaseToken)) {
            throw new RuntimeException('A matching private source-operation lease is required.');
        }
        // Wait-free exclusive entry refuses to race an existing dynamic request.
        $exclusiveRequests = SourceHttpRequestLock::exclusive($root);
        try {
        if ((new InstallationGate($root))->status() !== InstallationGate::READY) {
            throw new RuntimeException('Installed application is not ready for source maintenance.');
        }
        $storage = self::storage($root);
        $marker = $storage . '/' . self::MARKER;
        if (file_exists($marker) || is_link($marker)) {
            throw new RuntimeException('Source maintenance already active or requires manual recovery.');
        }
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'schema' => 'adult-core-source-http-maintenance-v1',
            'status' => 'front_controller_maintenance',
            'created_utc' => $created,
            'installed_root_sha256' => hash('sha256', $root),
            'lease_token_sha256' => hash('sha256', $releaseToken),
            'site_http_front_controller_blocked' => true,
            'static_assets_blocked' => false,
            'pro_workers_blocked' => false,
            'ready_for_activation' => false,
        ];
        // Exclusive create is required; an interrupted marker is never
        // overwritten or auto-cleared, so dynamic HTTP stays fail-closed.
        $handle = @fopen($marker, 'x+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not exclusively create protected HTTP maintenance marker.');
        }
        try {
            $json = json_encode(
                $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
            if (!chmod($marker, 0600)
                || fwrite($handle, $json) !== strlen($json)
                || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('HTTP maintenance marker is incomplete and requires manual recovery.');
            }
        } finally {
            fclose($handle);
        }
        if ((new InstallationGate($root))->status() !== InstallationGate::SOURCE_UPDATE_MAINTENANCE) {
            throw new RuntimeException('Another site gate is active; maintenance marker retained for review.');
        }
        return [
            'schema' => 'adult-core-source-http-maintenance-v1',
            'status' => 'front_controller_maintenance',
            'created_utc' => $created,
            'site_http_front_controller_blocked' => true,
            'static_assets_blocked' => false,
            'pro_workers_blocked' => false,
            'ready_for_activation' => false,
            'dynamic_requests_drained_at_entry' => true,
        ];
        } finally {
            fclose($exclusiveRequests);
        }
    }

    /** @return array<string,mixed> */
    public static function status(string $installedRoot, string $privateCoordinationRoot): array
    {
        $root = self::site($installedRoot);
        $storage = self::storage($root);
        $path = $storage . '/' . self::MARKER;
        if (!file_exists($path) && !is_link($path)) {
            return [
                'schema' => 'adult-core-source-http-maintenance-v1',
                'status' => 'inactive',
                'site_http_front_controller_blocked' => false,
                'ready_for_activation' => false,
            ];
        }
        $record = self::record($path, $root);
        $lease = SourceOperationLease::status($root, $privateCoordinationRoot);
        if ($record === null || ($lease['status'] ?? null) !== 'operator_coordination_only') {
            return [
                'schema' => 'adult-core-source-http-maintenance-v1',
                'status' => 'incomplete_manual_recovery_required',
                'site_http_front_controller_blocked' => true,
                'ready_for_activation' => false,
            ];
        }
        return [
            'schema' => 'adult-core-source-http-maintenance-v1',
            'status' => 'front_controller_maintenance',
            'created_utc' => $record['created_utc'],
            'site_http_front_controller_blocked' => true,
            'static_assets_blocked' => false,
            'pro_workers_blocked' => false,
            'ready_for_activation' => false,
        ];
    }

    /**
     * Read-only proof that this exact private lease token owns the intact
     * source maintenance marker. Call under the shared operator's exclusive
     * SourceHttpRequestLock when composing a multi-part maintenance snapshot.
     * This neither ends maintenance nor authorizes source activation.
     */
    public static function assertOwnedMarker(
        string $installedRoot, string $privateCoordinationRoot, string $releaseToken
    ): void {
        $root = self::site($installedRoot);
        if (!SourceOperationLease::matches($root, $privateCoordinationRoot, $releaseToken)) {
            throw new RuntimeException('A matching private source-operation lease is required.');
        }
        $path = self::storage($root) . '/' . self::MARKER;
        $record = self::record($path, $root);
        if ($record === null || !hash_equals(
            $record['lease_token_sha256'], hash('sha256', $releaseToken)
        )) {
            throw new RuntimeException('Source maintenance marker is not owned by this lease.');
        }
        if ((new InstallationGate($root))->status() !== InstallationGate::SOURCE_UPDATE_MAINTENANCE) {
            throw new RuntimeException('Another recovery state prevents source-maintenance inspection.');
        }
    }

    public static function end(
        string $installedRoot, string $privateCoordinationRoot, string $releaseToken
    ): void {
        $root = self::site($installedRoot);
        if (!SourceOperationLease::matches($root, $privateCoordinationRoot, $releaseToken)) {
            throw new RuntimeException('A matching private source-operation lease is required.');
        }
        // Prevent new dynamic requests until the marker has been removed.
        $exclusiveRequests = SourceHttpRequestLock::exclusive($root);
        try {
        $path = self::storage($root) . '/' . self::MARKER;
        $record = self::record($path, $root);
        if ($record === null || !hash_equals(
            $record['lease_token_sha256'], hash('sha256', $releaseToken)
        )) {
            throw new RuntimeException('Source maintenance marker is absent, damaged or belongs to another lease.');
        }
        // Do not expose the site if a higher-priority restore/upgrade/license
        // marker is still active. Finish its own recovery first.
        if ((new InstallationGate($root))->status() !== InstallationGate::SOURCE_UPDATE_MAINTENANCE) {
            throw new RuntimeException('Another blocking site state requires recovery first.');
        }
        if (!unlink($path)) {
            throw new RuntimeException('Could not safely remove private source maintenance marker.');
        }
        if ((new InstallationGate($root))->status() !== InstallationGate::READY) {
            throw new RuntimeException('Source maintenance ended but installation is still blocked.');
        }
        } finally {
            fclose($exclusiveRequests);
        }
    }

    private static function site(string $installedRoot): string
    {
        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($installedRoot)) {
            throw new RuntimeException('Invalid installed source root for maintenance.');
        }
        return $root;
    }

    private static function storage(string $root): string
    {
        $path = $root . '/storage';
        if (is_link($path) || !is_dir($path) || !is_writable($path)) {
            throw new RuntimeException('Private site storage is missing or unsafe.');
        }
        $permissions = fileperms($path);
        if (!is_int($permissions) || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Source maintenance requires private 0700 site storage.');
        }
        return $path;
    }

    /** @return array<string,mixed>|null */
    private static function record(string $path, string $root): ?array
    {
        if (is_link($path) || !is_file($path)) return null;
        $permissions = fileperms($path);
        $size = filesize($path);
        if (!is_int($permissions) || ($permissions & 0077) !== 0
            || !is_int($size) || $size < 1 || $size > self::LIMIT) return null;
        $json = file_get_contents($path);
        if (!is_string($json)) return null;
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || count($data) !== 9
            || ($data['schema'] ?? null) !== 'adult-core-source-http-maintenance-v1'
            || ($data['status'] ?? null) !== 'front_controller_maintenance'
            || ($data['installed_root_sha256'] ?? null) !== hash('sha256', $root)
            || ($data['site_http_front_controller_blocked'] ?? null) !== true
            || ($data['static_assets_blocked'] ?? null) !== false
            || ($data['pro_workers_blocked'] ?? null) !== false
            || ($data['ready_for_activation'] ?? null) !== false
            || !is_string($data['lease_token_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $data['lease_token_sha256'])
            || !is_string($data['created_utc'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $data['created_utc'])) {
            return null;
        }
        return $data;
    }
}
