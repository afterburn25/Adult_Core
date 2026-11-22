<?php

declare(strict_types=1);

namespace AdultCore\Support;

/**
 * The ten launch workstreams. Development evidence never certifies production.
 * Update this matrix only alongside real implementation + an acceptance record.
 */
final class ReleaseMilestones
{
    /**
     * @return list<array{id:int,name:string,status:string,implemented:string,remaining:string,needs_external:bool}>
     */
    public static function all(): array
    {
        return [
            [
                'id'=>1, 'name'=>'Cajun Web Pro software licensing',
                'status'=>'external_acceptance_pending',
                'implemented'=>'Unified Lite/Pro key-selected installer, four SKU contracts, independent add-on licensing, signed caches and recovery.',
                'remaining'=>'Owner provisioned product catalog, prices, real staged keys, subscriptions, cancellation and payment-sandbox lifecycle.',
                'needs_external'=>true,
            ],
            [
                'id'=>2, 'name'=>'Lite shared hosting and Pro VPS',
                'status'=>'external_acceptance_pending',
                'implemented'=>'Host capability inspection, installer HTTPS/routing checks, fail-closed Pro-agent preflight, staging-host audit CLI.',
                'remaining'=>'Real Apache/cPanel Lite installation and real configured Pro VPS/agent acceptance with TLS and deployment evidence.',
                'needs_external'=>true,
            ],
            [
                'id'=>3, 'name'=>'Age verification and performer consent',
                'status'=>'incomplete',
                'implemented'=>'Signed verification event format, replay-resistant receipt storage, default-deny performer roster and consent predicates.',
                'remaining'=>'Contracted real provider, verified age/identity flows, jurisdiction policies, independent consent/record retention audit.',
                'needs_external'=>true,
            ],
            [
                'id'=>4, 'name'=>'Member payments and subscription access',
                'status'=>'incomplete',
                'implemented'=>'Provider-neutral verified-event inbox with conflicting-replay rejection, payment-required entitlement predicates.',
                'remaining'=>'Approved adult-friendly processor, signed provider adapter, checkout, refunds/chargebacks, settlement and billing lifecycle.',
                'needs_external'=>true,
            ],
            [
                'id'=>5, 'name'=>'Private creator content and moderation',
                'status'=>'incomplete',
                'implemented'=>'Private media authorization, pending-only administrator draft image uploads, draft/quarantine/report/review foundations and entitled member gallery details.',
                'remaining'=>'Controlled creator upload/approval, verified-performer media lifecycle, video/gallery management and tested takedowns.',
                'needs_external'=>true,
            ],
            [
                'id'=>6, 'name'=>'Production Pro video and streaming',
                'status'=>'incomplete',
                'implemented'=>'Signed job envelope, narrow payload allowlist, replay-conflict protected private job receipt, maintenance/recovery-aware receipt admission, bounded read-only signed receipt integrity and outstanding-work audit.',
                'remaining'=>'Hardened FFmpeg service, durable worker queue, authorized segments, streaming, monitoring and real VPS load testing.',
                'needs_external'=>true,
            ],
            [
                'id'=>7, 'name'=>'Executable add-on lifecycle and updates',
                'status'=>'incomplete',
                'implemented'=>'Signed data-only packages, edition/host compatibility checks and separate paid-module license recovery.',
                'remaining'=>'Safe extension execution API, migrations, dependency upgrades, rollback and isolated extension security review.',
                'needs_external'=>false,
            ],
            [
                'id'=>8, 'name'=>'Design and member experience',
                'status'=>'incomplete',
                'implemented'=>'Responsive admin, visual design editor, built-in theme presets, safe pages, member accounts and private member library.',
                'remaining'=>'Creator dashboard, member tiers UX, complete page builder, mobile/accessibility testing and production polish.',
                'needs_external'=>false,
            ],
            [
                'id'=>9, 'name'=>'Maintenance, backups and deployment',
                'status'=>'external_acceptance_pending',
                'implemented'=>'Encrypted backups and restore fixtures, pending-operation locks, read-only private-media integrity audit, CLI preflight, offline detached-signed source ZIP verification, read-only source update preview, private inert signed source staging, staged-source re-verification, offline signed runtime transition preflight, separate signed previous-source recovery material verification, immutable runtime-only signed staging, independent private runtime integrity verification, exclusive offline operator coordination lease, token-bound application HTTP maintenance gate and CI recovery tests.',
                'remaining'=>'Real host disaster recovery drills, managed updates, off-site retention, operational monitoring and rollback certification.',
                'needs_external'=>true,
            ],
            [
                'id'=>10, 'name'=>'Independent security and production release',
                'status'=>'blocked',
                'implemented'=>'Default-deny release gate, security regressions, disposable MySQL and HTTP workflow.',
                'remaining'=>'Independent security/privacy/compliance/accessibility audit, incident runbooks and all prior launch gates signed off.',
                'needs_external'=>true,
            ],
        ];
    }

    /** @return array{schema:string,production_ready:bool,open_count:int,workstreams:list<array<string,mixed>>} */
    public static function report(): array
    {
        $items = self::all();
        return [
            'schema'=>'adult-core-release-workstreams-v1',
            'production_ready'=>false,
            'open_count'=>count($items),
            'workstreams'=>$items,
        ];
    }
}
