<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTemplateOverride extends Model
{
    protected $fillable = [
        'name',
        'is_custom',
        'display_name',
        'description',
        'logo',
        'category',
        'is_visible',
        'includes_jupyter',
        'compose',
        'original_compose',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'is_visible' => 'boolean',
            'includes_jupyter' => 'boolean',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
