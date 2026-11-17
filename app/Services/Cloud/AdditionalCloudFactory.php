<?php

namespace App\Services\Cloud;

use InvalidArgumentException;

final class AdditionalCloudFactory
{
    public static function fromStored(string $provider, string $stored): AdditionalCloudServerClient
    {
        $credentials = AdditionalCloudCredentials::unpack($stored);

        return match ($provider) {
            'linode' => new LinodeCloudClient($credentials['token']),
            'upcloud' => new UpCloudClient((string) $credentials['account'], $credentials['token']),
            'scaleway' => new ScalewayCloudClient($credentials['token'], (string) $credentials['project']),
            'contabo' => new ContaboCloudClient(
                (string) $credentials['project'],
                (string) $credentials['secret'],
                (string) $credentials['account'],
                $credentials['token'],
            ),
            'exoscale' => new ExoscaleCloudClient((string) $credentials['account'], $credentials['token']),
            'aws' => new AwsCloudClient((string) $credentials['account'], $credentials['token']),
            'google' => new GoogleCloudClient($credentials['token']),
            'azure' => new AzureCloudClient(
                (string) $credentials['account'],
                $credentials['token'],
                (string) $credentials['project'],
                (string) $credentials['secret'],
            ),
            default => throw new InvalidArgumentException('Unknown cloud provider.'),
        };
    }
}
