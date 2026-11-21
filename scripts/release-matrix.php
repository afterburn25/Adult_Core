<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Support\ReleaseMilestones;
use AdultCore\Support\ReleaseReadiness;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$args = array_slice($argv, 1);
if (count($args) > 1 || ($args !== [] && $args !== ['--json'])) {
    fwrite(STDERR, "Usage: php scripts/release-matrix.php [--json]\n");
    exit(64);
}
$report = ReleaseMilestones::report();
// The overview never lets a development test convert a missing external signoff
// into production-ready. The existing release checker remains authoritative.
$report['production_ready'] = ReleaseReadiness::productionReady() && $report['open_count'] === 0;
if ($args === ['--json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} else {
    echo "Adult_Core launch workstreams — DEVELOPMENT ONLY; PRODUCTION BLOCKED\n";
    foreach ($report['workstreams'] as $item) {
        echo sprintf(
            "%2d. %-45s [%s]\n    Implemented: %s\n    Remaining: %s\n",
            $item['id'], $item['name'], $item['status'],
            $item['implemented'], $item['remaining']
        );
    }
    echo "\n".$report['open_count']." of 10 production workstreams remain open.\n";
}
// A machine calling this tool must NOT interpret it as deployment permission.
exit($report['production_ready'] ? 0 : 1);
