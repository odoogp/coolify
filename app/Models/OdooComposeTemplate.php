<?php

namespace App\Models;

use App\Domain\Odoo\OdooVersion;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

class OdooComposeTemplate extends Model
{
    protected $fillable = [
        'version',
        'postgres_version',
        'compose',
    ];

    public static function composeFor(string $version): ?string
    {
        $compose = static::query()->where('version', $version)->value('compose');

        return is_string($compose) && $compose !== '' ? $compose : null;
    }

    /**
     * @return array{compose: string, postgresVersion: string}
     */
    public static function editorState(string $version): array
    {
        $row = static::query()->where('version', $version)->first();
        if ($row instanceof self) {
            return [
                'compose' => $row->compose,
                'postgresVersion' => $row->postgres_version,
            ];
        }

        $compose = self::defaultCompose($version);

        return [
            'compose' => $compose,
            'postgresVersion' => self::tagFromImage(self::postgresImage($compose)) ?? '16-alpine',
        ];
    }

    public static function saveFor(string $version, string $compose, string $postgresVersion): self
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/', $version)) {
            throw new InvalidArgumentException('The Odoo version is the image tag, for example 21.');
        }

        $postgresVersion = self::postgresTag($postgresVersion);
        $compose = OdooVersion::apply($compose, $version);
        $compose = self::applyPostgres($compose, $postgresVersion);
        if (OdooVersion::image($compose) === null) {
            throw new InvalidArgumentException('The Compose file needs an Odoo service.');
        }

        return static::query()->updateOrCreate(
            ['version' => $version],
            [
                'postgres_version' => $postgresVersion,
                'compose' => $compose,
            ],
        );
    }

    public static function defaultCompose(string $version): string
    {
        $path = base_path('templates/compose/odoo.yaml');
        $base = is_file($path)
            ? (string) file_get_contents($path)
            : "services:\n  odoo:\n    image: odoo:18\n  postgresql:\n    image: postgres:16-alpine\n";

        return self::applyPostgres(OdooVersion::apply($base, $version), '16-alpine');
    }

    public static function applyPostgres(string $compose, string $tag): string
    {
        $current = self::postgresImage($compose);
        $next = 'postgres:'.self::postgresTag($tag);
        if ($current === null || $current === $next) {
            return $compose;
        }

        $pattern = '/^([ \t]*image:[ \t]*)([\'"]?)'.preg_quote($current, '/').'\2[ \t]*$/mi';
        if (preg_match_all($pattern, $compose) !== 1) {
            return $compose;
        }

        $updated = preg_replace_callback($pattern, function (array $match) use ($next): string {
            return $match[1].$match[2].$next.$match[2];
        }, $compose, 1);

        return is_string($updated) ? $updated : $compose;
    }

    public static function postgresImage(string $compose): ?string
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
            $image = strtolower(trim((string) ($service['image'] ?? '')));
            $isPostgres = $name === 'postgresql'
                || str_starts_with($image, 'postgres:')
                || str_contains($image, '/postgres:');
            if ($isPostgres && $image !== '' && ! str_contains($image, '@')) {
                return $image;
            }
        }

        return null;
    }

    public static function postgresTag(string $version): string
    {
        $version = strtolower(trim($version));
        $version = str_starts_with($version, 'postgres:') ? substr($version, 9) : $version;
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,40}$/', $version)) {
            throw new InvalidArgumentException('Invalid PostgreSQL version.');
        }

        return $version;
    }

    public static function tagFromImage(?string $image): ?string
    {
        if ($image === null || ! str_contains($image, ':')) {
            return null;
        }

        $tag = substr($image, (int) strrpos($image, ':') + 1);

        return $tag !== '' ? $tag : null;
    }
}
