<?php

declare(strict_types=1);

namespace AdultCore\Membership;

use PDO;
use RuntimeException;

/**
 * Member-only catalog; titles are private metadata too.
 * No candidate row reaches the caller unless the established content/age/
 * consent/moderation/payment entitlement policy authorizes this member.
 */
final class MemberLibrary
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array{id:int,title:string,visibility:string}> */
    public function visible(int $memberId, int $limit = 24): array
    {
        if ($memberId < 1 || $limit < 1 || $limit > 50) {
            throw new RuntimeException('Invalid private library request.');
        }
        $gate = new Entitlements($this->pdo);
        // Bound the candidate scan; don't expose unauthorized counts or
        // unpublished titles to the page or its surrounding layout.
        $stmt = $this->pdo->query("SELECT id,title,visibility FROM ac_content
            WHERE status='published' AND review_status='approved'
              AND visibility IN ('members','paid')
            ORDER BY id DESC LIMIT 250");
        $visible = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int)($row['id'] ?? 0);
            $title = $row['title'] ?? null;
            $visibility = $row['visibility'] ?? null;
            if ($id < 1 || !is_string($title) || !in_array($visibility, ['members','paid'], true)
                || !$gate->canView($memberId, $id)) {
                continue;
            }
            $visible[] = ['id'=>$id, 'title'=>$title, 'visibility'=>$visibility];
            if (count($visible) === $limit) break;
        }
        return $visible;
    }

    /** Safe text-only HTML; no unreviewed preview images or raw user markup. */
    public static function render(array $visible): string
    {
        $out = '<section class="panel"><h2>Your approved library</h2>';
        if ($visible === []) {
            return $out . '<p>No content is currently available for this account. '
                . 'Access requires independent age verification, approved consent, '
                . 'moderation review and any applicable paid membership.</p></section>';
        }
        $out .= '<ul class="member-library">';
        foreach ($visible as $item) {
            $id = $item['id'] ?? null;
            $title = $item['title'] ?? null;
            $visibility = $item['visibility'] ?? null;
            if (!is_int($id) || $id < 1 || !is_string($title)
                || !in_array($visibility, ['members','paid'], true)) continue;
            $name = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            // Link to a guarded gallery detail route in a future increment.
            // Until that endpoint exists, show approved titles without links.
            $out .= '<li><strong>'.$name.'</strong> <small>'
                . ($visibility === 'paid' ? 'Paid membership' : 'Members')
                . '</small></li>';
        }
        return $out.'</ul></section>';
    }
}
