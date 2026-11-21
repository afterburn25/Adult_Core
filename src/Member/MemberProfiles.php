<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Private self-service member name. The member ID MUST come from verified
 * MemberSession, never from a request parameter. Profile text is never public.
 */
final class MemberProfiles
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validateDisplayName(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>100
            || preg_match('//u',$value)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$value)===1) {
            throw new RuntimeException('Use a single-line display name of 2–100 UTF-8 bytes.');
        }
        $name=trim($value);
        if (strlen($name)<2 || strlen($name)>100) {
            throw new RuntimeException('Use a single-line display name of 2–100 UTF-8 bytes.');
        }
        return $name;
    }

    /** @return array{display_name:string,revision:int}|null */
    public function current(int $memberId): ?array
    {
        if (!MemberProfileSchema::installed($this->pdo)) {
            throw new RuntimeException('Private member profile setup required.');
        }
        if ($memberId<1) return null;
        $q=$this->pdo->prepare("SELECT p.display_name,p.revision FROM ac_member_profiles p
            JOIN ac_users u ON u.id=p.user_id
            WHERE p.user_id=? AND u.role='member'
                AND u.status IN ('pending','active') LIMIT 1");
        $q->execute([$memberId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?['display_name'=>(string)$row['display_name'],
            'revision'=>(int)$row['revision']]:null;
    }

    public function save(int $memberId,int $expectedRevision,mixed $input): int
    {
        $name=self::validateDisplayName($input);
        if ($memberId<1 || $expectedRevision<0 || $expectedRevision>=999999999
            || !MemberProfileSchema::installed($this->pdo)) {
            throw new RuntimeException('Private member profile update denied.');
        }
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT role,status FROM ac_users WHERE id=? FOR UPDATE');
            $q->execute([$memberId]);
            $member=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($member) || $member['role']!=='member'
                || !in_array($member['status'],['pending','active'],true)) {
                throw new RuntimeException('Private member profile update denied.');
            }
            $q=$this->pdo->prepare('SELECT display_name,revision
                FROM ac_member_profiles WHERE user_id=? FOR UPDATE');
            $q->execute([$memberId]);
            $prior=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($prior)) {
                if ($expectedRevision!==0) {
                    throw new RuntimeException('Member profile changed; reload before saving.');
                }
                $q=$this->pdo->prepare('INSERT INTO ac_member_profiles
                    (user_id,display_name,revision,updated_at)
                    VALUES (?,?,1,UTC_TIMESTAMP())');
                $q->execute([$memberId,$name]);
                $next=1;
            } else {
                if ((int)$prior['revision']!==$expectedRevision) {
                    throw new RuntimeException('Member profile changed; reload before saving.');
                }
                if (hash_equals((string)$prior['display_name'],$name)) {
                    throw new RuntimeException('Display name is unchanged.');
                }
                $q=$this->pdo->prepare('UPDATE ac_member_profiles SET
                    display_name=?,revision=revision+1,updated_at=UTC_TIMESTAMP()
                    WHERE user_id=? AND revision=?');
                $q->execute([$name,$memberId,$expectedRevision]);
                if ($q->rowCount()!==1) {
                    throw new RuntimeException('Member profile changed; reload before saving.');
                }
                $next=$expectedRevision+1;
            }
            $this->pdo->commit();
            return $next;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
