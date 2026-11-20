<?php

declare(strict_types=1);

namespace AdultCore\Capability;

/**
 * Probe the optional, independently installed Pro service running on loopback.
 * It is intentionally impossible to choose an arbitrary URL from an HTTP request.
 */
final class ProAgentClient
{
    private const ENDPOINT = 'http://127.0.0.1:8787/v1/status';
    private const EXPECTED_SERVICE = 'adult-core-pro-agent';

    /** @return list<string> */
    public function detectCapabilities(string $projectRoot): array
    {
        $token = getenv('AFTERBURN_PRO_AGENT_TOKEN');
        if (!is_string($token) || strlen($token) < 32) {
            $localSecretFile = $projectRoot . '/config/pro-agent-secret.php';
            if (is_file($localSecretFile)) {
                $value = require $localSecretFile;
                $token = is_string($value) ? $value : '';
            }
        }
        if (!is_string($token) || preg_match('/^[A-Za-z0-9._~-]{32,256}$/D', $token) !== 1) {
            return [];
        }
        $body = $this->request($token);
        if ($body === null) {
            return [];
        }
        try {
            $json = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($json) || ($json['service'] ?? null) !== self::EXPECTED_SERVICE
            || ($json['api_version'] ?? null) !== 1 || ($json['status'] ?? null) !== 'ok') {
            return [];
        }
        $reported = $json['capabilities'] ?? null;
        if (!is_array($reported)) {
            return [];
        }
        // Only recognize explicit, supported service capabilities, not arbitrary agent text.
        $allowed = ['persistent_process', 'ffmpeg', 'video_pipeline', 'websocket_service'];
        $found = [];
        foreach ($reported as $capability) {
            if (is_string($capability) && in_array($capability, $allowed, true)) {
                $found[] = $capability;
            }
        }
        if (!in_array('persistent_process', $found, true)) {
            return [];
        }
        $found[] = 'pro_agent';
        return array_values(array_unique($found));
    }

    private function request(string $token): ?string
    {
        $auth = 'Authorization: Bearer ' . $token;
        if (function_exists('curl_init')) {
            $curl = curl_init(self::ENDPOINT);
            if ($curl === false) {
                return null;
            }
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 800,
                CURLOPT_CONNECTTIMEOUT_MS => 400,
                CURLOPT_HTTPHEADER => [$auth],
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $result = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            return $status === 200 && is_string($result) && strlen($result) < 8192 ? $result : null;
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 0.8,
            'ignore_errors' => false,
            'follow_location' => 0,
            'header' => $auth . "\r\nConnection: close\r\n",
        ]]);
        $result = @file_get_contents(self::ENDPOINT, false, $context, 0, 8193);
        if (!is_string($result) || strlen($result) > 8192) {
            return null;
        }
        $status = $http_response_header[0] ?? '';
        return str_contains($status, ' 200 ') ? $result : null;
    }
}
