<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (self $area): array {
                $label = $area->name;
                if ($area->parent instanceof self) {
                    $label .= ' · '.$area->parent->name;
                }

                return [
                    'value' => (string) $area->id,
                    'label' => $label,
                ];
            })
            ->values()
            ->all();
    }
}
