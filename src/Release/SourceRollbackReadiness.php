<?php

declare(strict_types=1);

namespace AdultCore\Release;

use RuntimeException;

/**
 * Verifies that the previous signed source release has been preserved in a
 * separate private stage before a future source transition is considered.
 *
 * This cannot restore databases, activate a stage, or perform real rollback.
 */
final class SourceRollbackReadiness
{
    /** @return array<string,mixed> */
    public static function inspect(
        string $installedRoot,
        string $previousStage,
        string $candidateStage,
        string $previousZip,
        string $previousManifestJson,
        string $previousSignatureBase64,
        string $candidateZip,
        string $candidateManifestJson,
        string $candidateSignatureBase64,
        string $trustedPublicKeyPem
    ): array {
        // Fail closed if installed source has drift, either release is
        // unauthenticated, or the candidate staged tree is incomplete.
        $preflight = SourceUpdatePreflight::inspect(
            $installedRoot, $candidateStage,
            $previousZip, $previousManifestJson, $previousSignatureBase64,
            $candidateZip, $candidateManifestJson, $candidateSignatureBase64,
            $trustedPublicKeyPem
        );
        // The rollback copy is authenticated independently against the OLD
        // signed release, not against the new candidate.
        $rollback = SourceStageVerifier::verify(
            $previousStage, $previousZip,
            $previousManifestJson, $previousSignatureBase64, $trustedPublicKeyPem
        );

        $root = realpath($installedRoot);
        $old = realpath($previousStage);
        $new = realpath($candidateStage);
        if ($root === false || $old === false || $new === false
            || is_link($installedRoot) || is_link($previousStage) || is_link($candidateStage)
            || $old === $new || $old === $root || $new === $root
            || str_starts_with($old, $root . '/')
            || str_starts_with($new, $root . '/')
            || str_starts_with($root, $old . '/')
            || str_starts_with($root, $new . '/')
            || str_starts_with($old, $new . '/')
            || str_starts_with($new, $old . '/')) {
            throw new RuntimeException('Private rollback and candidate stages must be independent of the installed site.');
        }
        if (($rollback['stage_verified'] ?? null) !== true
            || ($rollback['ready_for_install'] ?? null) !== false
            || ($rollback['version'] ?? null) !== $preflight['installed_version']
            || ($preflight['candidate_stage_verified'] ?? null) !== true
            || ($preflight['ready_for_activation'] ?? null) !== false) {
            throw new RuntimeException('Signed previous source snapshot is inconsistent with transition preflight.');
        }

        // This is evidence of signed SOURCE bytes, not a deployment lock,
        // database-compatible recovery point, or an atomic rollback channel.
        return [
            'schema' => 'adult-core-source-rollback-readiness-v1',
            'read_only' => true,
            'previous_source_stage_verified' => true,
            'candidate_source_stage_verified' => true,
            'installed_runtime_integrity_verified' => true,
            'previous_version' => $preflight['installed_version'],
            'candidate_version' => $preflight['candidate_version'],
            'previous_source_files_preserved' => $rollback['verified_files'],
            'candidate_runtime_files' => $preflight['candidate_runtime_files'],
            'runtime_changes' => count($preflight['would_replace']),
            'runtime_additions' => count($preflight['would_add']),
            'installer_excluded' => true,
            'ready_for_activation' => false,
            'rollback_implemented' => false,
            'database_rollback_verified' => false,
            'license_authorized' => false,
            'live_host_accepted' => false,
        ];
    }
}
