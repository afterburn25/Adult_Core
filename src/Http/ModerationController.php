<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Moderation\ModerationRepository;
use AdultCore\Moderation\ModerationSchema;
use AdultCore\Media\PrivateMedia;
use AdultCore\Membership\TierCatalog;
use AdultCore\UI\Layout;
use PDO;
use RuntimeException;
use Throwable;

/** Admin-only quarantine and report triage. Does not approve or publish media. */
final class ModerationController
{
    private string $currentRole='admin';

    public function __construct(private readonly string $root) {}

    /**
     * Binary preview is strictly for the authenticated moderation team.
     * Public/member media paths cannot use this endpoint or bypass review.
     *
     * @param array{id:int,email:string,role:string} $admin
     */
    public function previewImage(string $path, string $method, array $admin): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        if (!RolePolicy::allows($admin['role'], 'content.moderate')) {
            http_response_code(403); return;
        }
        if ($method !== 'GET') {
            header('Allow: GET'); http_response_code(405); return;
        }
        if (preg_match('#^/admin/moderation/image/([1-9][0-9]{0,17})$#D',$path,$match)!==1) {
            http_response_code(404); return;
        }
        try {
            $pdo=Connection::open($this->root);
            $q=$pdo->prepare("SELECT m.private_storage_key,m.mime_type
                FROM ac_media m JOIN ac_content c ON c.id=m.content_id
                WHERE m.id=? AND m.review_status='pending'
                    AND c.status='draft' AND c.visibility='private'
                    AND c.review_status='pending' LIMIT 1");
            $q->execute([(int)$match[1]]);
            $asset=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($asset) || !is_string($asset['mime_type']??null)
                || !PrivateMedia::validMime($asset['mime_type'])) {
                http_response_code(404); return;
            }
            $file=(new PrivateMedia($this->root))->locate((string)$asset['private_storage_key']);
            if ($file===null || !PrivateMedia::safeToServe($file['path'], (string)$asset['mime_type'], $file['bytes'])) {
                http_response_code(404); return;
            }
            header('Content-Type: '.$asset['mime_type']);
            header('Content-Length: '.$file['bytes']);
            header('Content-Disposition: inline; filename="private-review-image"');
            readfile($file['path']);
        } catch (Throwable) {
            http_response_code(503);
        }
    }

    /**
     * GET-only moderator detail for unpublished PRIVATE draft content.
     * Rejected and quarantined drafts may be inspected but never previewed
     * through an image URL unless every existing preview guard still passes.
     *
     * @param array{id:int,email:string,role:string} $admin
     */
    public function draftPage(string $path,string $method,array $admin): void
    {
        $this->currentRole=$admin['role'];
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (!RolePolicy::allows($admin['role'],'content.moderate')) {
            $this->render(403,'Access denied','<p>Moderation permission required.</p>');
            return;
        }
        if ($method!=='GET') {
            header('Allow: GET');
            $this->render(405,'Method not allowed','<p>Draft review is read-only.</p>');
            return;
        }
        if (preg_match('#^/admin/moderation/draft/([1-9][0-9]{0,17})$#D',$path,$match)!==1) {
            $this->render(404,'Not found','<p>Private draft unavailable.</p>');
            return;
        }
        try {
            $record=(new ModerationRepository(Connection::open($this->root)))
                ->privateDraftDetail((int)$match[1]);
            if ($record===null) {
                $this->render(404,'Not found','<p>Private draft unavailable.</p>');
                return;
            }
            $content=$record['content'];
            $id=(int)$content['id'];
            $state=(string)$content['status'];
            $review=(string)$content['review_status'];
            $allowPreview=$state==='draft' && $review==='pending';
            $html='<p class="intro">This review screen is private to authorized moderators. '
                .'It cannot approve, publish, restore or delete any content.</p>'
                .'<section class="panel"><h2>'.self::e((string)$content['title']).'</h2>'
                .'<p><strong>Draft #'.$id.'</strong> · '
                .self::e($state).' / '.self::e($review)
                .' · Private only</p>'
                .'<p>Expected performers: '.(int)$content['expected_performers']
                .' · Future tier ID: '.($content['tier_id']===null?'Not assigned':(int)$content['tier_id'])
                .'</p></section><section class="panel"><h2>Private image review</h2>'
                .'<p>Pending image previews are non-cacheable and require separate authorization. '
                .'A rejected image or quarantined draft cannot be previewed.</p><ul>';
            foreach ($record['images'] as $media) {
                $mediaId=(int)$media['id'];
                $stateLabel=self::e((string)$media['review_status']);
                $html.='<li>Image #'.$mediaId.' · '.self::e((string)$media['mime_type'])
                    .' · '.$stateLabel;
                if ($allowPreview && $media['review_status']==='pending') {
                    $html.=' · <a href="/admin/moderation/image/'.$mediaId
                        .'" target="_blank" rel="noopener noreferrer">Inspect privately</a>';
                }
                $html.='</li>';
            }
            $html.='</ul></section><section class="panel"><h2>Performer evidence status</h2>'
                .'<p>These are review-state indicators only, never proof of independent consent.</p><ul>';
            foreach ($record['performers'] as $performer) {
                $html.='<li>Record #'.(int)$performer['id'].' · '
                    .self::e((string)$performer['review_status'])
                    .' · Identity: '.($performer['verified_at']===null?'Pending':'Assertion recorded')
                    .' · Consent: '.($performer['consent_at']===null?'Pending':'Assertion recorded')
                    .'</li>';
            }
            $html.='</ul></section><section class="panel"><h2>Private decision history</h2>'
                .'<ul>';
            foreach ($record['history'] as $action) {
                $html.='<li>'.self::e((string)$action['action'])
                    .' · '.self::e((string)$action['created_at'])
                    .' · '.self::e((string)$action['note']).'</li>';
            }
            $html.='</ul></section><p><a href="/admin/moderation">Back to moderation queue</a></p>';
            $this->render(200,'Private draft review',$html);
        } catch (Throwable) {
            $this->render(503,'Review unavailable','<p>Private draft review could not be loaded.</p>');
        }
    }

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method, array $admin): void
    {
        $this->currentRole=$admin['role'];
        if (!RolePolicy::allows($admin['role'], 'content.moderate')) {
            $this->render(403,'Access denied','<p>Moderation permission required.</p>'); return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST'); $this->render(405,'Method not allowed','<p>Unsupported method.</p>'); return;
        }
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the page to continue.</p>'); return;
        }
        try {
            $pdo=Connection::open($this->root);
            $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $q->execute([ModerationSchema::VERSION]);
            if ((int)$q->fetchColumn()!==1) {
                $this->render(503,'Schema update required','<p><a href="/admin/setup">Apply additive database migrations</a> before using moderation.</p>');return;
            }
            $repo=new ModerationRepository($pdo);
            if ($method==='POST') {
                $action=$_POST['action']??null;
                if (!is_string($action)) throw new RuntimeException('Unknown moderation action.');
                if ($action==='new_draft') {
                    if (!is_string($_POST['title']??null)) throw new RuntimeException('Title is required.');
                    $repo->createDraft($_POST['title'], $admin['id']);
                } elseif ($action==='rename_draft') {
                    $repo->renameDraft(
                        self::positiveId($_POST['content_id']??null), $admin['id'],
                        self::safeString($_POST['expected_title']??null),
                        self::safeString($_POST['title']??null)
                    );
                } elseif ($action==='assign_draft_tier') {
                    $repo->assignDraftTier(
                        self::positiveId($_POST['content_id']??null),$admin['id'],
                        self::optionalId($_POST['expected_tier_id']??null),
                        self::optionalId($_POST['tier_id']??null)
                    );
                } elseif ($action==='reject_draft_image') {
                    $repo->rejectDraftImage(
                        self::positiveId($_POST['media_id']??null),$admin['id'],
                        self::safeString($_POST['note']??null)
                    );
                } elseif ($action==='declare_performers') {
                    $repo->declarePerformers(self::positiveId($_POST['content_id']??null),$admin['id'],self::positiveId($_POST['performer_count']??null));
                } elseif ($action==='upload_draft_image') {
                    $upload=$_FILES['draft_image']??null;
                    if (!is_array($upload) || ($upload['error']??null)!==UPLOAD_ERR_OK
                        || !is_string($upload['tmp_name']??null)
                        || !is_uploaded_file($upload['tmp_name'])) {
                        throw new RuntimeException('Choose an uploaded JPEG, PNG or WebP image.');
                    }
                    $repo->attachPrivateImage(
                        self::positiveId($_POST['content_id']??null),
                        $admin['id'], $upload['tmp_name'], true, new PrivateMedia($this->root)
                    );
                } elseif (in_array($action,['quarantine','reject'],true)) {
                    $repo->moderateContent(self::positiveId($_POST['content_id']??null),$admin['id'],$action,self::safeString($_POST['note']??null));
                } elseif (in_array($action,['resolved','dismissed'],true)) {
                    $repo->resolveReport(self::positiveId($_POST['report_id']??null),$admin['id'],$action,self::safeString($_POST['note']??null));
                } else {
                    throw new RuntimeException('Unknown moderation action.');
                }
                header('Location: /admin/moderation?result=saved',true,303); return;
            }
            $this->render(200,'Content moderation',$this->dashboard($repo,$pdo));
        } catch (RuntimeException $e) {
            $allowed=['Unknown moderation action.','Invalid record ID.','Invalid review details.','Unsupported review action.','Content not found.','Report not open.','Unsupported report resolution.','Administrator privileges required.','Title is required.','Use a title between 1 and 190 bytes.','Enter a review explanation between 10 and 500 bytes.',
                'Choose an uploaded JPEG, PNG or WebP image.','Invalid private draft image upload.',
                'Only private, unreviewed drafts accept images.','Private draft image limit reached.',
                'Invalid or oversized image upload.','Image type not allowed.','Image type or dimensions not allowed.',
                'Private image could not be safely inspected.','Image contains metadata or an unsupported image structure.',
                'Private storage unavailable.','Only private, unreviewed drafts can be edited.',
                'Draft changed; reload the moderation queue before editing.','Draft title is unchanged.',
                'Pending private draft image not found.','Invalid draft tier assignment.',
                'Only private, unreviewed drafts can receive a tier.',
                'Draft tier changed; reload the moderation queue.',
                'Draft tier assignment is unchanged.','Choose an existing membership tier.',
                'Invalid moderation queue cursor.'];
            $message=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'Could not complete the review action.';
            $this->render(422,'Moderation error','<p>'.self::e($message).'</p><p><a href="/admin/moderation">Return to moderation</a>.</p>');
        } catch (Throwable) {
            $this->render(503,'Moderation unavailable','<p>Database unavailable. No review action was confirmed.</p>');
        }
    }

    private static function positiveId(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) throw new RuntimeException('Invalid record ID.');
        $v=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($v===false) throw new RuntimeException('Invalid record ID.');
        return $v;
    }

    /** Empty fields deliberately mean "no required tier" for private drafts. */
    private static function optionalId(mixed $value): ?int
    {
        if ($value===null || $value==='') return null;
        return self::positiveId($value);
    }

    private static function safeString(mixed $value): string
    {
        if (!is_string($value)) throw new RuntimeException('Invalid review details.');
        return $value;
    }

    private function dashboard(ModerationRepository $repo, PDO $pdo): string
    {
        $tiers=(new TierCatalog($pdo))->all();
        $csrf=self::e(AdminSession::csrf(), (new \AdultCore\Licensing\LicenseManager($this->root))->config()['edition'] ?? 'unknown');
        $rawCursor=$_GET['before_content']??null;
        $before=$rawCursor===null?null:(is_string($rawCursor)
            ? filter_var($rawCursor,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : false);
        if ($before===false) {
            throw new RuntimeException('Invalid moderation queue cursor.');
        }
        $contentPage=$repo->contentQueuePage($before);
        $queue=$contentPage['items'];
        $draftOptions='<option value="">Choose an unpublished draft</option>';
        $rosterOptions='<option value="">Choose a draft without a roster</option>';
        foreach ($queue as $draft) {
            if ($draft['status']!=='draft' || $draft['visibility']!=='private'
                || $draft['review_status']!=='pending') continue;
            $option='<option value="'.(int)$draft['id'].'">'
                .'#'.(int)$draft['id'].' — '.self::e((string)$draft['title']).'</option>';
            $draftOptions.=$option;
            if ((int)$draft['expected_performers']===0) $rosterOptions.=$option;
        }
        $html='<p class="intro">Private content stays unpublished until independent age, consent, and media review integrations are implemented. This panel supports draft preparation, quarantine, rejection, and report triage only. Closing a report does not restore content.</p>';
        if (($_GET['result']??'')==='saved') $html.='<p role="status">Moderation action recorded.</p>';
        $html.='<section class="panel"><h2>Create private content draft</h2><form method="post" action="/admin/moderation" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="new_draft">'
            .'<label for="content-title">Draft title</label><input id="content-title" name="title" maxlength="190" required>'
            .'<button class="install-button">Create unpublished draft</button></form></section>';
        $html.='<section class="panel"><h2>Stage a private draft image</h2>'
            .'<p>Only JPEG, PNG or WebP (up to 8 MiB). Files remain outside the public web root and '
            .'pending review. Images containing embedded EXIF/GPS, text metadata, or unsupported image chunks are refused; '
            .'export a metadata-free image first. This action never verifies performers, approves media, or publishes a gallery.</p>'
            .'<form method="post" action="/admin/moderation" enctype="multipart/form-data" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'">'
            .'<input type="hidden" name="action" value="upload_draft_image">'
            .'<label for="draft-image-content">Unpublished draft</label>'
            .'<select id="draft-image-content" name="content_id" required>'.$draftOptions.'</select>'
            .'<label>Private image<input type="file" name="draft_image" accept="image/jpeg,image/png,image/webp" required></label>'
            .'<button class="button-secondary" type="submit">Store image privately for review</button></form></section>';
        $html.='<section class="panel"><h2>Content queue</h2><p class="muted">Draft names can be edited only while unpublished and pending review. Concurrent edits are refused until the queue is reloaded.</p><table class="page-table"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Performer evidence</th><th>Pending images</th><th>Open reports</th><th>Review action</th><th>Draft title</th><th>Future tier</th></tr></thead><tbody>';
        foreach ($queue as $item) {
            $id=(int)$item['id'];
            $html.='<tr><td>'.$id.'</td><td>'.self::e((string)$item['title']).'</td><td>'.self::e((string)$item['status']).' / '.self::e((string)$item['review_status']).'</td>'
                .'<td>'.self::e(self::evidenceSummary($item)).'</td><td>'.(int)($item['pending_images']??0).' pending</td><td>'.(int)$item['open_reports'].'</td><td><form method="post" action="/admin/moderation">'
                .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="content_id" value="'.$id.'">'
                .'<label>Decision<select name="action"><option value="quarantine">Quarantine</option><option value="reject">Reject</option></select></label>'
                .'<label>Reason<input name="note" required minlength="10" maxlength="500" placeholder="Reason for review decision"></label>'
                .'<button class="button-secondary">Record decision</button></form></td><td>';
            if ($item['status']==='draft' && $item['visibility']==='private'
                && $item['review_status']==='pending') {
                $html.='<form method="post" action="/admin/moderation">'
                    .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="action" value="rename_draft">'
                    .'<input type="hidden" name="content_id" value="'.$id.'">'
                    .'<input type="hidden" name="expected_title" value="'.self::e((string)$item['title']).'">'
                    .'<label>Edit title<input name="title" value="'.self::e((string)$item['title']).'" maxlength="190" required></label>'
                    .'<button class="button-secondary">Save private title</button></form>';
            } else {
                $html.='<span class="muted">Not editable</span>';
            }
            $html.='<p><a href="/admin/moderation?audit_content='.$id.'">View audit history</a></p>';
            if ($item['visibility']==='private'
                && in_array($item['status'],['draft','quarantined','rejected'],true)) {
                $html.='<p><a href="/admin/moderation/draft/'.$id.'">Open private draft review</a></p>';
            }
            $html.='</td><td>';
            if ($item['status']==='draft' && $item['visibility']==='private'
                && $item['review_status']==='pending') {
                $priorTier=$item['tier_id']===null?'':(string)(int)$item['tier_id'];
                $html.='<form method="post" action="/admin/moderation">'
                    .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="action" value="assign_draft_tier">'
                    .'<input type="hidden" name="content_id" value="'.$id.'">'
                    .'<input type="hidden" name="expected_tier_id" value="'.$priorTier.'">'
                    .'<label>Future required tier<select name="tier_id">'
                    .'<option value="">No tier assigned</option>';
                foreach ($tiers as $tier) {
                    $tid=(int)$tier['id'];
                    $html.='<option value="'.$tid.'"'.($priorTier===(string)$tid?' selected':'').'>'
                        .self::e($tier['name'])
                        .($tier['is_active']===1?'':' (staging only; disabled for access)').'</option>';
                }
                $html.='</select></label><button class="button-secondary">Save future tier</button></form>'
                    .'<small>Staging only — no access granted</small>';
            } else {
                $html.='<span class="muted">Unavailable</span>';
            }
            $html.='</td></tr>';
        }
        $html.='</tbody></table>';
        if ($contentPage['next']!==null) {
            $html.='<p><a href="/admin/moderation?before_content='
                .(int)$contentPage['next'].'">Older content records</a></p>';
        }
        if ($before!==null) {
            $html.='<p><a href="/admin/moderation">Back to newest content records</a></p>';
        }
        if ($queue===[]) $html.='<p>No content records on this page.</p>';
        $html.='</section>';
        $html.='<section class="panel"><h2>Pending private draft images</h2>'
            .'<p>Previews require moderator sign-in and can be viewed only while the draft remains unpublished. '
            .'Previewing a staged image never constitutes consent, approval, or publication.</p><ul>';
        foreach ($repo->pendingDraftImages() as $image) {
            $id=(int)$image['id'];
            $html.='<li>Draft #'.(int)$image['content_id'].' — '
                .self::e((string)$image['title']).' ('.self::e((string)$image['mime_type']).') '
                .'<a href="/admin/moderation/image/'.$id.'" target="_blank" rel="noopener noreferrer">'
                .'Review pending image #'.$id.'</a>'
                .'<form method="post" action="/admin/moderation" class="install-form">'
                .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                .'<input type="hidden" name="action" value="reject_draft_image">'
                .'<input type="hidden" name="media_id" value="'.$id.'">'
                .'<label>Rejection reason<input name="note" minlength="10" maxlength="500" required'
                .' placeholder="Reason image cannot be accepted"></label>'
                .'<button class="button-secondary">Reject this pending image</button></form></li>';
        }
        $html.='</ul></section>';
        $html.='<section class="panel"><h2>Declare performer roster (draft only)</h2><p>Enter the number of people depicted in the draft. This does not verify anyone or permit publication. Each record receives a private numeric reference for the contracted verification provider.</p>'
            .'<form method="post" action="/admin/moderation" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="declare_performers">'
            .'<label for="roster-draft">Draft without a roster</label>'
            .'<select id="roster-draft" name="content_id" required>'.$rosterOptions.'</select>'
            .'<label>Number of performers<input name="performer_count" type="number" min="1" max="20" required></label>'
            .'<button class="button-secondary">Create pending records</button></form></section>';
        $html.='<section class="panel"><h2>Performer verification records</h2><table class="page-table"><thead><tr><th>Record ID</th><th>Content ID</th><th>Identity</th><th>Consent</th><th>Review</th></tr></thead><tbody>';
        foreach ($repo->performerQueue() as $record) {
            $html.='<tr><td>'.(int)$record['id'].'</td><td>'.(int)$record['content_id'].'</td><td>'.($record['verified_at']===null?'Pending':'Assertion received').'</td><td>'.($record['consent_at']===null?'Pending':'Assertion received').'</td><td>'.self::e((string)$record['review_status']).'</td></tr>';
        }
        $html.='</tbody></table></section>';
        $html.='<section class="panel"><h2>Reports</h2><table class="page-table"><thead><tr><th>Report</th><th>Content</th><th>Reason</th><th>Status</th><th>Disposition</th></tr></thead><tbody>';
        foreach ($repo->reportQueue() as $item) {
            $id=(int)$item['id'];
            $html.='<tr><td>'.$id.'</td><td>'.(int)$item['content_id'].' — '.self::e((string)$item['title']).'</td><td>'.self::e((string)$item['reason']).'</td><td>'.self::e((string)$item['status']).'</td><td>';
            if ($item['status']==='open') {
                $html.='<form method="post" action="/admin/moderation"><input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="report_id" value="'.$id.'"><label>Decision<select name="action"><option value="resolved">Resolved</option><option value="dismissed">Dismissed</option></select></label>'
                    .'<label>Reason<input name="note" required minlength="10" maxlength="500"></label><button class="button-secondary">Close report</button></form>';
            } else { $html.='Closed'; }
            $html.='</td></tr>';
        }
        $html.='</tbody></table></section>';
        $auditId=filter_var($_GET['audit_content']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if ($auditId!==false && $auditId!==null) {
            $html.='<section class="panel"><h2>Private moderation history for content #'.(int)$auditId.'</h2>'
                .'<p class="muted">Newest 50 recorded actions. This private history does not grant review or publication rights.</p>'
                .'<table class="page-table"><thead><tr><th>Action</th><th>Actor</th><th>Reason or outcome</th><th>Time (UTC)</th></tr></thead><tbody>';
            foreach ($repo->auditTrail((int)$auditId) as $event) {
                $html.='<tr><td>'.self::e((string)$event['action']).'</td>'
                    .'<td>'.(int)$event['actor_id'].'</td>'
                    .'<td>'.self::e((string)$event['note']).'</td>'
                    .'<td>'.self::e((string)$event['created_at']).'</td></tr>';
            }
            $html.='</tbody></table><p><a href="/admin/moderation">Close history</a></p></section>';
        }
        $html.='<p class="muted">Review actions are appended to the private ac_moderation_actions audit table. Draft images can be staged privately but remain pending and unpublished. Identity documents and performer verification evidence are never accepted by this admin page.</p>';
        return $html;
    }

    /** Evidence status only: never an automated approval or publishing decision. */
    private static function evidenceSummary(array $item): string
    {
        $expected=(int)($item['expected_performers']??0);
        $recorded=(int)($item['recorded_performers']??0);
        $evidenced=(int)($item['evidenced_performers']??0);
        if ($expected < 1) return 'Roster not declared';
        if ($recorded !== $expected) {
            return sprintf('Roster incomplete (%d of %d records)', $recorded, $expected);
        }
        if ($evidenced < $expected) {
            return sprintf('Pending evidence (%d of %d reviewed)', $evidenced, $expected);
        }
        return sprintf('Evidence recorded (%d of %d) — independent approval still required', $evidenced, $expected);
    }

    private function render(int $code,string $title,string $body): void
    {
        http_response_code($code);
        echo Layout::admin($title,
            '<div class="admin-heading page-heading"><div><p class="eyebrow">TRUST &amp; SAFETY</p><h1>'
            . self::e($title) . '</h1></div><span class="page-heading-tag">Restricted workflow</span></div>' . $body,
            '/admin/moderation', AdminSession::csrf(), (new \AdultCore\Licensing\LicenseManager($this->root))->config()['edition'] ?? 'unknown',
            $this->currentRole);
    }

    private static function e(string $v):string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
