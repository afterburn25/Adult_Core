<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Support\SiteHealth;
use AdultCore\UI\Layout;
use Throwable;

/** Read-only site operator check, NOT a remote certification service. */
final class HealthController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method,array $admin): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (!RolePolicy::allows($admin['role'],'site.settings.manage')) {
            $this->render(403,'Access denied','<p>Site diagnostics require owner/admin permission.</p>');
            return;
        }
        if ($method!=='GET') {
            header('Allow: GET');
            $this->render(405,'Method not allowed','<p>Diagnostics are read-only.</p>');
            return;
        }
        try {
            $pdo=Connection::open($this->root);
            $checks=(new SiteHealth($this->root,$pdo))->checks(
                is_string($_SERVER['DOCUMENT_ROOT']??null)
                    ? (string)$_SERVER['DOCUMENT_ROOT'] : null);
            $passed=SiteHealth::allLocalChecksPass($checks);
            $html='<p class="intro">Local checks for this independently hosted Lite/Pro installation. '
                .'Nothing is changed or sent to a vendor. This screen never displays private paths, '
                .'license keys, passwords, performer evidence or member data.</p>'
                .'<p role="status"><strong>Local checks: '
                .($passed?'Passed':'Needs attention').'</strong>. '
                .'Production release is still blocked pending real host acceptance, approved adult-compatible '
                .'merchant billing, independent age/performer consent verification and security/compliance review.</p>'
                .'<section class="panel"><h2>Current local checks</h2>'
                .'<table class="page-table"><thead><tr><th>Check</th><th>Result</th><th>Requirement</th></tr></thead><tbody>';
            foreach ($checks as $item) {
                $html.='<tr><td>'.self::e($item['name']).'</td><td>'
                    .($item['pass']?'Pass':'Needs attention').'</td><td>'
                    .self::e($item['detail']).'</td></tr>';
            }
            $html.='</tbody></table></section><p>'
                .'<a href="/admin/setup">Review local migrations</a> · '
                .'<a href="/admin/license">Review license state</a></p>'
                .'<p class="muted">This check does not test actual cPanel/Apache HTTPS deployment, '
                .'VPS Pro agents or streaming, external providers, disaster recovery or an independent audit. '
                .'It cannot unlock protected media or entitlements.</p>';
            $this->render(200,'Site health',$html);
        } catch (Throwable) {
            $this->render(503,'Site health unavailable',
                '<p>Unable to complete local diagnostics. Review the database and private installation.</p>');
        }
    }

    private function render(int $status,string $title,string $body): void
    {
        http_response_code($status);
        $config=(new LicenseManager($this->root))->config();
        $edition=is_array($config)?(string)($config['edition']??'unknown'):'unknown';
        echo Layout::admin($title,
            '<div class="admin-heading"><div><p class="eyebrow">OPERATIONS</p><h1>'
            .self::e($title).'</h1></div><span class="page-heading-tag">Local read-only diagnostics</span></div>'.$body,
            '/admin/health',AdminSession::csrf(),$edition);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
