<?php

namespace App;

class EntraID
{
    public static function isConfigured(): bool
    {
        return Config::get('ENTRAID_TENANT_ID') !== ''
            && Config::get('ENTRAID_CLIENT_ID') !== ''
            && Config::get('ENTRAID_CLIENT_SECRET') !== ''
            && Config::get('ENTRAID_REDIRECT_URI') !== '';
    }

    public static function getAuthorizationUrl(string $state): string
    {
        $tenantId = Config::get('ENTRAID_TENANT_ID');
        $clientId = Config::get('ENTRAID_CLIENT_ID');
        $redirectUri = Config::get('ENTRAID_REDIRECT_URI');

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
        ]);

        return "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize?{$params}";
    }

    public static function exchangeCode(string $code): array
    {
        $tenantId = Config::get('ENTRAID_TENANT_ID');
        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        $postData = [
            'client_id' => Config::get('ENTRAID_CLIENT_ID'),
            'client_secret' => Config::get('ENTRAID_CLIENT_SECRET'),
            'code' => $code,
            'redirect_uri' => Config::get('ENTRAID_REDIRECT_URI'),
            'grant_type' => 'authorization_code',
            'scope' => 'openid profile email',
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Token exchange failed: ' . $response);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['id_token'])) {
            throw new \RuntimeException('Invalid token response');
        }

        return $data;
    }

    public static function parseIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid ID token format');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload) {
            throw new \RuntimeException('Failed to decode ID token');
        }

        return [
            'oid' => $payload['oid'] ?? $payload['sub'] ?? '',
            'name' => $payload['name'] ?? '',
            'email' => $payload['email'] ?? $payload['preferred_username'] ?? '',
            'preferred_username' => $payload['preferred_username'] ?? '',
        ];
    }
}
