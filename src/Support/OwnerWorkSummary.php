<?php

declare(strict_types=1);

namespace AdultCore\Support;

use AdultCore\Creator\CreatorSchema;
use AdultCore\Member\MemberSchema;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Website\SiteSchema;
use PDO;

/**
 * Owner-only local operational counters. No revenue, identity verification,
 * age/consent approval, publication rights or membership entitlements.
 * A null count means the underlying additive schema is NOT installed.
 */
final class OwnerWorkSummary
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{pending_members:?int,draft_pages:?int,open_reports:?int,submitted_plans:?int} */
    public function read(): array
    {
        $versions=$this->pdo->query('SELECT version FROM ac_schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN);
        $ready=array_fill_keys(array_filter($versions,'is_string'),true);
        return [
            'pending_members'=>isset($ready[MemberSchema::VERSION])
                ? $this->count("SELECT COUNT(*) FROM ac_users WHERE role='member' AND status='pending'") : null,
            'draft_pages'=>isset($ready[SiteSchema::VERSION])
                ? $this->count("SELECT COUNT(*) FROM ac_pages WHERE status='draft'") : null,
            'open_reports'=>isset($ready[MembershipSchema::VERSION])
                ? $this->count("SELECT COUNT(*) FROM ac_content_reports WHERE status='open'") : null,
            'submitted_plans'=>isset($ready[CreatorSchema::VERSION])
                ? $this->count("SELECT COUNT(*) FROM ac_creator_drafts WHERE status='submitted'") : null,
        ];
    }

    private function count(string $statement): int
    {
        // SQL strings are source-defined, never request parameters.
        return (int)$this->pdo->query($statement)->fetchColumn();
    }
}
