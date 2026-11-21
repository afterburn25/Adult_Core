<?php

declare(strict_types=1);

namespace AdultCore\Membership;

use PDO;
use RuntimeException;

/**
 * Read-only member-scoped subscription records. An "active" database status
 * is NOT evidence of a verified charge or permission to serve adult media.
 */
final class MemberSubscriptionSummary
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array{name:string,status:string,starts_at:string,expires_at:string,is_active:int}> */
    public function forMember(int $memberId): array
    {
        if ($memberId<1) throw new RuntimeException('Invalid member subscription request.');
        $q=$this->pdo->prepare('SELECT t.name,m.status,m.starts_at,m.expires_at,t.is_active
            FROM ac_memberships m JOIN ac_membership_tiers t ON t.id=m.tier_id
            WHERE m.user_id=? ORDER BY m.id DESC LIMIT 20');
        $q->execute([$memberId]);
        $rows=[];
        while (($row=$q->fetch(PDO::FETCH_ASSOC))!==false) {
            $rows[]=[
                'name'=>(string)$row['name'],
                'status'=>(string)$row['status'],
                'starts_at'=>(string)$row['starts_at'],
                'expires_at'=>(string)$row['expires_at'],
                'is_active'=>(int)$row['is_active'],
            ];
        }
        return $rows;
    }

    /** Always label locally stored states as records, never verified payments. */
    public static function render(array $records): string
    {
        $html='<section class="panel"><h2>Membership records</h2>'
            .'<p class="muted">These are locally recorded subscription states, not proof of a completed payment. '
            .'Content access also requires verified age, performer consent, moderation approval and any applicable '
            .'provider-authenticated billing lifecycle. No purchases or cancellations can be made here.</p>';
        if ($records===[]) {
            return $html.'<p>No membership records are linked to this account.</p></section>';
        }
        $html.='<table class="page-table"><thead><tr>'
            .'<th>Tier</th><th>Recorded state</th><th>Tier access setting</th><th>Period (UTC)</th>'
            .'</tr></thead><tbody>';
        foreach (array_slice($records,0,20) as $record) {
            if (!is_array($record)) continue;
            $name=self::e((string)($record['name']??''));
            $status=self::e((string)($record['status']??'unknown'));
            $starts=self::e((string)($record['starts_at']??''));
            $ends=self::e((string)($record['expires_at']??''));
            $tier=(int)($record['is_active']??0)===1
                ? 'Enabled (other checks still apply)' : 'Disabled for access';
            $html.='<tr><td>'.$name.'</td><td>'.$status.'</td><td>'.$tier.'</td>'
                .'<td>'.$starts.' – '.$ends.'</td></tr>';
        }
        return $html.'</tbody></table></section>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
