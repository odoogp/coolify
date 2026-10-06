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
            'max_members' => __('Members'),
            'max_production_branches' => __('Production branches'),
            'max_staging_branches' => __('Staging branches'),
            'max_services' => __('Services'),
        ] as $column => $label) {
            $value = $this->{$column};

            if ($value === null) {
                $items[] = ['label' => $label, 'value' => __('No limit')];
            } elseif ((int) $value > 0) {
                $items[] = ['label' => $label, 'value' => (string) (int) $value];
            }
        }

        if ($this->can_add_servers) {
            $items[] = ['label' => __('Can add servers'), 'value' => null];
        }

        if ($this->can_launch_on_instance_server) {
            $items[] = ['label' => __('Can launch on the platform server'), 'value' => null];
        }

        return $items;
    }
}
