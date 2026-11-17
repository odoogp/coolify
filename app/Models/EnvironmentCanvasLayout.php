<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvironmentCanvasLayout extends Model
{
    protected $fillable = [
        'environment_id',
        'positions',
        'groups',
        'connections',
    ];

    protected function casts(): array
    {
        return [
            'positions' => 'array',
            'groups' => 'array',
            'connections' => 'array',
        ];
    }

    /**
     * User-drawn edges between canvas nodes (agent ↔ services, etc.).
     *
     * @return list<array{id: string, from: string, to: string, label?: string}>
     */
    public function connectionsList(): array
    {
        $rows = $this->connections ?? [];
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row)
                && filled($row['id'] ?? null)
                && filled($row['from'] ?? null)
                && filled($row['to'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (string) $row['id'],
                'from' => (string) $row['from'],
                'to' => (string) $row['to'],
                'label' => is_string($row['label'] ?? null) ? $row['label'] : 'link',
            ])
            ->values()
            ->all();
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return array<string, array{x: float, y: float}>
     */
    public function positionsMap(): array
    {
        $positions = $this->positions ?? [];
        $out = [];
        foreach ($positions as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            $out[(string) $key] = [
                'x' => (float) ($value['x'] ?? 0),
                'y' => (float) ($value['y'] ?? 0),
            ];
        }

        return $out;
    }
}
