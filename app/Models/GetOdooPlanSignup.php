<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GetOdooPlanSignup extends BaseModel
{
    protected $fillable = [
        'plan_id',
        'pricing_area_id',
        'name',
        'email',
        'password',
        'amount',
        'payment_gateway',
        'status',
        'wompi_link_id',
        'wompi_link_url',
        'wompi_transaction_id',
        'user_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'amount' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(GetOdooPlan::class, 'plan_id');
    }

    public function pricingArea(): BelongsTo
    {
        return $this->belongsTo(GetOdooPricingArea::class, 'pricing_area_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'awaiting_payment' => __('Pending payment'),
            'paid' => __('Paid'),
            'failed' => __('Failed'),
            default => (string) $this->status,
        };
    }
}
