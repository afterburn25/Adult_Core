<?php

declare(strict_types=1);

namespace AdultCore\Support;

use AdultCore\Capability\PublicRootSafety;
use AdultCore\Installer\InstallationGate;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Auth\AdminMfaSchema;
use AdultCore\Auth\StaffSchema;
use AdultCore\Member\PasswordRecoverySchema;
use AdultCore\Design\CutoutPlacement;
use AdultCore\Website\SiteSchema;
use AdultCore\Website\PageRevisionSchema;
use AdultCore\Website\PageNavigationSchema;
use AdultCore\Website\PageHistorySchema;
use AdultCore\Website\SiteIdentitySchema;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Moderation\ModerationSchema;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Creator\CreatorFeedbackSchema;
use AdultCore\Design\DesignSchema;
use AdultCore\Design\HighlightSchema;
use AdultCore\Verification\VerificationSchema;
use PDO;

/**
 * Purely local read-only checks for an independently installed site.
 * Never contacts a vendor or discloses secrets, directory paths, license
 * keys, identities, verification data, payment records or media bytes.
 */
final class SiteHealth
{
    public function __construct(private readonly string $root,private readonly PDO $pdo) {}

    /** @return list<array{name:string,pass:bool,detail:string}> */
    public function checks(?string $documentRoot): array
    {
        $result=[];
        $add=static function(string $name,bool $pass,string $detail)use(&$result):void {
            $result[]=['name'=>$name,'pass'=>$pass,'detail'=>$detail];
        };
        $add('Signed installation and cleanup',
            (new InstallationGate($this->root))->status()===InstallationGate::READY,
            'Installer removed and signed installed state valid.');
        $add('PHP runtime',PHP_VERSION_ID>=80300,'PHP 8.3+ required.');
        $add('Required PHP extensions',
            extension_loaded('pdo_mysql') && extension_loaded('openssl')
            && extension_loaded('json') && extension_loaded('fileinfo')
            && extension_loaded('session'),
            'PDO MySQL, OpenSSL, JSON, Fileinfo and session extensions are required.');
        $private=true;
        foreach (['storage','config'] as $name) {
            $dir=$this->root.'/'.$name;
            if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) $private=false;
        }
        $add('Private folders',$private,
            'Private config and storage must be real writable directories, not symlinks.');
        $expected=realpath($this->root.'/public');
        $actual=$documentRoot===null?false:realpath($documentRoot);
        $safeWebroot=$expected!==false && $actual!==false && $expected===$actual
            && PublicRootSafety::unsafePaths($this->root.'/public')===[];
        $add('Public webroot isolation',$safeWebroot,
            'Only public/ may be the website document root; no unexpected private scripts or links.');
        // Refer to schema constants, not copied version strings, so checks
        // follow the actual definitions when migrations are revised.
        $required=[
            SiteSchema::VERSION,PageRevisionSchema::VERSION,
            PageNavigationSchema::VERSION,PageHistorySchema::VERSION,
            SiteIdentitySchema::VERSION,MemberSchema::VERSION,
            MemberEmailSchema::VERSION,PasswordRecoverySchema::VERSION,
            MembershipSchema::VERSION,ModerationSchema::VERSION,
            CreatorSchema::VERSION,CreatorReviewSchema::VERSION,
            CreatorFeedbackSchema::VERSION,DesignSchema::VERSION,
            CutoutPlacement::VERSION,HighlightSchema::VERSION,
            AdminMfaSchema::VERSION,StaffSchema::VERSION,
            VerificationSchema::VERSION,
        ];
        $versions=$this->pdo->query('SELECT version FROM ac_schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN);
        $have=array_fill_keys(array_filter($versions,'is_string'),true);
        $pending=count(array_filter($required,static fn(string $v):bool=>!isset($have[$v])));
        $add('Monitored additive migrations',$pending===0,
            $pending===0?'All monitored local migrations recorded.':
                $pending.' monitored schema change(s) pending; back up before running Admin → Setup.');
        $edition=$this->pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn();
        $add('Local edition marker',in_array($edition,['lite','pro'],true),
            'Installed database edition must be Lite or Pro.');
        // Read validated site-local config only; do not call the paid vendor,
        // show its domain/key, or turn this into a license refresh operation.
        $license=(new LicenseManager($this->root))->config();
        $add('Local license/edition consistency',
            self::editionMatches($edition,$license),
            'The locally validated license SKU and database edition must agree. '
            .'Use the existing license/edition recovery flow if mismatched.');
        return $result;
    }

    /** @param mixed $edition @param mixed $license */
    public static function editionMatches(mixed $edition,mixed $license): bool
    {
        return in_array($edition,['lite','pro'],true)
            && is_array($license)
            && is_string($license['edition']??null)
            && hash_equals($edition,$license['edition']);
    }

    public static function allLocalChecksPass(array $checks): bool
    {
        if ($checks===[]) return false;
        foreach ($checks as $check) {
            if (!is_array($check) || ($check['pass']??null)!==true) return false;
        }
        return true;
    }
}
