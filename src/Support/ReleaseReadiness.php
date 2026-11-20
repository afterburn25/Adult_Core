<?php

declare(strict_types=1);

namespace AdultCore\Support;

/** Release sign-off checklist. Until signed-off externally, no 1.0 production claim. */
final class ReleaseReadiness
{
    /** @return list<string> */
    public static function blockers(): array
    {
        return [
            'Approved adult-content merchant processor with verified real webhook integration',
            'Age and identity verification provider and documented jurisdiction controls',
            'Performer consent and recordkeeping workflow audited for completeness',
            'Moderation, reporting, takedown and appeals workflows',
            'Production Pro worker and video playback authorization load tests',
            'Administrative MFA, password recovery and incident audit logging',
            'Signed updates with tested rollback and encrypted backup/restore',
            'Shared hosting/cPanel and provisioned VPS compatibility certification',
            'Independent security assessment and vulnerability remediation',
        ];
    }

    public static function productionReady(): bool
    {
        return self::blockers() === [];
    }
}
