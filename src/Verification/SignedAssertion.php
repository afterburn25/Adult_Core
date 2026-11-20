<?php

declare(strict_types=1);

namespace AdultCore\Verification;

use RuntimeException;

/**
 * A narrow, provider-independent signed assertion contract. This does NOT prove
 * any identity by itself; only connect a contracted provider whose adapter has
 * been reviewed for its verification and consent standards.
 */
final class SignedAssertion
{
    /** @return array{event_id:string,claim_type:string,subject_id:int,decision:string,reference:string,occurred_at:int} */
    public static function verify(string $body, string $unixTimestamp, string $signature, string $hexSecret, ?int $now = null): array
    {
        $now ??= time();
        if (strlen($body) < 2 || strlen($body) > 8192 || !preg_match('/^[1-9][0-9]{9}$/D', $unixTimestamp)
            || abs($now - (int)$unixTimestamp) > 300 || !preg_match('/^sha256=[a-f0-9]{64}$/D', $signature)
            || !preg_match('/^[a-f0-9]{64,128}$/D', $hexSecret)) {
            throw new RuntimeException('Invalid verification assertion.');
        }
        $key = hex2bin($hexSecret);
        $mac = hash_hmac('sha256', $unixTimestamp . '.' . $body, $key);
        if (!hash_equals('sha256=' . $mac, $signature)) {
            throw new RuntimeException('Invalid verification assertion.');
        }
        try { $claim = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Invalid verification assertion.'); }
        if (!is_array($claim) || array_is_list($claim) || count($claim) !== 6
            || array_diff(array_keys($claim), ['event_id','claim_type','subject_id','decision','reference','occurred_at']) !== []
            || !is_string($claim['event_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{8,120}$/D', $claim['event_id']) !== 1
            || !in_array($claim['claim_type'] ?? null, ['member_age','performer_age','performer_consent'], true)
            || !is_int($claim['subject_id'] ?? null) || $claim['subject_id'] < 1
            || !in_array($claim['decision'] ?? null, ['verified','revoked'], true)
            || !is_string($claim['reference'] ?? null) || preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D', $claim['reference']) !== 1
            || !is_int($claim['occurred_at'] ?? null) || $claim['occurred_at'] > $now + 300
            || $claim['occurred_at'] < $now - 86400) {
            throw new RuntimeException('Invalid verification assertion.');
        }
        return $claim;
    }
}
