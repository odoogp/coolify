<?php

namespace App\Services\Cloud;

final class AdditionalCloudCatalog
{
    /**
     * Providers added beside Hetzner, Vultr, and DigitalOcean.
     * Order is the order shown on the new-server screen.
     *
     * @return list<array{slug: string, label: string, summary: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'linode',
                'label' => 'Linode',
                'summary' => 'Provision a Linode.',
            ],
            [
                'slug' => 'upcloud',
                'label' => 'UpCloud',
                'summary' => 'Provision an UpCloud server.',
            ],
            [
                'slug' => 'scaleway',
                'label' => 'Scaleway',
                'summary' => 'Provision a Scaleway instance.',
            ],
            [
                'slug' => 'contabo',
                'label' => 'Contabo',
                'summary' => 'Provision a Contabo VPS.',
            ],
            [
                'slug' => 'exoscale',
                'label' => 'Exoscale',
                'summary' => 'Provision an Exoscale instance.',
            ],
            [
                'slug' => 'aws',
                'label' => 'AWS',
                'summary' => 'Provision an AWS instance.',
            ],
            [
                'slug' => 'google',
                'label' => 'Google Cloud',
                'summary' => 'Provision a Google Cloud instance.',
            ],
            [
                'slug' => 'azure',
                'label' => 'Microsoft Azure',
                'summary' => 'Provision an Azure virtual machine.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::definitions(), 'slug');
    }

    public static function supports(?string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    public static function providerRule(): string
    {
        return 'required|string|in:hetzner,digitalocean,vultr,'.implode(',', self::slugs());
    }

    /**
     * @return array{slug: string, label: string, summary: string}
     */
    public static function find(string $slug): array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['slug'] === $slug) {
                return $definition;
            }
        }

        throw new \InvalidArgumentException('Unknown cloud provider.');
    }
}
