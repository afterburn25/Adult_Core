<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;

/**
 * Admin-only, read-only member listing. Excludes password hashes, provider
 * identity/age data, financial records and protected media permissions.
 * Caller enforces local owner/admin role, authenticated session, MFA and license.
 */
final class MemberDirectory
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{members:list<array{id:int,email:string,status:string,created_at:string}>,next:?int} */
    public function page(?int $before=null,?string $status=null,?int $memberId=null): array
    {
        if ($before!==null && $before<1) {
            throw new RuntimeException('Invalid member directory cursor.');
        }
        if ($status!==null && !in_array($status,['pending','active','suspended'],true)) {
            throw new RuntimeException('Invalid member directory filter.');
        }
        if ($memberId!==null && ($memberId<1 || $before!==null)) {
            throw new RuntimeException('Invalid numeric member lookup.');
        }
        $sql="SELECT id,email,status,created_at FROM ac_users WHERE role='member'";
        $params=[];
        if ($before!==null) { $sql.=' AND id<?'; $params[]=$before; }
        if ($status!==null) { $sql.=' AND status=?'; $params[]=$status; }
        if ($memberId!==null) { $sql.=' AND id=?'; $params[]=$memberId; }
        $q=$this->pdo->prepare($sql.' ORDER BY id DESC LIMIT 51');
        $q->execute($params);
        $rows=[];
        while (($row=$q->fetch(PDO::FETCH_ASSOC))!==false) {
            $rows[]=['id'=>(int)$row['id'],'email'=>(string)$row['email'],
                'status'=>(string)$row['status'],'created_at'=>(string)$row['created_at']];
        }
        $more=count($rows)>50;
        if ($more) array_pop($rows);
        return ['members'=>$rows,
            'next'=>$more && $rows!==[] ? $rows[count($rows)-1]['id'] : null];
    }
}
