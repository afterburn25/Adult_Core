<?php

declare(strict_types=1);

namespace AdultCore\Release;

use AdultCore\Installer\InstallationGate;
use RuntimeException;

/**
 * Local exclusive guard for EXISTING console-only license/edition recovery.
 * It does not authorize recovery on its own: callers must still verify the
 * original signed rollback material under their domain-specific locks.
 */
final class ConsoleRecoveryGuard
{
    /** @return resource */
    public static function enter(string $root, string $expectedState)
    {
        $states = [
            InstallationGate::EDITION_UPGRADE_PENDING => '.edition-upgrade-pending',
            InstallationGate::LICENSE_UPDATE_PENDING => '.license-update-pending',
        ];
        if (PHP_SAPI !== 'cli' || !isset($states[$expectedState])) {
            throw new RuntimeException('Console recovery requires an explicitly supported pending operation.');
        }

        $lock = SourceHttpRequestLock::exclusive($root);
        try {
            // InstallationGate prioritizes pending states. Reject overlapping
            // markers explicitly rather than allowing a higher-priority
            // recovery state to hide an unrelated source/backup operation.
            foreach ([
                '.edition-upgrade-pending', '.license-update-pending',
                '.backup-restore-pending', '.backup-maintenance',
                '.source-update-maintenance',
            ] as $marker) {
                $path = $root . '/storage/' . $marker;
                clearstatcache(true, $path);
                if ($marker !== $states[$expectedState]
                    && (file_exists($path) || is_link($path))) {
                    throw new RuntimeException('Overlapping maintenance or recovery requires manual operator review.');
                }
            }
            if ((new InstallationGate($root))->status() !== $expectedState) {
                throw new RuntimeException('The expected pending recovery operation is absent.');
            }
            return $lock;
        } catch (\Throwable $error) {
            fclose($lock);
            throw $error;
        }
    }
}
