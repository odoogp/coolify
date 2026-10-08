<?php

namespace App\Support;

use App\Models\ServiceTemplateOverride;
use App\Models\Team;
use App\Services\GetOdoo\GetOdooAreaEntitlements;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ServiceTemplateCatalog
{
    public static function apply(Collection $templates): Collection
    {
        if (! Schema::hasTable('service_template_overrides')) {
            return $templates;
        }

        $rows = ServiceTemplateOverride::query()->get()->keyBy('name');
        if ($rows->isEmpty()) {
            return $templates;
        }

        $templates = $templates
            ->reject(function (mixed $template, int|string $key) use ($rows): bool {
                $row = $rows->get((string) $key);

                return $row instanceof ServiceTemplateOverride && ! $row->is_visible && ! $row->is_custom;
            })
            ->map(function (mixed $template, int|string $key) use ($rows): mixed {
                $name = (string) $key;
                $row = $rows->get($name);
                if (! $row instanceof ServiceTemplateOverride || $row->is_custom) {
                    return $template;
                }

                $compose = base64_encode((string) $row->compose);
                if (is_object($template)) {
                    $template = clone $template;
                    $template->compose = $compose;
                    if (filled($row->display_name)) {
                        $template->display_name = $row->display_name;
                    }
                    if (filled($row->description)) {
                        $template->description = $row->description;
                    }
                    if (filled($row->logo)) {
                        $template->logo = $row->logo;
                    }
                    if (filled($row->category)) {
                        $template->category = $row->category;
                    }

                    return $template;
                }

                if (is_array($template)) {
                    $template['compose'] = $compose;
                    if (filled($row->display_name)) {
                        $template['display_name'] = $row->display_name;
                    }
                    if (filled($row->description)) {
                        $template['description'] = $row->description;
                    }
                    if (filled($row->logo)) {
                        $template['logo'] = $row->logo;
                    }
                    if (filled($row->category)) {
                        $template['category'] = $row->category;
                    }
                }

                return $template;
            });

        foreach ($rows->where('is_custom', true)->where('is_visible', true) as $row) {
            $templates->put($row->name, (object) [
                'compose' => base64_encode((string) $row->compose),
                'display_name' => $row->display_name ?: Str::headline($row->name),
                'description' => $row->description,
                'logo' => $row->logo ?: 'svgs/default.webp',
                'category' => $row->category ?: 'Custom',
                'is_custom' => true,
            ]);
        }

        return $templates->sortKeys();
    }

    /**
     * @return list<array{name: string, label: string, overridden: bool, is_custom: bool, is_visible: bool, category: ?string}>
     */
    public static function summaries(): array
    {
        $rows = Schema::hasTable('service_template_overrides')
            ? ServiceTemplateOverride::query()->get()->keyBy('name')
            : collect();

        $fromCatalog = service_templates_from_catalog()
            ->keys()
            ->map(function (mixed $name) use ($rows): array {
                $key = (string) $name;
                $row = $rows->get($key);

                return [
                    'name' => $key,
                    'label' => filled($row?->display_name) ? (string) $row->display_name : Str::headline($key),
                    'overridden' => $row instanceof ServiceTemplateOverride && ! $row->is_custom,
                    'is_custom' => false,
                    'is_visible' => $row instanceof ServiceTemplateOverride ? (bool) $row->is_visible : true,
                    'category' => $row?->category,
                ];
            });

        $custom = $rows
            ->where('is_custom', true)
            ->map(fn (ServiceTemplateOverride $row): array => [
                'name' => $row->name,
                'label' => $row->display_name ?: Str::headline($row->name),
                'overridden' => true,
                'is_custom' => true,
                'is_visible' => (bool) $row->is_visible,
                'category' => $row->category,
            ])
            ->values();

        return $fromCatalog
            ->concat($custom)
            ->sortBy('name', SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * Picker rows: Odoo first, then the rest of the launchable catalog.
     *
     * @return list<array{value: string, label: string, description: ?string, category: ?string, logo: ?string}>
     */
    public static function launchOptions(): array
    {
        $templates = get_service_templates();

        return $templates
            ->map(function (mixed $template, int|string $key): array {
                $name = (string) $key;
                $label = data_get($template, 'display_name');
                if (! is_string($label) || $label === '') {
                    $label = $name === 'odoo' ? 'Odoo' : Str::headline($name);
                }

                return [
                    'value' => $name,
                    'label' => $label,
                    'description' => is_string(data_get($template, 'description')) ? data_get($template, 'description') : null,
                    'category' => is_string(data_get($template, 'category')) ? data_get($template, 'category') : null,
                    'logo' => is_string(data_get($template, 'logo')) ? data_get($template, 'logo') : null,
                ];
            })
            ->sortBy(fn (array $row): array => [$row['value'] === 'odoo' ? 0 : 1, strtolower($row['label'])])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string, description: ?string, category: ?string, logo: ?string}>
     */
    public static function launchOptionsForTeam(?Team $team = null): array
    {
        return GetOdooAreaEntitlements::filterLaunchOptions(
            self::launchOptions(),
            $team ?? currentTeam(),
        );
    }

    public static function composeFor(string $name): ?string
    {
        self::assertUsableName($name);

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

    public static function isCustom(string $name): bool
    {
        if (! Schema::hasTable('service_template_overrides')) {
            return false;
        }

        return ServiceTemplateOverride::query()->where('name', $name)->where('is_custom', true)->exists();
    }

    /**
     * @param  array{display_name?: string, description?: string, logo?: string, category?: string, is_visible?: bool}  $meta
     */
    public static function create(string $name, string $compose, ?int $userId, array $meta = []): void
    {
        $name = self::normalizeName($name);
        self::assertCreatableName($name);
        $compose = str_replace(["\r\n", "\r"], "\n", $compose);
        validateDockerComposeForInjection($compose);

        ServiceTemplateOverride::query()->create([
            'name' => $name,
            'is_custom' => true,
            'display_name' => filled($meta['display_name'] ?? null) ? (string) $meta['display_name'] : Str::headline($name),
            'description' => filled($meta['description'] ?? null) ? (string) $meta['description'] : null,
            'logo' => filled($meta['logo'] ?? null) ? (string) $meta['logo'] : null,
            'category' => filled($meta['category'] ?? null) ? (string) $meta['category'] : 'Custom',
            'is_visible' => array_key_exists('is_visible', $meta) ? (bool) $meta['is_visible'] : true,
            'compose' => $compose,
            'original_compose' => null,
            'updated_by' => $userId,
        ]);

        self::writeComposeFile($name, $compose, create: true);
    }

    /**
     * @param  array{display_name?: string, description?: string, logo?: string, category?: string, is_visible?: bool}  $meta
     */
    public static function save(string $name, string $compose, ?int $userId, array $meta = []): void
    {
        self::assertUsableName($name);
        $compose = str_replace(["\r\n", "\r"], "\n", $compose);
        validateDockerComposeForInjection($compose);

        $existing = ServiceTemplateOverride::query()->where('name', $name)->first();
        $isCustom = (bool) ($existing?->is_custom ?? false);
        $original = $existing?->original_compose ?? ($isCustom ? null : self::readComposeFile($name));

        $payload = [
            'compose' => $compose,
            'original_compose' => $original,
            'updated_by' => $userId,
            'is_custom' => $isCustom,
        ];
        if (array_key_exists('display_name', $meta)) {
            $payload['display_name'] = filled($meta['display_name']) ? (string) $meta['display_name'] : null;
        }
        if (array_key_exists('description', $meta)) {
            $payload['description'] = filled($meta['description']) ? (string) $meta['description'] : null;
        }
        if (array_key_exists('logo', $meta)) {
            $payload['logo'] = filled($meta['logo']) ? (string) $meta['logo'] : null;
        }
        if (array_key_exists('category', $meta)) {
            $payload['category'] = filled($meta['category']) ? (string) $meta['category'] : null;
        }
        if (array_key_exists('is_visible', $meta)) {
            $payload['is_visible'] = (bool) $meta['is_visible'];
        }

        ServiceTemplateOverride::query()->updateOrCreate(
            ['name' => $name],
            $payload,
        );

        self::writeComposeFile($name, $compose, create: $isCustom);
    }

    public static function restore(string $name): void
    {
        self::assertUsableName($name);

        $override = ServiceTemplateOverride::query()->where('name', $name)->first();
        if ($override === null) {
            return;
        }

        if ($override->is_custom) {
            $override->delete();
            self::deleteComposeFile($name);

            return;
        }

        $original = $override->original_compose;
        $override->delete();

        if (is_string($original) && $original !== '') {
            self::writeComposeFile($name, $original);
        }
    }

    private static function normalizeName(string $name): string
    {
        return Str::slug($name);
    }

    private static function assertCreatableName(string $name): void
    {
        self::assertSlug($name);

        if (service_templates_from_catalog()->has($name) || self::isOverridden($name)) {
            throw new InvalidArgumentException(__('That service template already exists.'));
        }
    }

    private static function assertUsableName(string $name): void
    {
        self::assertSlug($name);

        if (service_templates_from_catalog()->has($name) || self::isCustom($name) || self::isOverridden($name)) {
            return;
        }

        throw new InvalidArgumentException('Unknown service template.');
    }

    private static function assertSlug(string $name): void
    {
        if ($name === '' || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $name) !== 1) {
            throw new InvalidArgumentException(__('Use a simple lowercase name (letters, numbers, dashes).'));
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

    private static function composeFilePath(string $name, bool $forCreate = false): ?string
    {
        $directory = rtrim((string) config('constants.services.compose_path'), '/');
        foreach (['yaml', 'yml'] as $extension) {
            $path = $directory.'/'.$name.'.'.$extension;
            if (is_file($path)) {
                return $path;
            }
        }

        if ($forCreate && is_dir($directory) && is_writable($directory)) {
            return $directory.'/'.$name.'.yaml';
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

    private static function writeComposeFile(string $name, string $compose, bool $create = false): void
    {
        $path = self::composeFilePath($name, forCreate: $create);
        if ($path === null) {
            return;
        }
        if (! is_file($path) && ! $create) {
            return;
        }
        if (is_file($path) && ! is_writable($path)) {
            return;
        }

        File::put($path, $compose);
    }

    private static function deleteComposeFile(string $name): void
    {
        $path = self::composeFilePath($name);
        if ($path !== null && is_file($path) && is_writable($path)) {
            File::delete($path);
        }
    }
}
