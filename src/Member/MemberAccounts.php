<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;

/** Public signups remain pending; no self-certified age verification or content unlocks. */
final class MemberAccounts
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validate(string $email, string $password): void
    {
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($password) < 12 || strlen($password) > 72) {
            throw new RuntimeException('Use a valid email and a password between 12 and 72 bytes.');
        }
    }

    public function register(string $email, string $password): void
    {
        $email = strtolower(trim($email));
        self::validate($email, $password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $s = $this->pdo->prepare("INSERT INTO ac_users (email,password_hash,role,status,created_at) VALUES (?,?,'member','pending',UTC_TIMESTAMP())");
        $s->execute([$email, $hash]);
    }

    /** @return array{id:int,email:string,status:string}|null */
    public function verify(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 190 || strlen($password) > 72 || strlen($password) === 0) return null;
        $s = $this->pdo->prepare("SELECT id,email,password_hash,status FROM ac_users WHERE email=? AND role='member' LIMIT 1");
        $s->execute([$email]);
        $record = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record) || !password_verify($password, $record['password_hash'])
            || !in_array($record['status'], ['pending', 'active'], true)) return null;
        return ['id'=>(int)$record['id'],'email'=>$record['email'],'status'=>$record['status']];
    }

    /** @return array{id:int,email:string,status:string}|null */
    public function byId(int $id): ?array
    {
        if ($id < 1) return null;
        $s = $this->pdo->prepare("SELECT id,email,status FROM ac_users WHERE id=? AND role='member' LIMIT 1");
        $s->execute([$id]);
        $record = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record) || !in_array($record['status'], ['pending','active'], true)) return null;
        return ['id'=>(int)$record['id'],'email'=>$record['email'],'status'=>$record['status']];
    }
}
