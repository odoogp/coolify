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
    ];

    protected function casts(): array
    {
        return [
            'positions' => 'array',
            'groups' => 'array',
        ];
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
