<?php

declare(strict_types=1);

/** Local read-only diagnostics. Not a deployment, license issuer or hosting certificate. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Capability\DeploymentPreflight;

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--(document-root|expected-edition|phase|json)=(.*)$/D', $arg, $m)
        || isset($args[$m[1]]) || strlen($m[2]) > 4096) {
        fwrite(STDERR, "Usage: php scripts/deployment-preflight.php --document-root=/absolute/host/path/public --expected-edition=lite|pro --phase=preinstall|installed [--json=1]\n");
        exit(64);
    }
    $args[$m[1]] = $m[2];
}
if (!isset($args['document-root'], $args['expected-edition'], $args['phase'])
    || isset($args['json']) && $args['json'] !== '1') {
    fwrite(STDERR, "Required: --document-root=absolute-path --expected-edition=lite|pro --phase=preinstall|installed. Optional: --json=1\n");
    exit(64);
}
try {
    $result = (new DeploymentPreflight(dirname(__DIR__)))->inspect(
        $args['document-root'], $args['expected-edition'], $args['phase']
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Deployment preflight cannot start: " . $e->getMessage() . "\n");
    exit(64);
}
if (isset($args['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} else {
    echo "Adult_Core deployment preflight | expected ", strtoupper($result['expected_edition']),
        " | ", $result['phase'], " | ", $result['result'], "\n";
    foreach ($result['checks'] as $check) {
        echo ($check['pass'] ? '[PASS] ' : '[BLOCK] '), $check['id'], ' — ', $check['detail'], "\n";
    }
    echo "External checks still required on actual hosting:\n";
    foreach ($result['external_verification_required'] as $item) echo '- ', $item, "\n";
}
exit($result['blockers'] === 0 ? 0 : 2);
