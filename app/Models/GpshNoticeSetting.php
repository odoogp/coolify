<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GpshNoticeSetting extends Model
{
    public $incrementing = false;

    protected $table = 'gpsh_notice_settings';

    protected $fillable = [
        'mounted',
        'accessible',
        'expiration',
        'deletion',
        'custom',
    ];

    protected function casts(): array
    {
        return [
            'mounted' => 'boolean',
            'accessible' => 'boolean',
            'expiration' => 'boolean',
            'deletion' => 'boolean',
            'custom' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    public function allows(string $kind): bool
    {
        return in_array($kind, ['mounted', 'accessible', 'expiration', 'deletion', 'custom'], true)
            && (bool) $this->getAttribute($kind);
    }
}
