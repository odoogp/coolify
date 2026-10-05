<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GetOdooServerOrder extends BaseModel
{
    protected $table = 'get_odoo_server_orders';

    protected $fillable = [
        'team_id',
        'user_id',
        'offer_id',
        'private_key_id',
        'server_id',
        'server_name',
        'location',
        'amount',
        'status',
        'wompi_link_id',
        'wompi_link_url',
        'wompi_transaction_id',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(GetOdooServerOffer::class, 'offer_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }
}
