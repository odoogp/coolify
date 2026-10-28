<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GetOdooPlanOrder extends BaseModel
{
    protected $table = 'get_odoo_plan_orders';

    protected $fillable = [
        'team_id',
        'user_id',
        'plan_id',
        'amount',
        'description',
        'status',
        'purpose',
        'payment_gateway',
        'charge_mode',
        'n1co_plan_id',
        'n1co_subscription_id',
        'n1co_subscription_status',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(GetOdooPlan::class, 'plan_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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
            'cancelled' => __('Cancelled'),
            default => (string) $this->status,
        };
    }

    public function statusType(): string
    {
        return match ($this->status) {
            'paid' => 'success',
            'failed' => 'error',
            'awaiting_payment' => 'warning',
            default => 'neutral',
        };
    }

    /**
     * Client-facing line: which plan was charged and why (signup vs renewal).
     */
    public function lineLabel(): string
    {
        $this->loadMissing('plan');
        $planName = trim((string) ($this->plan?->name ?: ''));
        if ($planName === '') {
            $planName = __('Organization plan');
        }

        $purpose = match ((string) $this->purpose) {
            'signup' => __('Plan signup'),
            'renewal' => __('Plan renewal'),
            'extra' => __('Plan add-on'),
            default => filled($this->description) ? (string) $this->description : __('Plan charge'),
        };

        if (filled($this->description) && ! in_array((string) $this->purpose, ['signup', 'renewal'], true)) {
            return $planName.' · '.(string) $this->description;
        }

        return $planName.' · '.$purpose;
    }

    public function chargeModeLabel(): ?string
    {
        return match ((string) ($this->charge_mode ?: 'once')) {
            'subscription' => __('Recurring'),
            'once' => __('One-time'),
            default => null,
        };
    }
}
