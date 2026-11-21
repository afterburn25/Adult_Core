<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Membership\MemberSubscriptionSummary;

$checks=0;
$ck=static function(bool $ok,string $message)use(&$checks):void{
    if (!$ok) throw new RuntimeException('Subscription overview: '.$message);
    $checks++;
};
$empty=MemberSubscriptionSummary::render([]);
$ck(str_contains($empty,'No membership records are linked to this account.')
    && str_contains($empty,'not proof of a completed payment'),
    'Empty member catalog is neutral and does not imply checkout');
$rows=[[
    'name'=>'Tier <img src=x onerror=alert(1)>',
    'status'=>'active<script>alert(1)</script>',
    'starts_at'=>'2026-01-01 00:00:00',
    'expires_at'=>'2027-01-01 00:00:00',
    'is_active'=>0,
]];
$html=MemberSubscriptionSummary::render($rows);
$ck(str_contains($html,'Tier &lt;img src=x onerror=alert(1)&gt;')
    && !str_contains($html,'<img src=x onerror=alert(1)>'),
    'Member-visible tier label is escaped');
$ck(str_contains($html,'active&lt;script&gt;alert(1)&lt;/script&gt;')
    && !str_contains($html,'<script>alert(1)</script>'),
    'Untrusted membership record status is escaped');
$ck(str_contains($html,'Disabled for access')
    && !str_contains($html,'Verified payment'),
    'Disabled tier never appears as an approved charge');
$ck(str_contains($html,'No purchases or cancellations can be made here.')
    && !str_contains($html,'license_key'),
    'Page does not expose payment actions or licensing secrets');
echo "Passed {$checks} subscription overview safety checks.\n";
