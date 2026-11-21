<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use PDO;

/** Only active privileged accounts may log in or retain admin sessions. */
final class AdminAccounts
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The base installer schema predates member-account migrations. Its first
     * administrator MUST be able to sign in to apply the additive migration.
     * Once the status column exists, suspended identities fail closed.
     */
    private function hasAccountStatus(): bool
    {
        $q=$this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_users' AND COLUMN_NAME='status'");
        return (int)$q->fetchColumn()===1;
    }

    /** @return array{id: int, email: string, role: string}|null */
    public function verifyCredentials(string $email, string $password): ?array
    {
        // The input bounds also avoid silent bcrypt truncation at 72 bytes.
        if (strlen($email) > 190 || strlen($password) > 72 || strlen($password) === 0) {
            return null;
        }
        $email = strtolower(trim($email));
        $sql='SELECT id, email, password_hash, role FROM ac_users WHERE email = ?';
        if ($this->hasAccountStatus()) $sql.=" AND status='active'";
        $statement=$this->pdo->prepare($sql.' LIMIT 1');
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

    /**
     * Staff revocation version, absent on an installation before site setup.
     * A suspended/restored moderator must obtain a NEW session after rotation.
     */
    public function staffSessionEpoch(int $id): int
    {
        if ($id<1) return -1;
        $schema=$this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_users'
                AND COLUMN_NAME='staff_session_epoch'");
        if ((int)$schema->fetchColumn()===0) return 0;
        $q=$this->pdo->prepare('SELECT staff_session_epoch FROM ac_users WHERE id=? LIMIT 1');
        $q->execute([$id]);
        $epoch=$q->fetchColumn();
        return $epoch===false ? -1 : (int)$epoch;
    }

    /** @return array{id: int, email: string, role: string}|null */
    public function authorizedById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $sql='SELECT id, email, role FROM ac_users WHERE id = ?';
        if ($this->hasAccountStatus()) $sql.=" AND status='active'";
        $statement=$this->pdo->prepare($sql.' LIMIT 1');
        $statement->execute([$id]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || !RolePolicy::allows((string) $user['role'], 'admin.access')) {
            return null;
        }
        return ['id' => (int) $user['id'], 'email' => (string) $user['email'], 'role' => (string) $user['role']];
    }
}
