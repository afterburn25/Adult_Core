<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Release\SourceHttpRequestLock;
use RuntimeException;

/**
 * A cooperative LOCAL Pro job executor holds this guard for its entire job
 * execution lifetime. Source/backup maintenance cannot enter while it is held.
 *
 * Used by the local trusted handler lifecycle. It does not launch, interrupt,
 * drain or verify
 * FFmpeg workers, external agents, DB writers, static delivery, or remote nodes.
 */
final class ProWorkerExecutionGuard
{
    /**
     * Acquire before loading a job, retain the returned handle through all
     * database/file writes and cleanup, then fclose() it on process exit.
     *
     * @return resource
     */
    public static function enter(string $installedRoot)
    {
        // Same nonblocking flock shared by dynamic HTTP and signed receipts.
        // Acquiring it first closes the gap between checking maintenance
        // markers and beginning cooperating local worker execution.
        $guard = SourceHttpRequestLock::enter($installedRoot);
        if (!is_resource($guard)) {
            throw new RuntimeException('Pro worker requires private installed storage.');
        }
        try {
            if ((new InstallationGate($installedRoot))->status() !== InstallationGate::READY) {
                throw new RuntimeException('Pro worker cannot begin during installation or maintenance.');
            }
            self::assertSignedProEdition($installedRoot);
            // A concurrent source/backup maintenance marker cannot be
            // published while our shared lifetime guard is held.
            ProJobAdmission::assertMayAccept($installedRoot);
            return $guard;
        } catch (\Throwable $error) {
            fclose($guard);
            throw $error;
        }
    }
    /** Signed edition identity only; caller must own the required local guard and gate. */
    public static function assertSignedProEdition(string $installedRoot): void
    {
        $appKeyFile = $installedRoot . '/config/app-key.php';
        $stateFile = $installedRoot . '/storage/install-state.json';
        if (is_link($appKeyFile) || is_link($stateFile)
            || !is_file($appKeyFile) || !is_readable($appKeyFile)
            || !is_file($stateFile) || !is_readable($stateFile)
            || (filesize($appKeyFile) ?: 0) > 4096
            || (filesize($stateFile) ?: 0) > 4096) {
            throw new RuntimeException('Pro worker signed edition identity is unsafe.');
        }
        $key = require $appKeyFile;
        $raw = file_get_contents($stateFile);
        if (!is_string($key) || !is_string($raw)
            || !InstallationState::valid($raw, $key)) {
            throw new RuntimeException('Pro worker requires a valid signed installation.');
        }
        $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if ($state['edition'] !== 'pro') {
            throw new RuntimeException('A Lite license cannot start Pro worker execution.');
        }
    }
}
