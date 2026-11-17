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
        'customer_summary',
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

    public function customerSummary(): string
    {
        $written = trim((string) $this->customer_summary);

        if ($written !== '') {
            return $written;
        }

        return $this->defaultCustomerSummary();
    }

    public function defaultCustomerSummary(): string
    {
        $code = strtolower((string) preg_replace('/[^a-z0-9]/', '', (string) $this->name));

        return match (true) {
            str_starts_with($code, 'cax') || $this->architecture === 'arm' => __('ARM processor. It does the same work and uses less.'),
            str_starts_with($code, 'ccx') => __('Dedicated processor. Its power is not shared.'),
            str_starts_with($code, 'cpx') => __('Shared AMD processor, with more room for the application.'),
            str_starts_with($code, 'cx') => __('Shared processor for a site or an application.'),
            default => __('A server for your application.'),
        };
    }
}
