<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceChangeDraft extends Model
{
    protected $fillable = [
        'service_id',
        'user_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{subdomain: string, workers: int}
     */
    public function runtimePayload(): array
    {
        $payload = $this->payload ?? [];

        return [
            'subdomain' => (string) ($payload['subdomain'] ?? ''),
            'workers' => max(0, min(32, (int) ($payload['workers'] ?? 0))),
        ];
    }
}
