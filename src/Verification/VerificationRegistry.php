<?php

declare(strict_types=1);

namespace AdultCore\Verification;

use PDO;
use RuntimeException;
use Throwable;

/** Stores only hashed third-party references; no ID photographs or sensitive documents. */
final class VerificationRegistry
{
    public function __construct(private readonly PDO $pdo) {}

    /** Returns false for duplicate provider event IDs; never grants a publishing decision. */
    public function apply(string $provider, array $claim): bool
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,39}$/D', $provider) !== 1) {
            throw new RuntimeException('Provider ID not valid.');
        }
        // Even callers outside the HTTP endpoint must supply syntactically validated claims.
        if (!in_array($claim['claim_type'] ?? null, ['member_age','performer_age','performer_consent'], true)
            || !in_array($claim['decision'] ?? null, ['verified','revoked'], true)
            || !is_int($claim['subject_id'] ?? null) || $claim['subject_id'] < 1
            || !is_string($claim['event_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{8,120}$/D', $claim['event_id'])
            || !is_string($claim['reference'] ?? null) || !preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D', $claim['reference'])
            || !is_int($claim['occurred_at'] ?? null) || $claim['occurred_at'] < 1000000000
            || $claim['occurred_at'] > time() + 300 || $claim['occurred_at'] < time() - 86400) {
            throw new RuntimeException('Verification claim is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            // Lock the subject before checking the latest event, to serialize concurrent
            // retries and revoke/verify ordering for the same identity.
            if ($claim['claim_type'] === 'member_age') {
                $lock=$this->pdo->prepare("SELECT id FROM ac_users WHERE id=? AND role='member' FOR UPDATE");
                $lock->execute([$claim['subject_id']]);
                if (!$lock->fetchColumn()) throw new RuntimeException('Unknown member.');
            } else {
                $lock=$this->pdo->prepare('SELECT id,content_id FROM ac_performer_records WHERE id=? FOR UPDATE');
                $lock->execute([$claim['subject_id']]);
                $performer=$lock->fetch(PDO::FETCH_ASSOC);
                if (!is_array($performer)) throw new RuntimeException('Unknown performer record.');
            }
            $dupe=$this->pdo->prepare('SELECT 1 FROM ac_verification_receipts WHERE provider=? AND event_id=? LIMIT 1');
            $dupe->execute([$provider,$claim['event_id']]);
            if ($dupe->fetchColumn()) { $this->pdo->commit(); return false; }
            $latest=$this->pdo->prepare('SELECT occurred_at FROM ac_verification_receipts WHERE provider=? AND claim_type=? AND subject_id=? ORDER BY occurred_at DESC,id DESC LIMIT 1');
            $latest->execute([$provider,$claim['claim_type'],$claim['subject_id']]);
            $previous=$latest->fetchColumn();
            $eventTime=gmdate('Y-m-d H:i:s',$claim['occurred_at']);
            // Reject old/out-of-order decisions, including those arriving with a fresh HTTP signature.
            if (is_string($previous) && $previous >= $eventTime) {
                throw new RuntimeException('Stale provider decision.');
            }
            $q = $this->pdo->prepare('INSERT IGNORE INTO ac_verification_receipts (provider,event_id,claim_type,subject_id,decision,reference_sha256,occurred_at,received_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $q->execute([$provider, $claim['event_id'], $claim['claim_type'], $claim['subject_id'], $claim['decision'], hash('sha256',$claim['reference']),$eventTime]);
            if ($q->rowCount() !== 1) { $this->pdo->commit(); return false; }
            $verified = $claim['decision'] === 'verified';
            if ($claim['claim_type'] === 'member_age') {
                if ($verified) {
                    $q = $this->pdo->prepare('INSERT INTO ac_age_verifications (user_id,provider,verification_ref_hash,verified_at,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 365 DAY)) ON DUPLICATE KEY UPDATE provider=VALUES(provider),verification_ref_hash=VALUES(verification_ref_hash),verified_at=VALUES(verified_at),expires_at=VALUES(expires_at)');
                    $q->execute([$claim['subject_id'],$provider,hash('sha256',$claim['reference'])]);
                } else {
                    $this->pdo->prepare('DELETE FROM ac_age_verifications WHERE user_id=?')->execute([$claim['subject_id']]);
                }
                // Member remains pending until separately reviewed and email confirmed.
            } else {
                $field = $claim['claim_type'] === 'performer_age' ? 'verified_at' : 'consent_at';
                // Field name is from strict enum, not request interpolation.
                $this->pdo->prepare('UPDATE ac_performer_records SET ' . $field . '=?,review_status=? WHERE id=?')
                    ->execute([$verified ? gmdate('Y-m-d H:i:s') : null, 'pending', $claim['subject_id']]);
                // Any new evidence or revocation invalidates previous moderation approval.
                $this->pdo->prepare("UPDATE ac_content SET status='quarantined',review_status='pending' WHERE id=? AND status='published'")
                    ->execute([(int)$performer['content_id']]);
                $this->pdo->prepare("UPDATE ac_media SET review_status='pending' WHERE content_id=?")
                    ->execute([(int)$performer['content_id']]);
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
