<?php

declare(strict_types=1);

namespace AdultCore\Installer;

use InvalidArgumentException;
use AdultCore\Licensing\CajunWebClient;

final class InstallInput
{
    /**
     * Reject a registered hostname that differs from the actual browser origin
     * before contacting the issuer or creating MySQL tables. Only the normal
     * www/apex equivalence is allowed; ports cannot select a different site.
     */
    public static function assertRequestHost(string $licenseDomain, string $httpHost): void
    {
        $registered = CajunWebClient::domain($licenseDomain);
        if (!preg_match('/^([a-zA-Z0-9.-]+)(?::([0-9]{1,5}))?$/D', $httpHost, $matches)
            || (isset($matches[2]) && ((int) $matches[2] < 1 || (int) $matches[2] > 65535))) {
            throw new InvalidArgumentException('The installation hostname does not match the registered license domain.');
        }
        try {
            $actual = CajunWebClient::domain($matches[1]);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('The installation hostname does not match the registered license domain.');
        }
        if (!hash_equals($registered, $actual)) {
            throw new InvalidArgumentException('The installation hostname does not match the registered license domain.');
        }
    }

    /**
     * Validate an installer service call independently of the browser form.
     * The browser has already checked password confirmation; the service only
     * receives the password itself and a normalized integer database port.
     * Every value is checked again before issuer requests or PDO creation.
     */
    public static function validateForService(array $fields): array
    {
        $port = $fields['db_port'] ?? null;
        $password = $fields['admin_password'] ?? null;
        if ((!is_int($port) && !is_string($port)) || !is_string($password)) {
            throw new InvalidArgumentException('Invalid installation configuration.');
        }
        $candidate = $fields;
        $candidate['db_port'] = (string) $port;
        $candidate['admin_password_confirm'] = $password;
        return self::validate($candidate);
    }

    /** Validate before any network/database requests; never interpolate unchecked DSN fields. */
    public static function validate(array $fields): array
    {
        $take = static fn(string $key): string => is_string($fields[$key] ?? null) ? trim($fields[$key]) : '';
        $licenseKey = CajunWebClient::key($take('license_key'));
        $licenseDomain = CajunWebClient::domain($take('license_domain'));
        $dbHost = $take('db_host');
        $dbName = $take('db_name');
        $dbUser = $take('db_user');
        $dbPassword = $fields['db_password'] ?? null;
        $rawSiteName=$fields['site_name']??null;
        $siteName = $take('site_name');
        $email = strtolower($take('admin_email'));
        $password = $fields['admin_password'] ?? null;
        $confirm = $fields['admin_password_confirm'] ?? null;
        $portString = $take('db_port');
        if ($portString === '') {
            $portString = '3306';
        }
        if (strlen($dbHost) > 253 || preg_match('/^(?:[a-zA-Z0-9][a-zA-Z0-9._-]*|\[[a-fA-F0-9:]+\])$/D', $dbHost) !== 1
            || !ctype_digit($portString) || (int) $portString < 1 || (int) $portString > 65535
            || preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $dbName) !== 1
            || preg_match('/^[a-zA-Z0-9_@.\-]{1,128}$/D', $dbUser) !== 1
            || !is_string($dbPassword) || strlen($dbPassword) > 1024
            || !is_string($rawSiteName) || strlen($rawSiteName)>120
            || preg_match('//u',$rawSiteName)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$rawSiteName)===1
            || strlen($siteName) < 2 || strlen($siteName) > 120
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
            || !is_string($password) || strlen($password) < 12 || strlen($password) > 72
            || !hash_equals($password, is_string($confirm) ? $confirm : '')) {
            throw new InvalidArgumentException('Please check database settings, site name, email and password (minimum 12 characters; both passwords must match).');
        }
        return [
            'license_key' => $licenseKey, 'license_domain' => $licenseDomain,
            'db_host' => $dbHost,
            'db_port' => (int) $portString,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_password' => $dbPassword,
            'site_name' => $siteName,
            'admin_email' => $email,
            'admin_password' => $password,
        ];
    }
}
