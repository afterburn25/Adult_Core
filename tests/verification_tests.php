<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Verification\SignedAssertion;
use AdultCore\Verification\VerificationSchema;

$checks=0;
function check(bool $ok,string $why): void {global $checks;if (!$ok)throw new RuntimeException('FAIL: '.$why);$checks++;}
function reject(callable $fn, string $why): void {
    try { $fn(); } catch (RuntimeException) { check(true,$why);return; }
    throw new RuntimeException('FAIL: '.$why);
}
$now=time();
$secret=bin2hex(random_bytes(32));
$claim=['event_id'=>'event_demo_0001','claim_type'=>'member_age','subject_id'=>42,
    'decision'=>'verified','reference'=>'provider-reference-0001','occurred_at'=>$now];
$body=json_encode($claim,JSON_THROW_ON_ERROR);
$sig='sha256='.hash_hmac('sha256',(string)$now.'.'.$body,hex2bin($secret));
check(SignedAssertion::verify($body,(string)$now,$sig,$secret,$now)===$claim,'Valid provider envelope');
check(VerificationSchema::VERSION==='m8_verification_receipts_v1','Versioned verification schema');
reject(fn()=>SignedAssertion::verify($body.' ',(string)$now,$sig,$secret,$now),'Mutated body');
reject(fn()=>SignedAssertion::verify($body,(string)($now-301),$sig,$secret,$now),'Expired timestamp');
reject(fn()=>SignedAssertion::verify($body,(string)$now,$sig,bin2hex(random_bytes(32)),$now),'Wrong secret');
reject(fn()=>SignedAssertion::verify($body,(string)$now,'sha256='.str_repeat('a',64),$secret,$now),'Invalid mac');
reject(fn()=>SignedAssertion::verify($body,(string)$now,$sig,bin2hex(random_bytes(8)),$now),'Short secret');
reject(fn()=>SignedAssertion::verify($body,(string)$now,$sig,$secret,$now+301),'Clock drift replay');
foreach ([
    ['event_id'=>'x'], ['claim_type'=>'admin'], ['subject_id'=>0],
    ['decision'=>'approved'], ['reference'=>'bad ref!'], ['occurred_at'=>$now-90000],
    ['occurred_at'=>$now+301], ['excess'=>true],
] as $override) {
    $bad=array_replace($claim,$override);
    $json=json_encode($bad,JSON_THROW_ON_ERROR);
    $signed='sha256='.hash_hmac('sha256',(string)$now.'.'.$json,hex2bin($secret));
    reject(fn()=>SignedAssertion::verify($json,(string)$now,$signed,$secret,$now),'Malformed signed claims rejected');
}
check(!file_exists(dirname(__DIR__).'/config/verification.php'),'Provider endpoint disabled by default');
echo "Passed $checks signed-verification checks.\n";
