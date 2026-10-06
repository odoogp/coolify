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
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
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
}
