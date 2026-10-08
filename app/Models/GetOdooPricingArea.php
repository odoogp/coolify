<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GetOdooPricingArea extends BaseModel
{
    public const KIND_REGION = 'region';

    public const KIND_COUNTRY = 'country';

    protected $fillable = [
        'code',
        'name',
        'kind',
        'parent_id',
        'iso_code',
        'extra_fixed',
        'extra_percent',
        'is_active',
        'allow_multiple_projects',
        'allow_all_services',
        'allowed_services',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'extra_fixed' => 'decimal:2',
            'extra_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'allow_multiple_projects' => 'boolean',
            'allow_all_services' => 'boolean',
            'allowed_services' => 'array',
            'sort_order' => 'integer',
            'parent_id' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(
            GetOdooPlan::class,
            'get_odoo_plan_pricing_area',
            'pricing_area_id',
            'plan_id',
        )->withPivot('promo_price')->withTimestamps();
    }

    public function isRegion(): bool
    {
        return $this->kind === self::KIND_REGION;
    }

    public function isCountry(): bool
    {
        return $this->kind === self::KIND_COUNTRY;
    }

    public static function normalizeCode(string $value): string
    {
        return Str::slug(trim($value));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function countryChoices(): array
    {
        return self::query()
            ->where('kind', self::KIND_COUNTRY)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (self $area): array => [
                'value' => (string) $area->id,
                'label' => $area->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Countries offered on a plan signup. Worldwide plans get every active country.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function countryChoicesForPlan(GetOdooPlan $plan): array
    {
        $all = self::countryChoices();
        if ($plan->isAvailableWorldwide()) {
            return $all;
        }

        $ids = $plan->pricingAreas()
            ->where('kind', self::KIND_COUNTRY)
            ->where('is_active', true)
            ->pluck('get_odoo_pricing_areas.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_filter(
            $all,
            fn (array $row): bool => in_array((int) $row['value'], $ids, true)
        ));
    }
}
