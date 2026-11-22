<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use AdultCore\Installer\InstallationState;
use AdultCore\Release\SourceHttpRequestLock;
use AdultCore\Release\SourceUpdateMaintenance;
use RuntimeException;

/**
 * A read-only LOCAL snapshot of signed Pro receipts under an authenticated
 * source maintenance marker and exclusive cooperating request/worker lock.
 *
 * No Pro daemon runs here. External workers and SQL writes remain unverified.
 */
final class ProSourceMaintenanceReceiptSnapshot
{
    /** @return array<string,mixed> */
    public static function inspect(
        string $installedRoot, string $privateCoordinationRoot,
        string $releaseToken, string $receiptHmacSecret
    ): array {
        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($installedRoot)) {
            throw new RuntimeException('Signed Pro maintenance snapshot root is unsafe.');
        }

        // Nonblocking exclusive local flock means no *cooperating* PHP
        // request, receipt writer or local execution guard is active at scan.
        $lock = SourceHttpRequestLock::exclusive($root);
        try {
            SourceUpdateMaintenance::assertOwnedMarker(
                $root, $privateCoordinationRoot, $releaseToken
            );
            $appKeyFile = $root . '/config/app-key.php';
            $stateFile = $root . '/storage/install-state.json';
            if (is_link($appKeyFile) || is_link($stateFile)
                || !is_file($appKeyFile) || !is_readable($appKeyFile)
                || !is_file($stateFile) || !is_readable($stateFile)
                || (filesize($appKeyFile) ?: 0) > 4096
                || (filesize($stateFile) ?: 0) > 4096) {
                throw new RuntimeException('Signed Pro site identity is unavailable or unsafe.');
            }
            $key = require $appKeyFile;
            $bytes = file_get_contents($stateFile);
            if (!is_string($key) || !is_string($bytes)
                || !InstallationState::valid($bytes, $key)) {
                throw new RuntimeException('Signed Pro site identity is invalid.');
            }
            $state = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
            if ($state['edition'] !== 'pro') {
                throw new RuntimeException('Pro maintenance receipt snapshot requires the Pro edition.');
            }

            $audit = ProJobReceiptAudit::inspect($root, $receiptHmacSecret);
            if ($audit['no_locally_open_receipts'] !== true) {
                throw new RuntimeException(
                    'Unfinished signed local Pro receipts block this maintenance snapshot.'
                );
            }

            return [
                'schema' => 'adult-core-pro-source-maintenance-receipt-snapshot-v1',
                'read_only' => true,
                'signed_pro_edition_verified' => true,
                'owned_source_maintenance_marker_verified' => true,
                'cooperating_local_guard_exclusive_at_scan' => true,
                'signed_local_receipts_verified' => $audit['signed_receipts_verified'],
                'pending_receipts' => $audit['pending_receipts'],
                'processing_receipts' => $audit['processing_receipts'],
                'no_unfinished_signed_local_receipts_at_scan' => true,
                'external_pro_workers_quiesced' => false,
                'database_writers_quiesced' => false,
                'ready_for_activation' => false,
                'production_ready' => false,
            ];
        } finally {
            fclose($lock);
        }
    }
}
