<?php

declare(strict_types=1);

namespace AdultCore\Website;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Only the installed site's public brand is mutable. The canonical ac_settings
 * site_name is shared by public and preview renderers, never by the vendor
 * license domain, administrator identity, membership or consent records.
 */
final class SiteIdentity
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validateName(mixed $name): string
    {
        if (!is_string($name) || strlen($name)>120
            || preg_match('//u',$name)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$name)===1) {
            throw new RuntimeException('Site name must be valid single-line text, up to 120 bytes.');
        }
        $clean=trim($name);
        if (strlen($clean)<2 || strlen($clean)>120) {
            throw new RuntimeException('Site name must be between 2 and 120 bytes.');
        }
        return $clean;
    }

    /** @return array{site_name:string,revision:int} */
    public function current(): array
    {
        if (!SiteIdentitySchema::installed($this->pdo)) {
            throw new RuntimeException('Apply site identity migration in Site setup.');
        }
        $q=$this->pdo->query("SELECT setting_key,setting_value FROM ac_settings
            WHERE setting_key IN ('site_name','site_name_revision')");
        $values=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[$row['setting_key']]=$row['setting_value'];
        }
        $revision=$values['site_name_revision']??null;
        if (!is_string($revision) || preg_match('/^[1-9][0-9]{0,8}$/D',$revision)!==1
            || !is_string($values['site_name']??null)) {
            throw new RuntimeException('Local site identity needs repair from a verified backup.');
        }
        // An older installation might contain a legacy name the new validator
        // would refuse. Show it escaped so the owner can repair it safely.
        return ['site_name'=>$values['site_name'],'revision'=>(int)$revision];
    }

    public function rename(mixed $name,int $expectedRevision): int
    {
        $clean=self::validateName($name);
        if ($expectedRevision<1 || $expectedRevision>=999999999) {
            throw new RuntimeException('Invalid local site identity revision.');
        }
        if (!SiteIdentitySchema::installed($this->pdo)) {
            throw new RuntimeException('Apply site identity migration in Site setup.');
        }
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->query("SELECT setting_value FROM ac_settings
                WHERE setting_key='site_name_revision' FOR UPDATE");
            $version=$q->fetchColumn();
            if (!is_string($version) || preg_match('/^[1-9][0-9]{0,8}$/D',$version)!==1) {
                throw new RuntimeException('Local site identity needs repair from a verified backup.');
            }
            if ((int)$version!==$expectedRevision) {
                throw new RuntimeException('Site name changed; reload before saving.');
            }
            $q=$this->pdo->prepare("UPDATE ac_settings SET setting_value=?
                WHERE setting_key='site_name'");
            $q->execute([$clean]);
            if ((int)$this->pdo->query("SELECT COUNT(*) FROM ac_settings WHERE setting_key='site_name'")->fetchColumn()!==1) {
                throw new RuntimeException('Local site identity needs repair from a verified backup.');
            }
            $next=$expectedRevision+1;
            $q=$this->pdo->prepare("UPDATE ac_settings SET setting_value=?
                WHERE setting_key='site_name_revision' AND setting_value=?");
            $q->execute([(string)$next,(string)$expectedRevision]);
            if ($q->rowCount()!==1) {
                throw new RuntimeException('Site name changed; reload before saving.');
            }
            $this->pdo->commit();
            return $next;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
