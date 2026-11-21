<?php

declare(strict_types=1);

namespace AdultCore\Membership;

use AdultCore\Media\PrivateMedia;
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

    /**
     * An individually authorized gallery, with no private storage keys or
     * unpublished metadata. Media delivery must independently reauthorize
     * each request (including any intervening revocation or safety report).
     *
     * @return array{id:int,title:string,visibility:string,media:list<array{id:int,mime_type:string}>}|null
     */
    public function detail(int $memberId, int $contentId): ?array
    {
        if ($memberId < 1 || $contentId < 1) return null;
        if (!(new Entitlements($this->pdo))->canView($memberId, $contentId)) return null;
        $stmt = $this->pdo->prepare("SELECT id,title,visibility FROM ac_content
            WHERE id=? AND status='published' AND review_status='approved'
                AND visibility IN ('members','paid') LIMIT 1");
        $stmt->execute([$contentId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record) || !is_string($record['title'] ?? null)
            || !in_array($record['visibility'] ?? null, ['members','paid'], true)) return null;

        $assets = $this->pdo->prepare("SELECT id,mime_type FROM ac_media
            WHERE content_id=? AND review_status='approved' ORDER BY id ASC LIMIT 24");
        $assets->execute([$contentId]);
        $media=[];
        while (($asset=$assets->fetch(PDO::FETCH_ASSOC))!==false) {
            $mime=$asset['mime_type'] ?? null;
            $id=(int)($asset['id'] ?? 0);
            if ($id > 0 && is_string($mime) && PrivateMedia::validMime($mime)) {
                $media[]=['id'=>$id,'mime_type'=>$mime];
            }
        }
        // A report or revoked entitlement may have arrived while composing
        // the view. Recheck before exposing any private content metadata.
        if (!(new Entitlements($this->pdo))->canView($memberId, $contentId)) return null;
        return [
            'id'=>(int)$record['id'],
            'title'=>$record['title'],
            'visibility'=>$record['visibility'],
            'media'=>$media,
        ];
    }

    /** Pure output renderer: callers must first obtain detail() authorization. */
    public static function renderDetail(array $detail): string
    {
        $title=$detail['title'] ?? null;
        $id=$detail['id'] ?? null;
        $assets=$detail['media'] ?? null;
        if (!is_string($title) || !is_int($id) || $id < 1 || !is_array($assets)) {
            throw new RuntimeException('Invalid authorized gallery data.');
        }
        $safeTitle=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<section class="panel"><h2>'.$safeTitle.'</h2>';
        if ($assets===[]) {
            $html.='<p>There are no approved images available in this gallery.</p>';
        } else {
            $html.='<div class="member-library-images">';
            foreach ($assets as $asset) {
                $mediaId=$asset['id'] ?? null;
                $mime=$asset['mime_type'] ?? null;
                if (!is_int($mediaId) || $mediaId < 1 || !is_string($mime)
                    || !PrivateMedia::validMime($mime)) continue;
                // The endpoint checks age/consent/moderation/paid entitlements
                // again before sending image bytes. Do not embed storage keys.
                $html.='<img src="/media/'.$mediaId.'" alt="Protected gallery image"'
                    .' loading="lazy" decoding="async" width="480">';
            }
            $html.='</div>';
        }
        return $html.'</section>'
            .'<p><a href="/members/report?id='.$id.'">Report a concern about this gallery</a></p>'
            .'<p><a href="/members/library">Back to your library</a></p>';
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
            // Only entitlement-filtered IDs can enter the guarded detail route.
            $out .= '<li><a href="/members/library/'.$id.'"><strong>'.$name.'</strong></a> <small>'
                . ($visibility === 'paid' ? 'Paid membership' : 'Members')
                . '</small></li>';
        }
        return $out.'</ul></section>';
    }
}
