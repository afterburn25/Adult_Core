<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use PDO;

/** Database-backed first-admin login and continuous role verification. */
final class AdminAccounts
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id: int, email: string, role: string}|null */
    public function verifyCredentials(string $email, string $password): ?array
    {
        // The input bounds also avoid silent bcrypt truncation at 72 bytes.
        if (strlen($email) > 190 || strlen($password) > 72 || strlen($password) === 0) {
            return null;
        }
        $email = strtolower(trim($email));
        $statement = $this->pdo->prepare('SELECT id, email, password_hash, role FROM ac_users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        // Always perform a password hash comparison even for unknown accounts.
        static $dummyHash = null;
        $dummyHash ??= password_hash('not-a-real-admin-password', PASSWORD_DEFAULT);
        $hash = is_array($user) && is_string($user['password_hash'] ?? null)
            ? $user['password_hash'] : $dummyHash;
        $valid = password_verify($password, $hash);
        if (!$valid || !is_array($user) || !RolePolicy::allows((string) $user['role'], 'admin.access')) {
            return null;
        }
        return ['id' => (int) $user['id'], 'email' => (string) $user['email'], 'role' => (string) $user['role']];
    }

    /** @return array{id: int, email: string, role: string}|null */
    public function authorizedById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id, email, role FROM ac_users WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || !RolePolicy::allows((string) $user['role'], 'admin.access')) {
            return null;
        }
        return ['id' => (int) $user['id'], 'email' => (string) $user['email'], 'role' => (string) $user['role']];
    }
}
