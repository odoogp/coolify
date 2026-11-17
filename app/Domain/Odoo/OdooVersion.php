<?php

namespace App\Domain\Odoo;

use Symfony\Component\Yaml\Yaml;

/**
 * Rewrites only the Odoo image tag inside an existing Compose file.
 * PostgreSQL and every other service stay as they are.
 */
class OdooVersion
{
    /** @var list<string> */
    public const SUPPORTED = ['17', '18', '19', '20'];

    public static function current(string $compose): ?string
    {
        $image = self::image($compose);
        if ($image === null) {
            return null;
        }

        $tag = substr($image, (int) strrpos($image, ':') + 1);

        return $tag !== '' ? $tag : null;
    }

    public static function image(string $compose): ?string
    {
        $service = self::odooService($compose);
        if ($service === null) {
            return null;
        }

        $image = strtolower(trim((string) ($service['image'] ?? '')));
        if ($image === '' || str_contains($image, '@') || ! self::isOdooImage($image)) {
            return null;
        }

        return $image;
    }

    public static function apply(string $compose, string $version): string
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/', $version)) {
            return $compose;
        }

        $image = self::image($compose);
        if ($image === null || ! str_contains($image, ':')) {
            return $compose;
        }

        $next = substr($image, 0, (int) strrpos($image, ':')).':'.$version;
        if ($next === $image) {
            return $compose;
        }

        $pattern = '/^([ \t]*image:[ \t]*)([\'"]?)'.preg_quote($image, '/').'\2[ \t]*$/mi';
        if (preg_match_all($pattern, $compose) !== 1) {
            return $compose;
        }

        $updated = preg_replace_callback($pattern, function (array $match) use ($next): string {
            return $match[1].$match[2].$next.$match[2];
        }, $compose, 1);

        return is_string($updated) ? $updated : $compose;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function odooService(string $compose): ?array
    {
        try {
            $yaml = Yaml::parse($compose);
        } catch (\Throwable) {
            return null;
        }

        $services = is_array($yaml) ? ($yaml['services'] ?? null) : null;
        if (! is_array($services)) {
            return null;
        }

        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            $image = strtolower((string) ($service['image'] ?? ''));
            $isOdoo = $name === 'odoo'
                || str_starts_with($image, 'odoo:')
                || str_contains($image, '/odoo:');
            if ($isOdoo) {
                return $service;
            }
        }

        return null;
    }

    private static function isOdooImage(string $image): bool
    {
        return str_starts_with($image, 'odoo:') || str_contains($image, '/odoo:');
    }
}
