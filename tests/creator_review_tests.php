<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Creator\CreatorDraftReview;
use AdultCore\Auth\RolePolicy;

$checks=0;
$assert=static function(bool $good,string $message)use(&$checks):void {
    if (!$good) throw new RuntimeException('Text review boundary: '.$message);
    ++$checks;
};
$assert(CreatorReviewSchema::VERSION==='m14_creator_text_review_v1',
    'Explicit additive review migration version');
$assert(CreatorDraftReview::note('  Please revise this text.  ')==='Please revise this text.',
    'Review feedback is normalized private text');
foreach (['','short',str_repeat('x',501),"\x00 invalid review body","\xff"] as $bad) {
    try {
        CreatorDraftReview::note($bad);
        $assert(false,'Unsafe private review feedback accepted.');
    } catch (RuntimeException) {
        $assert(true,'Unsafe private feedback rejected.');
    }
}
$assert(RolePolicy::allows('owner','content.moderate')
    && RolePolicy::allows('admin','content.moderate')
    && RolePolicy::allows('moderator','content.moderate')
    && !RolePolicy::allows('member','content.moderate'),
    'Only active local moderation staff can review creator plans');
$source=(string)file_get_contents(dirname(__DIR__).'/src/Creator/CreatorDraftReview.php');
$assert(str_contains($source,"['changes','rejected']")
    && !str_contains($source,"['changes','approved']")
    && !str_contains($source,"SET status='approved'")
    && !str_contains($source,'INSERT INTO ac_content')
    && !str_contains($source,'INSERT INTO ac_media')
    && !str_contains($source,'UPDATE ac_age_verifications'),
    'Private text review has no approval, media, public or age-verification transition');
$controller=(string)file_get_contents(dirname(__DIR__).'/src/Http/CreatorReviewController.php');
$assert(str_contains($controller,"'content.moderate'")
    && str_contains($controller,'AdminSession::csrfValid')
    && str_contains($controller,'Cache-Control: private, no-store')
    && str_contains($controller,'X-Robots-Tag: noindex, nofollow')
    && !str_contains($controller,'value="approved"'),
    'Moderator UI requires active role, CSRF and no public approval button');
$member=(string)file_get_contents(dirname(__DIR__).'/src/Http/MemberController.php');
$assert(str_contains($member,"$"."action==='submit_review'")
    && str_contains($member,'MemberSession::csrfValid')
    && str_contains($member,'CreatorReviewSchema::VERSION'),
    'Member can submit through existing authenticated creator workspace only');
echo "Passed {$checks} creator text-review boundary checks.\n";
