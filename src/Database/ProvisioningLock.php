<?php

declare(strict_types=1);

namespace AdultCore\Database;

use PDO;
use RuntimeException;

/**
 * Nonblocking, connection-scoped MySQL/MariaDB database maintenance mutex.
 * Cooperating installation, restoration, backup and edition transitions
 * targeting one database cannot overlap across different application roots.
 * Keep the original provisioning lock namespace for backward compatibility.
 * This is advisory: unrelated SQL clients and older releases can ignore it.
 */
final class ProvisioningLock
{
    private function __construct(private readonly PDO $pdo, private readonly string $name)
    {
    }

    public static function acquire(PDO $pdo): self
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()) {
            throw new RuntimeException('Provisioning requires an idle MySQL connection.');
        }
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '' || strlen($database) > 256) {
            throw new RuntimeException('Provisioning requires a selected database.');
        }
        // GET_LOCK names are scoped to the server and limited to 64 bytes.
        // Folding ASCII case conservatively also handles case-insensitive hosts.
        $name = hash('sha256', "adult-core-db-provisioning-v1\n".strtolower($database));
        $check = $pdo->prepare('SELECT IS_USED_LOCK(?)');
        $check->execute([$name]);
        $owner = $check->fetchColumn();
        $check->closeCursor();
        if ($owner !== null && $owner !== false) {
            throw new RuntimeException('Another database provisioning operation is running.');
        }
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$name]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();
        if ((string) $result !== '1') {
            throw new RuntimeException('Another database provisioning operation is running or the lock is unavailable.');
        }
        return new self($pdo, $name);
    }

    public function assertHeld(): void
    {
        $statement = $this->pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $statement->execute([$this->name]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();
        if ((string) $result !== '1') {
            throw new RuntimeException('Database provisioning ownership was lost.');
        }
    }

    public function release(): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$this->name]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();
        if ((string) $result !== '1') {
            throw new RuntimeException('Database provisioning lock could not be released.');
        }
    }
}
