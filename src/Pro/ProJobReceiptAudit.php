<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use RuntimeException;

/**
 * Read-only audit of local signed Pro job RECEIPTS. No job execution, status
 * mutation, queue drain or certification of any external worker.
 */
final class ProJobReceiptAudit
{
    public const MAX_RECEIPTS = 2000;
    public const MAX_RECORD_BYTES = 20000;

    /** @return array<string,mixed> */
    public static function inspect(string $siteRoot, string $hmacSecret): array
    {
        if (strlen($hmacSecret) < 32 || strlen($hmacSecret) > 4096) {
            throw new RuntimeException('Pro receipt audit needs a separately protected signing secret.');
        }
        $storage = $siteRoot . '/storage';
        if (is_link($storage) || !is_dir($storage) || !is_readable($storage)) {
            throw new RuntimeException('Private Pro storage is missing or unsafe.');
        }
        $dir = $storage . '/pro-jobs';
        if (is_link($dir)) {
            throw new RuntimeException('Pro inbox cannot be a symbolic-link directory.');
        }
        if (!file_exists($dir)) {
            return self::report(false, ['pending'=>0,'processing'=>0,'done'=>0,'failed'=>0]);
        }
        if (!is_dir($dir) || !is_readable($dir)) {
            throw new RuntimeException('Pro inbox is not a readable regular directory.');
        }
        $permissions = fileperms($dir);
        if (!is_int($permissions) || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Pro inbox directory must be private.');
        }
        $entries = scandir($dir);
        if (!is_array($entries)) throw new RuntimeException('Cannot inventory private Pro job receipts.');
        $counts = ['pending'=>0,'processing'=>0,'done'=>0,'failed'=>0];
        $seen = 0;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            if (++$seen > self::MAX_RECEIPTS) {
                throw new RuntimeException('Pro inbox exceeds bounded offline audit size.');
            }
            if (preg_match('/^[a-f0-9]{64}\.json$/D', $name) !== 1) {
                throw new RuntimeException('Pro inbox includes an unexpected entry.');
            }
            $path = $dir . '/' . $name;
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException('Pro inbox includes an unsafe receipt file.');
            }
            $mode = fileperms($path);
            $size = filesize($path);
            if (!is_int($mode) || ($mode & 0077) !== 0
                || !is_int($size) || $size < 2 || $size > self::MAX_RECORD_BYTES) {
                throw new RuntimeException('Pro receipt mode or byte size is unsafe.');
            }
            $raw = file_get_contents($path);
            if (!is_string($raw) || strlen($raw) !== $size) {
                throw new RuntimeException('Pro receipt cannot be read reliably.');
            }
            try {
                $record = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('Pro receipt contains invalid JSON.');
            }
            if (!is_array($record) || array_keys($record) !==
                ['received_at','job','signature','status','mac']
                || !is_int($record['received_at']) || $record['received_at'] <= 0
                || !is_array($record['job'])
                || array_keys($record['job']) !==
                   ['version','id','type','payload','created_at']
                || ($record['job']['version'] ?? null) !== 1
                || !is_string($record['job']['id'])
                || preg_match('/^[a-zA-Z0-9-]{8,100}$/D', $record['job']['id']) !== 1
                || !is_int($record['job']['created_at'])
                || $record['job']['created_at'] <= 0
                || !JobInbox::validPayload($record['job'])
                || !is_string($record['signature'])
                || preg_match('/^[a-f0-9]{64}$/D', $record['signature']) !== 1
                || !isset($counts[$record['status'] ?? ''])
                || !is_string($record['mac'])
                || preg_match('/^[a-f0-9]{64}$/D', $record['mac']) !== 1
                || !hash_equals(hash('sha256', $record['job']['id']).'.json', $name)) {
                throw new RuntimeException('Pro receipt has invalid schema or filename identity.');
            }

            $expectedEnvelope = hash_hmac('sha256',
                json_encode($record['job'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $hmacSecret
            );
            $expectedReceipt = hash_hmac('sha256',
                "adult-core-pro-receipt-v1\n" .
                json_encode(array_slice($record, 0, 4, true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $hmacSecret
            );
            if (!hash_equals($expectedEnvelope, $record['signature'])
                || !hash_equals($expectedReceipt, $record['mac'])) {
                throw new RuntimeException('Pro receipt is not signed by the trusted site secret.');
            }
            ++$counts[$record['status']];
        }
        return self::report(true, $counts);
    }

    /** @param array{pending:int,processing:int,done:int,failed:int} $counts
     *  @return array<string,mixed>
     */
    private static function report(bool $inboxPresent, array $counts): array
    {
        return [
            'schema' => 'adult-core-pro-receipt-audit-v1',
            'read_only' => true,
            'inbox_present' => $inboxPresent,
            'signed_receipts_verified' => array_sum($counts),
            'pending_receipts' => $counts['pending'],
            'processing_receipts' => $counts['processing'],
            'done_receipts' => $counts['done'],
            'failed_receipts' => $counts['failed'],
            'no_locally_open_receipts' => $counts['pending'] === 0 && $counts['processing'] === 0,
            'external_workers_quiesced' => false,
            'database_writes_quiesced' => false,
            'ready_for_activation' => false,
        ];
    }
}
