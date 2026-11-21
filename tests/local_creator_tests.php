<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Creator\CreatorSchema;
use AdultCore\Creator\LocalCreators;
use AdultCore\Auth\RolePolicy;

$checks=0;
$ok=static function(bool $truth,string $description) use (&$checks):void {
    if (!$truth) throw new RuntimeException('Local creator test: '.$description);
    $checks++;
};
$ok(CreatorSchema::VERSION==='m13_local_creator_workspace_v1','Local additive migration ID');
$ok(LocalCreators::email(' Local.Member@Example.test ')==='local.member@example.test',
    'Owner selects local member by normalized email');
$ok(LocalCreators::displayName('  My <Creator>  ')==='My <Creator>',
    'Creator display names are stored as plain text');
$ok(LocalCreators::draftTitle('  Private <draft>  ')==='Private <draft>',
    'Private title remains user text for escaped rendering');
$ok(LocalCreators::draftSummary('  One private text-only planning idea.  ')
    ==='One private text-only planning idea.',
    'Draft summary normalizes trailing space');
foreach (['','x',str_repeat('q',101),"Mixed\nLine","Invalid\x00Name","\xff"] as $bad) {
    try {
        LocalCreators::displayName($bad);
        $ok(false,'Invalid display name accepted');
    } catch (RuntimeException) { $ok(true,'Invalid display name denied'); }
}
foreach (['','a',str_repeat('t',191),"\tcontrol","\xff"] as $bad) {
    try {
        LocalCreators::draftTitle($bad);
        $ok(false,'Invalid private title accepted');
    } catch (RuntimeException) { $ok(true,'Invalid private title refused'); }
}
foreach (['','short',str_repeat('q',1201),"\x00 embedded secret","\xff"] as $bad) {
    try {
        LocalCreators::draftSummary($bad);
        $ok(false,'Invalid private summary accepted');
    } catch (RuntimeException) { $ok(true,'Invalid private summary refused'); }
}
$ok(RolePolicy::allows('owner','members.manage')
    && RolePolicy::allows('admin','members.manage')
    && !RolePolicy::allows('moderator','members.manage')
    && !RolePolicy::allows('member','members.manage'),
    'Only the installation manager controls local creator profiles');
$source=(string)file_get_contents(dirname(__DIR__).'/src/Creator/LocalCreators.php');
$ok(!str_contains($source,'INSERT INTO ac_content')
    && !str_contains($source,'INSERT INTO ac_media')
    && !str_contains($source,'UPDATE ac_age_verifications')
    && !str_contains($source,"'published',"),
    'Creator drafts never implicitly publish, upload or verify age');
$controller=(string)file_get_contents(dirname(__DIR__).'/src/Http/CreatorAdminController.php');
$ok(str_contains($source,'ac_creator_draft_events')
    && str_contains($controller,'Text-review history')
    && str_contains($controller,'No feedback notes or private content'),
    'Site owner can audit numeric review history without replicating feedback in event log');
$ok(str_contains($controller,'privatePlansForOwner')
    && str_contains($controller,'/admin/creators/drafts/')
    && str_contains($controller,'Cache-Control: private, no-store')
    && str_contains($controller,'X-Robots-Tag: noindex, nofollow'),
    'Local owner read-only planning view protects sensitive drafts');
$ok(str_contains($controller,'AdminSession::csrfValid')
    && str_contains($controller,"'members.manage'")
    && !str_contains($controller,"action==='approve'")
    && !str_contains($controller,'value="approve"'),
    'Creator management is a privileged local owner route, no creator approval');
$member=(string)file_get_contents(dirname(__DIR__).'/src/Http/MemberController.php');
$ok(str_contains($member,'MemberSession::csrfValid')
    && str_contains($member,'CreatorSchema::VERSION')
    && !str_contains($member,'/members/creator/apply'),
    'Member creator workspace is permissioned; there is no application route');
echo "Passed {$checks} self-hosted local creator checks.\n";
