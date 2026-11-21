<?php

declare(strict_types=1);

namespace AdultCore\Capability;

use AdultCore\Edition\EditionSelector;
use AdultCore\Installer\InstallationGate;
use AdultCore\Licensing\LicenseManager;
use RuntimeException;

/**
 * Operator-only, read-only deployment triage. This is NOT a license issuer, a
 * hosting certificate or an alternate edition selector. The paid key is still
 * the sole installation-edition authority.
 */
final class DeploymentPreflight
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param 'lite'|'pro' $expectedEdition An operator expectation, never an installer input.
     * @param 'preinstall'|'installed' $phase
     * @return array<string,mixed>
     */
    public function inspect(string $documentRoot, string $expectedEdition, string $phase): array
    {
        if (!in_array($expectedEdition, ['lite', 'pro'], true)) {
            throw new RuntimeException('Expected edition must be lite or pro. This does not select the installed edition.');
        }
        if (!in_array($phase, ['preinstall', 'installed'], true)) {
            throw new RuntimeException('Phase must be preinstall or installed.');
        }
        if ($documentRoot === '' || $documentRoot[0] !== '/') {
            throw new RuntimeException('Supply the absolute web server document root, not a guessed relative path.');
        }

        $root = realpath($this->root);
        if ($root === false || !is_dir($root . '/public')) {
            throw new RuntimeException('The Adult_Core project root is missing.');
        }
        $host = (new HostInspector($root))->inspect($documentRoot);
        $selector = new EditionSelector($root . '/config/editions');
        $missing = $selector->missing($expectedEdition, $host['capabilities']);
        $checks = [];
        $record = static function (string $id, bool $pass, string $detail) use (&$checks): void {
            $checks[] = ['id' => $id, 'pass' => $pass, 'detail' => $detail];
        };
        foreach ($host['checks'] as $check) {
            // The optional agent and optional ZIP support are only mandatory if
            // the license-selected edition's manifest requires their capabilities.
            $required = match ($check['name']) {
                'Verified Pro background service' => $expectedEdition === 'pro',
                'PHP Phar ZIP archive support' => false,
                default => true,
            };
            if ($required) {
                $record('host_' . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $check['name'])),
                    (bool)$check['pass'], (string)$check['detail']);
            }
        }
        $record('edition_capabilities', $missing === [], $missing === []
            ? 'Required capabilities detected for the expected edition.'
            : 'Missing: ' . implode(', ', $missing) . '. A valid key cannot bypass host requirements.');

        $public = $root . '/public';
        $record('public_front_controller', is_file($public . '/index.php') && !is_link($public . '/index.php'),
            'The public/ front controller must be a real file.');
        $record('public_router', is_file($public . '/router.php') && !is_link($public . '/router.php'),
            'The bundled PHP development router must remain available for isolated HTTP tests.');
        $apacheConfig = $public . '/.htaccess';
        $apacheRules = is_file($apacheConfig) && !is_link($apacheConfig) ? @file_get_contents($apacheConfig) : false;
        $record('apache_routing_file', is_string($apacheRules)
                && str_contains($apacheRules, 'RewriteEngine On')
                && str_contains($apacheRules, 'Options -Indexes'),
            'Bundled Apache routing and directory-listing guards must be present; actual mod_rewrite support must be checked on hosting.');

        $unsafe = PublicRootSafety::unsafePaths($public);
        $record('no_public_symlinks_or_executables', $unsafe === [], $unsafe === []
            ? 'No linked or unexpected executable files detected under public/.'
            : 'Unexpected public path(s): ' . implode(', ', array_slice($unsafe, 0, 6)) . '. Remove or investigate before deployment.');
        foreach (['install', 'storage', 'config', 'src', 'tests', 'scripts'] as $private) {
            $record('private_' . $private, !file_exists($public . '/' . $private) && !is_link($public . '/' . $private),
                'The private ' . $private . '/ tree must not be placed inside public/.');
        }

        $gate = (new InstallationGate($root))->status();
        $expectedGate = $phase === 'preinstall' ? InstallationGate::SETUP_REQUIRED : InstallationGate::READY;
        $record('installation_gate', $gate === $expectedGate,
            $gate === $expectedGate
                ? ($phase === 'preinstall' ? 'Installer still available; site locked until successful installation and manual removal.' : 'Signed installation marker valid and install/ physically removed.')
                : 'Installation gate reports ' . $gate . '; expected ' . $expectedGate . '. Do not bypass the gate.');
        if ($phase === 'preinstall') {
            $existing = [];
            foreach (['config/database.php', 'config/app-key.php', 'config/license.php', 'storage/install-state.json', 'storage/license-cache.json'] as $path) {
                if (file_exists($root . '/' . $path) || is_link($root . '/' . $path)) {
                    $existing[] = $path;
                }
            }
            $record('fresh_private_state', $existing === [], $existing === []
                ? 'No existing installer-generated secrets or completion marker.'
                : 'Existing private setup file(s) detected; do not overwrite a site: ' . implode(', ', $existing) . '.');
        } else {
            $license = (new LicenseManager($root))->config(); // local only: NO HTTP/license-key output
            $record('signed_edition_matches', $license !== null && $license['edition'] === $expectedEdition,
                $license !== null && $license['edition'] === $expectedEdition
                    ? 'Locally signed installation edition and the recorded SKU agree with expectation.'
                    : 'Locally sealed license/edition absent, invalid or different from expected; use the verified upgrade flow.');
        }

        $blocked = count(array_filter($checks, static fn(array $check): bool => !$check['pass']));
        return [
            'schema' => 'adult-core-deployment-preflight-v1',
            'expected_edition' => $expectedEdition,
            'phase' => $phase,
            'result' => $blocked === 0 ? 'local_checks_passed' : 'blocked',
            'blockers' => $blocked,
            'checks' => $checks,
            'external_verification_required' => [
                'Confirm the REAL hosting document root points only to public/ and private trees are inaccessible over HTTPS.',
                'Confirm production PHP-FPM or Apache PHP matches the inspected CLI runtime and Apache rewrite rules work.',
                'Use a real, registered, staging-only Cajun Web Pro license on the intended domain; confirm TLS and revocation.',
                'Pro: separately verify the authenticated persistent VPS service is production-supervised; pro-agent-demo.py is not a production service.',
                'Rehearse full restore from an encrypted offsite backup on the actual hosting provider.',
                'Complete independent security, identity/consent and payment compliance review before production use.',
            ],
        ];
    }

}
