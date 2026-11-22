<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use RuntimeException;

/**
 * Fail-closed admission gate for signed Pro job RECEIPTS.
 *
 * Does not start a worker, drain existing work, stop FFmpeg or certify VPS
 * maintenance. Future production workers must independently recheck state.
 */
final class ProJobAdmission
{
    private const BLOCKING_MARKERS = [
        '.source-update-maintenance',
        '.backup-maintenance',
        '.backup-restore-pending',
        '.edition-upgrade-pending',
        '.license-update-pending',
    ];

    public static function assertMayAccept(string $siteRoot): void
    {
        $storage = $siteRoot . '/storage';
        if (is_link($storage) || !is_dir($storage) || !is_readable($storage)) {
            throw new RuntimeException('Pro job admission requires safe private site storage.');
        }
        foreach (self::BLOCKING_MARKERS as $name) {
            $path = $storage . '/' . $name;
            // A directory, incomplete marker or symlink is still blocking.
            // Never read or trust the marker contents in an admission check.
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('Pro job intake is blocked during maintenance or recovery.');
            }
        }
    }

    /**
     * A local, redacted view. A permitted receipt does not mean an actual
     * executable worker may safely run or resume.
     *
     * @return array{schema:string,job_receipt_admission_open:bool,existing_jobs_drained:bool,pro_worker_quiesced:bool,production_ready:bool}
     */
    public static function status(string $siteRoot): array
    {
        $open = true;
        try {
            self::assertMayAccept($siteRoot);
        } catch (RuntimeException) {
            $open = false;
        }
        return [
            'schema' => 'adult-core-pro-job-admission-v1',
            'job_receipt_admission_open' => $open,
            'existing_jobs_drained' => false,
            'pro_worker_quiesced' => false,
            'production_ready' => false,
        ];
    }
}
