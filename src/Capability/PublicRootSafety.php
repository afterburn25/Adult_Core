<?php

declare(strict_types=1);

namespace AdultCore\Capability;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Static source-layout safety inspection shared by installer and operator preflight.
 * This is NOT a substitute for HTTPS verification of the real web server.
 * Returns relative paths only, never the contents of private files.
 */
final class PublicRootSafety
{
    /** @return list<string> */
    public static function unsafePaths(string $public): array
    {
        if (!is_dir($public) || is_link($public)) {
            return ['public/'];
        }
        $bad = [];
        // Even an empty copied private directory is a misconfigured deployment.
        foreach (['install', 'storage', 'config', 'src', 'tests', 'scripts'] as $private) {
            if (file_exists($public . '/' . $private) || is_link($public . '/' . $private)) {
                $bad[] = 'public/' . $private;
            }
        }
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $iterator->setMaxDepth(16);
            $seen = 0;
            foreach ($iterator as $entry) {
                if (++$seen > 20000) {
                    $bad[] = 'public/ (scan limit exceeded)';
                    break;
                }
                $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($public) + 1));
                if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                    $bad[] = 'public/' . $path;
                    continue;
                }
                if ($entry->isDir() && $iterator->getDepth() >= 16) {
                    $bad[] = 'public/' . $path . ' (scan depth exceeded)';
                    continue;
                }
                if (!$entry->isFile()) continue;
                $base = basename($path);
                if ((str_starts_with($base, '.') && $path !== '.htaccess')
                    || preg_match('/\.(?:php[0-9]?|phtml|phar|cgi|pl|sh|env|ini|sql|log|bak|pem|key)$/iD', $path) === 1
                    || preg_match('/^(?:composer\.(?:json|lock)|license(?:[._-].*)?|app-key(?:[._-].*)?|database(?:[._-].*)?)$/iD', $base) === 1) {
                    if (in_array($path, ['index.php', 'router.php', '.htaccess'], true)) continue;
                    $bad[] = 'public/' . $path;
                }
            }
        } catch (Throwable) {
            $bad[] = 'public/ (scan unavailable)';
        }
        return array_values(array_unique($bad));
    }
}
