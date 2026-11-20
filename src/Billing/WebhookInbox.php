<?php

declare(strict_types=1);

namespace AdultCore\Billing;

use PDO;
use RuntimeException;

/** Provider-neutral incoming event storage. Never grants entitlements on an unverified callback. */
final class WebhookInbox
{
    public function __construct(private readonly PDO $pdo) {}

    public static function verifyHmac(string $body, string $header, string $secret): bool
    {
        if (strlen($body)>262144 || strlen($secret)<32 || !preg_match('/^[a-f0-9]{64}$/D', $header)) return false;
        return hash_equals(hash_hmac('sha256', $body, $secret), $header);
    }

    /** Caller must verify the provider's official signature, timestamp and event semantics first. */
    public function recordVerified(string $provider, string $eventId, string $eventType, string $payloadHash): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/D', $provider)
            || strlen($eventId)>190 || $eventId==='' || strlen($eventType)>120
            || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) {
            throw new RuntimeException('Invalid verified event metadata.');
        }
        $s=$this->pdo->prepare('INSERT IGNORE INTO ac_billing_events (provider, event_id, event_type, payload_sha256, received_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
        $s->execute([$provider,$eventId,$eventType,$payloadHash]);
        return $s->rowCount()===1;
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ac_billing_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(40) NOT NULL, event_id VARCHAR(190) NOT NULL, event_type VARCHAR(120) NOT NULL, payload_sha256 CHAR(64) NOT NULL, received_at DATETIME NOT NULL, UNIQUE KEY provider_event (provider, event_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}
