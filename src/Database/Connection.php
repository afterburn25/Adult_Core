<?php

declare(strict_types=1);

namespace AdultCore\Database;

use PDO;
use RuntimeException;

/** Reads the private config produced by the installer; never accepts request-supplied DSNs. */
final class Connection
{
    public static function open(string $root): PDO
    {
        $file = $root . '/config/database.php';
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Database configuration unavailable.');
        }
        $config = require $file;
        if (!is_array($config) || ($config['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Unsupported database configuration.');
        }
        foreach (['host', 'database', 'username', 'password', 'port'] as $field) {
            if (!isset($config[$field]) || (!is_string($config[$field]) && $field !== 'port')) {
                throw new RuntimeException('Invalid database configuration.');
            }
        }
        $host = $config['host'];
        $name = $config['database'];
        $port = (int) $config['port'];
        if (preg_match('/^(?:[a-zA-Z0-9][a-zA-Z0-9._-]*|\[[a-fA-F0-9:]+\])$/D', $host) !== 1
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1
            || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid database configuration.');
        }
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ],
        );
    }
}
