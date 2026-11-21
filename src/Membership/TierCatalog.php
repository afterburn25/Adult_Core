<?php

declare(strict_types=1);

namespace AdultCore\Membership;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Administratively editable tier labels only. Never charges, grants
 * memberships, verifies age or changes a tier's entitlement-active bit.
 */
final class TierCatalog
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validateName(string $name): string
    {
        // Check raw bytes BEFORE trim: otherwise trailing NUL or newline
        // characters are silently removed and accepted.
        if (strlen($name)>120
            || preg_match('/[\x00-\x1f\x7f]/', $name)===1
            || preg_match('//u',$name)!==1) {
            throw new RuntimeException('Enter a valid tier name of 2–120 bytes without control characters.');
        }
        $name=trim($name);
        if (strlen($name)<2 || strlen($name)>120) {
            throw new RuntimeException('Enter a valid tier name of 2–120 bytes without control characters.');
        }
        return $name;
    }

    /** @return list<array{id:int,name:string,is_active:int}> */
    public function all(): array
    {
        $q=$this->pdo->query('SELECT id,name,is_active FROM ac_membership_tiers ORDER BY id DESC LIMIT 50');
        $rows=[];
        while (($item=$q->fetch(PDO::FETCH_ASSOC))!==false) {
            $rows[]=[
                'id'=>(int)$item['id'],
                'name'=>(string)$item['name'],
                'is_active'=>(int)$item['is_active'],
            ];
        }
        return $rows;
    }

    /** Creating a label does not activate any paid-access entitlement. */
    public function create(string $name, int $actorId): int
    {
        $name=self::validateName($name);
        $this->requireManager($actorId);
        $this->pdo->beginTransaction();
        try {
            $this->lockCatalog();
            if ((int)$this->pdo->query('SELECT COUNT(*) FROM ac_membership_tiers')->fetchColumn()>=50) {
                throw new RuntimeException('Tier catalog limit reached.');
            }
            $this->assertUniqueName($name,null);
            // Disable the entitlement bit until a vetted merchant/fulfillment
            // lifecycle exists. Labels can still be staged on private drafts.
            $q=$this->pdo->prepare('INSERT INTO ac_membership_tiers (name,is_active) VALUES (?,0)');
            $q->execute([$name]);
            $id=(int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Rename in place; do not cancel, grant or revoke any paid entitlements. */
    public function rename(int $id, int $actorId, string $expectedName, string $name): void
    {
        if ($id<1) throw new RuntimeException('Invalid tier ID.');
        $expectedName=self::validateName($expectedName);
        $name=self::validateName($name);
        $this->requireManager($actorId);
        $this->pdo->beginTransaction();
        try {
            $this->lockCatalog();
            $q=$this->pdo->prepare('SELECT name FROM ac_membership_tiers WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $old=$q->fetchColumn();
            if (!is_string($old)) throw new RuntimeException('Tier not found.');
            if (!hash_equals($old,$expectedName)) {
                throw new RuntimeException('Tier label changed; reload the catalog before editing.');
            }
            if (hash_equals($old,$name)) throw new RuntimeException('Tier name is unchanged.');
            $this->assertUniqueName($name,$id);
            $this->pdo->prepare('UPDATE ac_membership_tiers SET name=? WHERE id=?')->execute([$name,$id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * A site-local row lock serializes all catalog writers. Without it,
     * concurrent create/rename requests can both pass the 50-tier cap and
     * case-insensitive uniqueness check before either commits. The installation
     * record exists for every licensed Lite/Pro site; it is never modified here.
     */
    private function lockCatalog(): void
    {
        $q=$this->pdo->query('SELECT id FROM ac_installations WHERE id=1 FOR UPDATE');
        if ($q->fetchColumn()===false) {
            throw new RuntimeException('Local tier catalog installation is missing.');
        }
    }

    private function assertUniqueName(string $name, ?int $excludeId): void
    {
        // A label is never an authorization key; IDs remain stable. Reject
        // obvious duplicate names, including case variants via DB collation.
        $sql='SELECT id FROM ac_membership_tiers WHERE name=?';
        $params=[$name];
        if ($excludeId!==null) { $sql.=' AND id<>?'; $params[]=$excludeId; }
        $q=$this->pdo->prepare($sql.' LIMIT 1');
        $q->execute($params);
        if ($q->fetchColumn()!==false) throw new RuntimeException('A tier with this name already exists.');
    }

    private function requireManager(int $actorId): void
    {
        if ($actorId<1) throw new RuntimeException('Tier management permission required.');
        $q=$this->pdo->prepare("SELECT role FROM ac_users WHERE id=? AND status='active' LIMIT 1");
        $q->execute([$actorId]);
        if (!in_array($q->fetchColumn(),['owner','admin'],true)) {
            throw new RuntimeException('Tier management permission required.');
        }
    }
}
