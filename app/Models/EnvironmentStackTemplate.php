<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvironmentStackTemplate extends Model
{
    protected $fillable = [
        'team_id',
        'created_by',
        'name',
        'display_name',
        'description',
        'services',
        'connections',
        'positions',
    ];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'connections' => 'array',
            'positions' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return list<array{key: string, service_type: string, odoo_version?: string|null, connect_to_docker_network?: bool}>
     */
    public function servicesList(): array
    {
        $rows = $this->services ?? [];
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row)
                && filled($row['key'] ?? null)
                && filled($row['service_type'] ?? null))
            ->map(fn (array $row): array => [
                'key' => (string) $row['key'],
                'service_type' => (string) $row['service_type'],
                'odoo_version' => is_string($row['odoo_version'] ?? null) ? $row['odoo_version'] : null,
                'connect_to_docker_network' => (bool) ($row['connect_to_docker_network'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, from_key: string, to_key: string, label?: string}>
     */
    public function connectionsList(): array
    {
        $rows = $this->connections ?? [];
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row)
                && filled($row['from_key'] ?? null)
                && filled($row['to_key'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (string) ($row['id'] ?? ('link:'.$row['from_key'].'->'.$row['to_key'])),
                'from_key' => (string) $row['from_key'],
                'to_key' => (string) $row['to_key'],
                'label' => is_string($row['label'] ?? null) ? $row['label'] : 'intranet',
            ])
            ->values()
            ->all();
    }
}
