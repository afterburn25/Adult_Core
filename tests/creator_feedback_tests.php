<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Creator\CreatorFeedbackSchema;
use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Creator\CreatorDraftReview;
use AdultCore\Creator\CreatorFeedbackHistory;

$checks=0;
$check=static function(bool $ok,string $label) use (&$checks):void {
    if (!$ok) throw new RuntimeException('Creator private feedback test: '.$label);
    $checks++;
};
$check(CreatorFeedbackSchema::VERSION==='m15_creator_feedback_history_v1',
    'New review history migration has an explicit version');
$check(CreatorReviewSchema::VERSION==='m14_creator_text_review_v1',
    'Review history has local m14 prerequisite');
$schema=file_get_contents(dirname(__DIR__).'/src/Creator/CreatorFeedbackSchema.php');
$check(str_contains($schema,'UNIQUE KEY uq_review_revision')
    && str_contains($schema,'INSERT IGNORE INTO ac_creator_feedback_history')
    && str_contains($schema,'e.action=d.status')
    && str_contains($schema,'MAX(e2.id)')
    && str_contains($schema,'FOREIGN KEY (draft_id)')
    && str_contains($schema,'FOREIGN KEY (reviewer_id)'),
    'Immutable local revision/actor references and duplicate review constraint');
$review=file_get_contents(dirname(__DIR__).'/src/Creator/CreatorDraftReview.php');
$check(str_contains($review,'INSERT INTO ac_creator_feedback_history')
    && str_contains($review,'$expectedRevision')
    && str_contains($review,"['changes','rejected']")
    && !str_contains($review,"'approved'"),
    'Feedback is persisted only during a denied/changes decision, never approval');
$repo=file_get_contents(dirname(__DIR__).'/src/Creator/CreatorFeedbackHistory.php');
$check(str_contains($repo,"c.status='enabled'")
    && str_contains($repo,"u.role='member'")
    && str_contains($repo,'AND c.status')
    && str_contains($repo,'members.manage')
    && str_contains($repo,'LIMIT 100'),
    'Read-only local timeline is bounded and member/owner permission scoped');
$member=file_get_contents(dirname(__DIR__).'/src/Http/MemberController.php');
$owner=file_get_contents(dirname(__DIR__).'/src/Http/CreatorAdminController.php');
$moderator=file_get_contents(dirname(__DIR__).'/src/Http/CreatorReviewController.php');
$check(str_contains($member,'CreatorFeedbackSchema::VERSION')
    && str_contains($member,'CreatorFeedbackHistory($pdo)')
    && str_contains($owner,'CreatorFeedbackHistory($pdo)')
    && str_contains($moderator,'CreatorFeedbackSchema::VERSION'),
    'Private member, owner and moderator pages require the new setup');
$check(!str_contains($repo,'INSERT INTO ac_content')
    && !str_contains($repo,'INSERT INTO ac_media')
    && !str_contains($repo,'ac_age_verifications')
    && !str_contains($repo,'payment'),
    'Read-only timeline cannot publish media or activate age/payment');
echo "Passed {$checks} local creator private feedback checks.\n";
