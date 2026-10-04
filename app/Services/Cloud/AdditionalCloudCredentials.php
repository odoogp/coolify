<?php

namespace App\Services\Cloud;

final class AdditionalCloudCredentials
{
    /**
     * Hetzner, Vultr, DigitalOcean, and Linode keep a single secret.
     * Providers that need more than one value store a JSON object in the same encrypted column.
     */
    public static function pack(string $provider, string $token, ?string $account = null, ?string $secret = null, ?string $project = null): string
    {
        if (! in_array($provider, ['upcloud', 'scaleway', 'contabo', 'exoscale', 'aws', 'azure'], true)) {
            return $token;
        }

        return json_encode([
            'token' => $token,
            'account' => $account,
            'secret' => $secret,
            'project' => $project,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{token: string, account: ?string, secret: ?string, project: ?string}
     */
    public static function unpack(string $stored): array
    {
        $stored = trim($stored);

        if (! str_starts_with($stored, '{')) {
            return [
                'token' => $stored,
                'account' => null,
                'secret' => null,
                'project' => null,
            ];
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded) || ! array_key_exists('token', $decoded)) {
            return [
                'token' => $stored,
                'account' => null,
                'secret' => null,
                'project' => null,
            ];
        }

        return [
            'token' => (string) ($decoded['token'] ?? ''),
            'account' => filled($decoded['account'] ?? null) ? (string) $decoded['account'] : null,
            'secret' => filled($decoded['secret'] ?? null) ? (string) $decoded['secret'] : null,
            'project' => filled($decoded['project'] ?? null) ? (string) $decoded['project'] : null,
        ];
    }
}
