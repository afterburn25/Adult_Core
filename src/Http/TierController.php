<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Membership\TierCatalog;
use AdultCore\UI\Layout;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Membership tier labels and listing. Provider-approved checkout and
 * entitlement lifecycle must be implemented independently before sales.
 */
final class TierController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method, array $admin): void
    {
        if (!RolePolicy::allows($admin['role'],'site.settings.manage')) {
            $this->render(403,'Access denied','<p>Tier management requires site settings permission.</p>');
            return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST');
            $this->render(405,'Method not allowed','<p>Unsupported request.</p>');
            return;
        }
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the tier catalog before making changes.</p>');
            return;
        }
        try {
            $pdo=Connection::open($this->root);
            $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $q->execute([MembershipSchema::VERSION]);
            if ((int)$q->fetchColumn()!==1) {
                $this->render(503,'Setup required',
                    '<p>Apply the additive membership schema under <a href="/admin/setup">Site setup</a> first.</p>');
                return;
            }
            $catalog=new TierCatalog($pdo);
            if ($method==='POST') {
                $action=$_POST['action']??null;
                if ($action==='create') {
                    $catalog->create(self::string($_POST['name']??null),$admin['id']);
                } elseif ($action==='rename') {
                    $id=filter_var($_POST['tier_id']??null,FILTER_VALIDATE_INT,
                        ['options'=>['min_range'=>1]]);
                    if ($id===false || $id===null) throw new RuntimeException('Invalid tier ID.');
                    $catalog->rename((int)$id,$admin['id'],
                        self::string($_POST['expected_name']??null),self::string($_POST['name']??null));
                } else {
                    throw new RuntimeException('Unsupported tier action.');
                }
                header('Location: /admin/tiers?result=saved',true,303);
                return;
            }
            $this->render(200,'Membership tiers',$this->listHtml($catalog));
        } catch (RuntimeException $e) {
            $allow=[
                'Enter a valid tier name of 2–120 bytes without control characters.',
                'Tier catalog limit reached.','A tier with this name already exists.',
                'Tier name is unchanged.','Tier label changed; reload the catalog before editing.',
                'Tier not found.','Invalid tier ID.','Unsupported tier action.',
                'Tier management permission required.','Invalid tier fields.',
            ];
            $message=in_array($e->getMessage(),$allow,true)
                ? $e->getMessage():'Could not save membership tier.';
            $this->render(422,'Tier change refused','<p role="alert">'.self::e($message).'</p>');
        } catch (Throwable) {
            $this->render(503,'Tier catalog unavailable','<p>Membership tier data is temporarily unavailable.</p>');
        }
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) throw new RuntimeException('Invalid tier fields.');
        return $value;
    }

    private function listHtml(TierCatalog $catalog): string
    {
        $csrf=self::e(AdminSession::csrf());
        $html='<p class="intro">Define labels for membership tiers without creating subscriptions or processing payments. '
            .'A tier label is not proof of payment or permission to view adult media. '
            .'Existing subscription states, prices and entitlements are not changed here.</p>';
        if (($_GET['result']??'')==='saved') {
            $html.='<p role="status">Tier label saved. No membership was granted.</p>';
        }
        $html.='<section class="panel"><h2>Create tier label</h2>'
            .'<form method="post" action="/admin/tiers" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'">'
            .'<input type="hidden" name="action" value="create">'
            .'<label for="new-tier-name">Tier name</label>'
            .'<input id="new-tier-name" name="name" minlength="2" maxlength="120" required>'
            .'<button class="install-button" type="submit">Create tier label</button></form></section>'
            .'<section class="panel"><h2>Configured tiers</h2><table class="page-table">'
            .'<thead><tr><th>ID</th><th>Label</th><th>Entitlement status</th><th>Rename label</th></tr></thead><tbody>';
        foreach ($catalog->all() as $tier) {
            $id=(int)$tier['id'];
            $name=self::e($tier['name']);
            $html.='<tr><td>'.$id.'</td><td>'.$name.'</td>'
                .'<td>'.($tier['is_active']===1?'Enabled (existing data only)':'Disabled').'</td><td>'
                .'<form method="post" action="/admin/tiers" class="install-form">'
                .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                .'<input type="hidden" name="action" value="rename">'
                .'<input type="hidden" name="tier_id" value="'.$id.'">'
                .'<input type="hidden" name="expected_name" value="'.$name.'">'
                .'<label>New name<input name="name" required minlength="2" maxlength="120" value="'.$name.'"></label>'
                .'<button class="button-secondary" type="submit">Save name</button></form></td></tr>';
        }
        $html.='</tbody></table></section>'
            .'<p class="muted">For safety, tiers cannot be deleted, deactivated, priced or assigned to members on this page. '
            .'Those operations need a contracted adult-content payment processor, lifecycle safeguards and independent deployment acceptance.</p>';
        return $html;
    }

    private function render(int $code,string $title,string $body): void
    {
        http_response_code($code);
        $edition=(new LicenseManager($this->root))->config()['edition']??'unknown';
        echo Layout::admin($title,
            '<div class="admin-heading"><div><p class="eyebrow">MEMBERSHIP CATALOG</p><h1>'
            .self::e($title).'</h1></div><span class="page-heading-tag">Labels only</span></div>'.$body,
            '/admin/tiers',AdminSession::csrf(),(string)$edition);
    }

    private static function e(string $v):string
    {
        return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
