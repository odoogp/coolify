<?php

namespace App\Models;

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
        'max_projects',
        'max_environments',
        'max_members',
        'max_production_branches',
        'max_staging_branches',
        'max_services',
        'can_add_servers',
        'can_launch_on_instance_server',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'max_projects' => 'integer',
            'max_environments' => 'integer',
            'max_members' => 'integer',
            'max_production_branches' => 'integer',
            'max_staging_branches' => 'integer',
            'max_services' => 'integer',
            'can_add_servers' => 'boolean',
            'can_launch_on_instance_server' => 'boolean',
        ];
    }

    public function signups(): HasMany
    {
        return $this->hasMany(GetOdooPlanSignup::class, 'plan_id');
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
     * @return list<array{label: string, value: string}>
     */
    public function adminLimits(): array
    {
        $number = fn (?int $value): string => $value === null ? __('No limit') : (string) $value;

        return [
            ['label' => __('Projects'), 'value' => $number($this->max_projects)],
            ['label' => __('Environments'), 'value' => $number($this->max_environments)],
            ['label' => __('Members'), 'value' => $number($this->max_members)],
            ['label' => __('Production branches'), 'value' => $number($this->max_production_branches)],
            ['label' => __('Staging branches'), 'value' => $number($this->max_staging_branches)],
            ['label' => __('Services'), 'value' => $number($this->max_services)],
            ['label' => __('Can add servers'), 'value' => $this->can_add_servers ? __('Yes') : __('No')],
            ['label' => __('Can launch instances on the server where GPSH is installed'), 'value' => $this->can_launch_on_instance_server ? __('Yes') : __('No')],
        ];
    }
}
