<?php

namespace App\Models;

use App\Services\GetOdoo\ResolveGetOdooPlansForCountry;
use App\Support\GetOdooBackupFrequency;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GetOdooPlan extends BaseModel
{
    protected $fillable = [
        'name',
        'summary',
        'description',
        'price',
        'currency',
        'payment_gateway',
        'is_active',
        'is_rest_of_world',
        'max_projects',
        'max_environments',
        'max_members',
        'max_production_branches',
        'max_staging_branches',
        'max_services',
        'can_add_servers',
        'can_launch_on_instance_server',
        'includes_migration',
        'backup_frequency',
        'backup_retention_days',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_rest_of_world' => 'boolean',
            'max_projects' => 'integer',
            'max_environments' => 'integer',
            'max_members' => 'integer',
            'max_production_branches' => 'integer',
            'max_staging_branches' => 'integer',
            'max_services' => 'integer',
            'can_add_servers' => 'boolean',
            'can_launch_on_instance_server' => 'boolean',
            'includes_migration' => 'boolean',
            'backup_retention_days' => 'integer',
        ];
    }

    public function signups(): HasMany
    {
        return $this->hasMany(GetOdooPlanSignup::class, 'plan_id');
    }

    /**
     * Country and/or region pricing areas. Empty when the plan is Rest of the world.
     */
    public function pricingAreas(): BelongsToMany
    {
        return $this->belongsToMany(
            GetOdooPricingArea::class,
            'get_odoo_plan_pricing_area',
            'plan_id',
            'pricing_area_id',
        )->withPivot('promo_price')->withTimestamps();
    }

    public function isRestOfWorld(): bool
    {
        return (bool) $this->is_rest_of_world;
    }

    /**
     * @deprecated Use isRestOfWorld()
     */
    public function isAvailableWorldwide(): bool
    {
        return $this->isRestOfWorld();
    }

    public function isAvailableInCountry(?GetOdooPricingArea $country): bool
    {
        return ResolveGetOdooPlansForCountry::planIsAvailable($this, $country);
    }

    public function scopeLabel(): string
    {
        if ($this->isRestOfWorld()) {
            return __('Rest of the world');
        }

        $areas = $this->relationLoaded('pricingAreas')
            ? $this->pricingAreas
            : $this->pricingAreas()->get();

        if ($areas->isEmpty()) {
            return __('Rest of the world');
        }

        return $areas->map(function (GetOdooPricingArea $area): string {
            $label = $area->name;
            if ($area->isRegion()) {
                $label = __('Region').': '.$label;
            }
            if ($area->pivot?->promo_price !== null) {
                $label .= ' $'.number_format((float) $area->pivot->promo_price, 2);
            }

            return $label;
        })->implode(', ');
    }

    public function isFree(): bool
    {
        return (float) $this->price <= 0;
    }

    public function publicUrl(): string
    {
        return route('getodoo.plan.start', ['plan' => $this->uuid]);
    }

    /**
     * Quotas and permissions the customer actually receives. A zero or a false stays off this list.
     *
     * @return list<array{label: string, value: ?string}>
     */
    public function includedItems(): array
    {
        $items = [];

        foreach ([
            'max_projects' => __('Projects'),
            'max_environments' => __('Environments'),
            'max_members' => __('Members (users)'),
            'max_production_branches' => __('Production branches'),
            'max_staging_branches' => __('Staging branches'),
        ] as $column => $label) {
            $value = $this->{$column};

            if ($value === null) {
                $items[] = ['label' => $label, 'value' => __('No limit')];
            } elseif ((int) $value > 0) {
                $items[] = ['label' => $label, 'value' => (string) (int) $value];
            }
        }

        if ($this->can_launch_on_instance_server) {
            $items[] = ['label' => __('Can launch on the platform server'), 'value' => null];
        }

        if ($this->includes_migration) {
            $items[] = ['label' => __('Migration help (GitHub, repository, dump + filestore)'), 'value' => null];
        }

        $frequency = (string) ($this->backup_frequency ?: GetOdooBackupFrequency::DAILY);
        if ($frequency !== GetOdooBackupFrequency::NONE) {
            $items[] = [
                'label' => __('Automatic Odoo backups'),
                'value' => GetOdooBackupFrequency::label($frequency),
            ];
        }

        return $items;
    }
}
