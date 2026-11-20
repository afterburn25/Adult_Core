<?php

declare(strict_types=1);

namespace AdultCore\Member;

use RuntimeException;

/** Opt-in PHP mail transport for compatible shared hosts; SMTP API integration is future work. */
final class EmailDelivery
{
    public static function loadConfig(string $root): ?array
    {
        $path=$root.'/config/email.php';
        if (!is_file($path) || is_link($path)) return null;
        $config=require $path;
        if (!is_array($config) || ($config['enabled']??false)!==true) return null;
        self::validateConfig($config);
        return $config;
    }

    /** Validates a fixed HTTPS origin, not a request-supplied Host header. */
    public static function validateConfig(array $config): void
    {
        $from=$config['from']??null;
        $base=$config['base_url']??null;
        if (!is_string($from) || filter_var($from,FILTER_VALIDATE_EMAIL)===false
            || strlen($from)>190 || preg_match('/[\r\n]/',$from)
            || !is_string($base) || strlen($base)>255 || preg_match('/[\r\n]/',$base)) {
            throw new RuntimeException('Invalid email delivery configuration.');
        }
        $parts=parse_url($base);
        if (!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https'
            || !is_string($parts['host']??null) || !filter_var('https://'.$parts['host'],FILTER_VALIDATE_URL)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['port']) || isset($parts['path']) || rtrim($base,'/')!==$base) {
            throw new RuntimeException('Email base URL must be an HTTPS origin without a path.');
        }
    }

    public static function send(string $recipient, string $token, array $config): bool
    {
        self::validateConfig($config);
        if (filter_var($recipient,FILTER_VALIDATE_EMAIL)===false || preg_match('/[\r\n]/',$recipient)
            || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('Invalid email delivery request.');
        $url=$config['base_url'].'/members/email/confirm?token='.$token;
        // The message uses deliberately neutral copy to protect account privacy.
        $headers=['From: '.$config['from'],'Content-Type: text/plain; charset=UTF-8', 'Auto-Submitted: auto-generated'];
        return mail($recipient,'Confirm your account',"Confirm ownership of this email address using this one-time link:\n\n"
            .$url."\n\nThe link expires after 30 minutes. If you did not request it, ignore this message.\n",
            implode("\r\n",$headers));
    }
}
