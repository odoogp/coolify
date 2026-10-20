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
        'waiting_tips',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waiting_tips' => 'array',
        ];
    }

    public static function composeFor(string $version): ?string
    {
        $compose = static::query()->where('version', $version)->value('compose');

        return is_string($compose) && $compose !== '' ? $compose : null;
    }

    /**
     * Tips shown on the public waiting page while that Odoo version mounts.
     * Null on the row means stock tips; an empty list means no tip carousel.
     *
     * @return list<string>
     */
    public static function defaultWaitingTips(): array
    {
        return [
            'Each environment can use its own GitHub branch.',
            'JupyterLab opens the addons folder while Odoo starts.',
            'Production and staging keep separate databases and filestores.',
            'A push to the environment branch updates only the modules.',
            'When it finishes, Open Odoo starts the administrator session.',
            'Worker changes apply the next time the service starts.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function waitingTipsFor(string $version): array
    {
        $row = static::query()->where('version', $version)->first();
        if (! $row instanceof self || $row->waiting_tips === null) {
            return self::defaultWaitingTips();
        }

        return self::normalizeWaitingTips($row->waiting_tips);
    }

    /**
     * @param  mixed  $tips
     * @return list<string>
     */
    public static function normalizeWaitingTips(mixed $tips): array
    {
        if (! is_array($tips)) {
            return [];
        }

        $clean = [];
        foreach ($tips as $tip) {
            if (! is_string($tip)) {
                continue;
            }
            $tip = trim(preg_replace("/\s+/u", ' ', $tip) ?? '');
            if ($tip === '' || mb_strlen($tip) > 280) {
                continue;
            }
            $clean[] = $tip;
            if (count($clean) >= 20) {
                break;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Versions that have a saved Compose row (Settings → Odoo). Launch pickers use only these.
     *
     * @return list<string>
     */
    public static function configuredVersions(): array
    {
        $versions = static::query()
            ->whereNotNull('compose')
            ->where('compose', '!=', '')
            ->pluck('version')
            ->map(fn (mixed $version): string => (string) $version)
            ->all();

        natsort($versions);

        return array_values($versions);
    }

    public static function isConfiguredVersion(string $version): bool
    {
        return static::composeFor($version) !== null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function versionOptions(): array
    {
        return collect(self::configuredVersions())
            ->map(fn (string $version): array => [
                'value' => $version,
                'label' => 'Odoo '.$version,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{compose: string, postgresVersion: string, waitingTips: list<string>}
     */
    public static function editorState(string $version): array
    {
        $row = static::query()->where('version', $version)->first();
        if ($row instanceof self) {
            return [
                'compose' => $row->compose,
                'postgresVersion' => $row->postgres_version,
                'waitingTips' => $row->waiting_tips === null
                    ? self::defaultWaitingTips()
                    : self::normalizeWaitingTips($row->waiting_tips),
            ];
        }

        $compose = self::defaultCompose($version);

        return [
            'compose' => $compose,
            'postgresVersion' => self::tagFromImage(self::postgresImage($compose)) ?? '16-alpine',
            'waitingTips' => self::defaultWaitingTips(),
        ];
    }

    /**
     * @param  list<string>|null  $waitingTips  null keeps stock tips on the row
     */
    public static function saveFor(string $version, string $compose, string $postgresVersion, ?array $waitingTips = null): self
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

        $payload = [
            'postgres_version' => $postgresVersion,
            'compose' => $compose,
        ];
        if ($waitingTips !== null) {
            $payload['waiting_tips'] = self::normalizeWaitingTips($waitingTips);
        }

        return static::query()->updateOrCreate(
            ['version' => $version],
            $payload,
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
        $tag = self::postgresTag($tag);
        $current = self::postgresImage($compose);
        $next = 'postgres:'.$tag;
        if ($current !== null && $current !== $next) {
            $pattern = '/^([ \t]*image:[ \t]*)([\'"]?)'.preg_quote($current, '/').'\2[ \t]*$/mi';
            if (preg_match_all($pattern, $compose) === 1) {
                $updated = preg_replace_callback($pattern, function (array $match) use ($next): string {
                    return $match[1].$match[2].$next.$match[2];
                }, $compose, 1);
                if (is_string($updated)) {
                    $compose = $updated;
                }
            }
        }

        return self::applyPostgresDataMount($compose, $tag);
    }

    /**
     * PostgreSQL 18+ stores data under /var/lib/postgresql (not .../data).
     * Mounting the old path makes the DB container exit, so Odoo cannot resolve HOST=postgresql.
     */
    public static function applyPostgresDataMount(string $compose, string $tag): string
    {
        $major = self::postgresMajor($tag);
        if ($major === null) {
            return $compose;
        }

        $target = $major >= 18 ? '/var/lib/postgresql' : '/var/lib/postgresql/data';
        $wrong = $major >= 18 ? '/var/lib/postgresql/data' : '/var/lib/postgresql';
        // Short-syntax volume lines for the postgresql service data mount.
        $pattern = '/^([ \t]*-[ \t]*[\'"]?[A-Za-z0-9_.-]*(?:postgres|postgresql)[-_]?data[\'"]?:)'.preg_quote($wrong, '/').'([\'"]?[ \t]*)$/mi';
        $updated = preg_replace($pattern, '$1'.$target.'$2', $compose);

        return is_string($updated) ? $updated : $compose;
    }

    public static function postgresMajor(string $tag): ?int
    {
        $tag = self::postgresTag($tag);
        if (preg_match('/^(\d+)/', $tag, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
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
