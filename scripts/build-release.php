<?php

declare(strict_types=1);

/**
 * Developer-only source distribution builder. One package contains both Lite
 * and Pro; a real Cajun Web Pro license key chooses the installed edition.
 * The checksum is an integrity aid, NOT an authenticity signature.
 */
$root = dirname(__DIR__);
$dest = $argv[1] ?? null;
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !is_string($dest)
    || !str_ends_with($dest, '.zip') || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Usage: php scripts/build-release.php /absolute/path/release.zip (requires ext-zip)\n");
    exit(2);
}
$parent = realpath(dirname($dest));
$source = realpath($root);
if ($parent === false || $source === false || $dest[0] !== '/'
    || $parent === $source || str_starts_with($parent, $source.'/')
    || is_link($dest) || (file_exists($dest) && !is_file($dest))) {
    fwrite(STDERR, "Choose a regular ZIP destination outside the Adult_Core source and web root.\n");
    exit(2);
}
$zip = new ZipArchive();
if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not open release archive destination.\n");
    exit(1);
}
$files = [];
try {
    $directories = ['src', 'public', 'install', 'config/editions', 'modules', 'themes', 'docs', 'scripts', 'tests', '.github', 'examples'];
    foreach ($directories as $directory) {
        if (!is_dir($root.'/'.$directory) || is_link($root.'/'.$directory)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) continue;
            $name = substr($entry->getPathname(), strlen($root)+1);
            if (str_contains($name, 'private') || str_contains('/'.$name.'/', '/__pycache__/')
                || preg_match('/\.(?:pem|key|log|pyc|pyo)$/D', $name)) continue;
            if (!$zip->addFile($entry->getPathname(), $name)) {
                throw new RuntimeException('Could not add source release file.');
            }
            $files[$name] = true;
        }
    }
    foreach (['README.md', 'AGENTS.md', 'composer.json', '.gitignore', 'VERSION'] as $name) {
        if (is_file($root.'/'.$name) && !is_link($root.'/'.$name)) {
            if (!$zip->addFile($root.'/'.$name, $name)) {
                throw new RuntimeException('Could not add top-level release file.');
            }
            $files[$name] = true;
        }
    }
    foreach ([
        'public/index.php', 'install/index.php',
        'src/Membership/MemberLibrary.php', 'src/Licensing/LicenseManager.php',
        'src/Support/ReleaseReadiness.php', 'src/Support/ReleaseMilestones.php',
        'src/Billing/WebhookInbox.php', 'src/Pro/JobInbox.php',
        'src/Media/PrivateMediaAudit.php', 'scripts/audit-private-media.php',
        'src/Verification/VerificationRegistry.php',
        'config/editions/lite.json', 'config/editions/pro.json',
        'VERSION',
    ] as $required) {
        if (!isset($files[$required])) {
            throw new RuntimeException('Required source file absent from release inventory: '.$required);
        }
    }
    if (!$zip->close()) throw new RuntimeException('Could not finish the release archive.');
    $hash = hash_file('sha256', $dest);
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
        throw new RuntimeException('Could not hash the completed release.');
    }
    if (file_put_contents($dest.'.sha256', $hash.'  '.basename($dest)."\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write release checksum.');
    }
    echo "Source release built: {$dest}\nSHA-256: {$hash}\n";
} catch (Throwable $error) {
    // No incomplete ZIP should be distributed as an apparently complete build.
    $zip->close();
    @unlink($dest);
    @unlink($dest.'.sha256');
    fwrite(STDERR, "Source release failed: ".$error->getMessage()."\n");
    exit(1);
}
