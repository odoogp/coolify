<?php

namespace App\Models;

use App\Services\GetOdoo\GetOdooPrice;
use Illuminate\Database\Eloquent\Builder;

class GetOdooServerOffer extends BaseModel
{
    protected $table = 'get_odoo_server_offers';

    protected $fillable = [
        'hetzner_type_id',
        'name',
        'description',
        'cores',
        'memory',
        'disk',
        'architecture',
        'currency',
        'monthly_price',
        'markup',
        'location',
        'locations',
        'margin_percent',
        'available_for_admins',
        'available_since',
        'in_stock',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'locations' => 'array',
            'available_for_admins' => 'boolean',
            'in_stock' => 'boolean',
            'monthly_price' => 'decimal:4',
            'markup' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'memory' => 'decimal:2',
            'synced_at' => 'datetime',
            'available_since' => 'datetime',
        ];
    }

    public function scopeForAdmins(Builder $query): Builder
    {
        return $query->where('available_for_admins', true)->where('in_stock', true);
    }

    public function monthlyFor(?string $location = null): float
    {
        foreach ($this->locations ?? [] as $row) {
            if (($row['location'] ?? null) === $location) {
                return (float) $row['monthly'];
            }
        }

        return (float) $this->monthly_price;
    }

    public function sellPrice(?string $location = null, ?GetOdooPrice $price = null): float
    {
        $price ??= GetOdooPrice::current();

        if ($this->margin_percent !== null) {
            $price = new GetOdooPrice($price->eurUsd, $price->taxPercent, (float) $this->margin_percent);
        }

        return $price->suggestedUsd($this->monthlyFor($location));
    }
}
