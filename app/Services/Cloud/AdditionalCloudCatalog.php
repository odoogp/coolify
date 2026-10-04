<?php

namespace App\Services\Cloud;

final class AdditionalCloudCatalog
{
    /**
     * Providers added beside Hetzner, Vultr, and DigitalOcean.
     * Order is the order shown on the new-server screen.
     *
     * @return list<array{slug: string, label: string, summary: string, mark: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'linode',
                'label' => 'Linode',
                'summary' => 'Provision a Linode.',
                'mark' => 'Ln',
            ],
            [
                'slug' => 'upcloud',
                'label' => 'UpCloud',
                'summary' => 'Provision an UpCloud server.',
                'mark' => 'Up',
            ],
            [
                'slug' => 'scaleway',
                'label' => 'Scaleway',
                'summary' => 'Provision a Scaleway instance.',
                'mark' => 'Sc',
            ],
            [
                'slug' => 'contabo',
                'label' => 'Contabo',
                'summary' => 'Provision a Contabo VPS.',
                'mark' => 'Co',
            ],
            [
                'slug' => 'exoscale',
                'label' => 'Exoscale',
                'summary' => 'Provision an Exoscale instance.',
                'mark' => 'Ex',
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
     * @return array{slug: string, label: string, summary: string, mark: string}
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
