<?php

namespace App\Support;

use App\Models\ServiceTemplateOverride;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ServiceTemplateCatalog
{
    public static function apply(Collection $templates): Collection
    {
        if (! Schema::hasTable('service_template_overrides')) {
            return $templates;
        }

        $overrides = ServiceTemplateOverride::query()->pluck('compose', 'name');
        if ($overrides->isEmpty()) {
            return $templates;
        }

        return $templates->map(function (mixed $template, int|string $key) use ($overrides): mixed {
            $name = (string) $key;
            if (! $overrides->has($name)) {
                return $template;
            }

            $compose = base64_encode((string) $overrides->get($name));
            if (is_object($template)) {
                $template = clone $template;
                $template->compose = $compose;

                return $template;
            }

            if (is_array($template)) {
                $template['compose'] = $compose;
            }

            return $template;
        });
    }

    /**
     * @return list<array{name: string, overridden: bool}>
     */
    public static function summaries(): array
    {
        $overridden = Schema::hasTable('service_template_overrides')
            ? ServiceTemplateOverride::query()->pluck('name')->all()
            : [];

        return service_templates_from_catalog()
            ->keys()
            ->map(fn (mixed $name): array => [
                'name' => (string) $name,
                'overridden' => in_array((string) $name, $overridden, true),
            ])
            ->sortBy('name', SORT_NATURAL)
            ->values()
            ->all();
    }

    public static function composeFor(string $name): ?string
    {
        self::assertKnownName($name);

        if (self::isOverridden($name)) {
            $override = ServiceTemplateOverride::query()->where('name', $name)->value('compose');
            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        $file = self::readComposeFile($name);

        if (is_string($file) && $file !== '') {
            return $file;
        }

        return self::catalogCompose($name);
    }

    public static function isOverridden(string $name): bool
    {
        if (! Schema::hasTable('service_template_overrides')) {
            return false;
        }

        return ServiceTemplateOverride::query()->where('name', $name)->exists();
    }

    public static function save(string $name, string $compose, ?int $userId): void
    {
        self::assertKnownName($name);
        $compose = str_replace(["\r\n", "\r"], "\n", $compose);
        validateDockerComposeForInjection($compose);

        $existing = ServiceTemplateOverride::query()->where('name', $name)->first();
        $original = $existing?->original_compose ?? self::readComposeFile($name);

        ServiceTemplateOverride::query()->updateOrCreate(
            ['name' => $name],
            [
                'compose' => $compose,
                'original_compose' => $original,
                'updated_by' => $userId,
            ],
        );

        self::writeComposeFile($name, $compose);
    }

    public static function restore(string $name): void
    {
        self::assertKnownName($name);

        $override = ServiceTemplateOverride::query()->where('name', $name)->first();
        if ($override === null) {
            return;
        }

        $original = $override->original_compose;
        $override->delete();

        if (is_string($original) && $original !== '') {
            self::writeComposeFile($name, $original);
        }
    }

    private static function assertKnownName(string $name): void
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '.')) {
            throw new InvalidArgumentException('Unknown service template.');
        }

        if (! service_templates_from_catalog()->has($name)) {
            throw new InvalidArgumentException('Unknown service template.');
        }
    }

    private static function catalogCompose(string $name): ?string
    {
        $encoded = data_get(service_templates_from_catalog(), "{$name}.compose");
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        return is_string($decoded) ? $decoded : null;
    }

    private static function composeFilePath(string $name): ?string
    {
        $directory = rtrim((string) config('constants.services.compose_path'), '/');
        foreach (['yaml', 'yml'] as $extension) {
            $path = $directory.'/'.$name.'.'.$extension;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function readComposeFile(string $name): ?string
    {
        $path = self::composeFilePath($name);
        if ($path === null) {
            return null;
        }

        $contents = File::get($path);

        return $contents === '' ? null : $contents;
    }

    private static function writeComposeFile(string $name, string $compose): void
    {
        $path = self::composeFilePath($name);
        if ($path === null || ! is_writable($path)) {
            return;
        }

        File::put($path, $compose);
    }
}
