<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use AdultCore\Installer\AtomicFile;
use AdultCore\Installer\InstallationGate;
use RuntimeException;

/** A signed, private module entitlement cache; paid add-ons never inherit a core key. */
final class ModuleLicenseManager
{
    public function __construct(private readonly string $root, private readonly ?CajunWebClient $client = null) {}

    private function authority(): CajunWebClient { return $this->client ?? new CajunWebClient(); }
    public static function sku(string $sku): bool
    {
        return preg_match('/^SOFTWARE-ADULT-CORE-MODULE-[A-Z0-9_-]{2,50}$/D', $sku) === 1;
    }
    private static function id(string $id): string
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $id)) throw new RuntimeException('Invalid add-on identifier.');
        return $id;
    }
    private function directory(): string { return $this->root . '/storage/packages/module-licenses'; }
    private function pending(string $id): string { return $this->directory().'/.pending-'.$id; }

    /** Never follow operator-supplied symlinks into external secret storage. */
    private function assertSafeStorage(): void
    {
        foreach ([$this->root . '/storage', $this->root . '/storage/packages', $this->directory()] as $directory) {
            if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
                throw new RuntimeException('Private module license storage is unsafe.');
            }
        }
    }

    private static function assertReplaceable(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || is_link($dir) || !is_writable($dir)
            || is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Module license path unsafe.');
        }
    }
    private function secret(): string
    {
        $path = $this->root.'/config/app-key.php';
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Private application key unavailable.');
        $key = require $path;
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('Private application key invalid.');
        return $key;
    }
    private function domain(): string
    {
        // Add-on entitlements never override the core installation gate or
        // core license. This also protects direct service/worker callers that
        // do not pass through the HTTP front controller.
        if ((new InstallationGate($this->root))->status() !== InstallationGate::READY) {
            throw new RuntimeException('Core installation is not ready for paid modules.');
        }
        $manager = new LicenseManager($this->root);
        $record = $manager->config();
        if ($record === null || $manager->status($record['domain']) !== 'valid') {
            throw new RuntimeException('Core license must be authorized before paid module access.');
        }
        return $record['domain'];
    }
    public function activate(string $id, string $sku, string $key): void
    {
        $id=self::id($id);
        if (!self::sku($sku)) throw new RuntimeException('Module has no recognized licensed product.');
        $key=CajunWebClient::key($key);
        $domain=$this->domain();
        $this->assertSafeStorage();
        $dir=$this->directory();
        // Refuse known-unsafe local targets BEFORE sending the module key to
        // the issuer. Recheck under lock below before changing either file.
        foreach ([$dir.'/.lock', $dir.'/'.$id.'.php', $dir.'/'.$id.'.json', $this->pending($id)] as $path) {
            // The per-module pending marker may be a regular file left by an
            // interrupted activation. Only an issuer-verified retry clears it.
            if ($path === $this->pending($id) && is_file($path) && !is_link($path)) continue;
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new RuntimeException('Module license private storage is unsafe.');
            }
        }
        if ($this->authority()->validate($key,$domain,$sku) !== 'valid') {
            throw new RuntimeException('Cajun Web Pro did not authorize this module license.');
        }
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Private module license storage unavailable.');
        $secret=$this->secret();
        if (is_link($dir.'/.lock') || (file_exists($dir.'/.lock') && !is_file($dir.'/.lock'))) {
            throw new RuntimeException('Module license lock is unsafe.');
        }
        $lock=@fopen($dir.'/.lock','c+');
        if (!is_resource($lock)) throw new RuntimeException('Module license lock unavailable.');
        try {
            if (!flock($lock,LOCK_EX)) throw new RuntimeException('Module license lock unavailable.');
            $this->assertSafeStorage();
            // Both destinations must be safe before writing either one.
            self::assertReplaceable($dir.'/'.$id.'.php');
            self::assertReplaceable($dir.'/'.$id.'.json');
            $pending = $this->pending($id);
            if (is_link($pending) || (file_exists($pending) && !is_file($pending))) {
                throw new RuntimeException('Module activation recovery marker is unsafe.');
            }
            if (!is_file($pending)) AtomicFile::write($pending, "module-activation-pending\n");
            // A failed write leaves this fail-closed marker for authenticated
            // reactivation using a newly verified paid module license key.
            self::writePrivate($dir.'/'.$id.'.php', "<?php\nreturn ".var_export(['key'=>$key,'sku'=>$sku,'domain'=>$domain],true).";\n");
            self::writePrivate($dir.'/'.$id.'.json',LicenseState::encode($key,$domain,$secret,'valid',time(),time(),$sku));
            if (!@unlink($pending)) throw new RuntimeException('Module activation pending marker could not be cleared.');
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    /** @return 'valid'|'denied'|'unavailable' */
    public function status(string $id, string $sku): string
    {
        if (!self::sku($sku)) return 'denied';
        try {
            $id=self::id($id);
            $this->assertSafeStorage();
            $dir=$this->directory();
            $pending=$this->pending($id);
            if (file_exists($pending) || is_link($pending)) return 'unavailable';
            if (is_link($dir.'/.lock') || (file_exists($dir.'/.lock') && !is_file($dir.'/.lock'))) {
                return 'unavailable';
            }
            $path=$dir.'/'.$id.'.php';
            if (!is_file($path) || is_link($path)) return 'denied';
            $record=require $path;
            if (!is_array($record) || ($record['sku']??null)!==$sku || ($record['domain']??null)!==$this->domain()) return 'denied';
            $key=CajunWebClient::key((string)($record['key']??''));
            $domain=$record['domain'];$secret=$this->secret();
            $cachePath=$dir.'/'.$id.'.json';
            $read=function()use($cachePath,$key,$domain,$secret,$sku):?array{
                if (!is_file($cachePath) || is_link($cachePath) || filesize($cachePath)>4096) return null;
                $raw=@file_get_contents($cachePath);
                return is_string($raw)?LicenseState::decode($raw,$key,$domain,$secret,$sku):null;
            };
            $cached=$read();$now=time();
            if($cached!==null && $cached['checked_at']+LicenseManager::REFRESH_SECONDS>$now)return $cached['verdict'];
            $lock=@fopen($dir.'/.lock','c+');
            if(!is_resource($lock))return self::grace($cached,$now);
            try {
                if(!flock($lock,LOCK_EX|LOCK_NB))return self::grace($cached,$now);
                $cached=$read();
                if($cached!==null && $cached['checked_at']+LicenseManager::REFRESH_SECONDS>$now)return $cached['verdict'];
                $answer=$this->authority()->validate($key,$domain,$sku);
                if($answer==='valid'||$answer==='denied'){
                    $last=$answer==='valid'?$now:($cached['last_success']??0);
                    self::writePrivate($cachePath,LicenseState::encode($key,$domain,$secret,$answer,$now,$last,$sku));
                    return $answer;
                }
                return self::grace($cached,$now);
            }finally{flock($lock,LOCK_UN);fclose($lock);}
        }catch(\Throwable){return 'unavailable';}
    }
    private static function grace(?array $receipt,int $now): string
    {
        if ($receipt!==null && $receipt['verdict']==='denied')return 'denied';
        return $receipt!==null&&$receipt['verdict']==='valid'&&$receipt['last_success']>0
            &&$receipt['last_success']+LicenseManager::TRANSIENT_GRACE_SECONDS>$now?'valid':'unavailable';
    }
    private static function writePrivate(string $path,string $body):void
    {
        self::assertReplaceable($path);
        $dir=dirname($path);
        $temp=tempnam($dir,'.module-license-');
        if($temp===false)throw new RuntimeException('Module license storage unavailable.');
        try {
            if(!chmod($temp,0600)||file_put_contents($temp,$body,LOCK_EX)!==strlen($body)||!rename($temp,$path)){
                throw new RuntimeException('Module license could not be saved.');
            }
        }finally{if(is_file($temp))@unlink($temp);}
    }
}
