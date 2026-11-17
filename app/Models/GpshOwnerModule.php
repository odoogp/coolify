<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class GpshOwnerModule extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * Module directory names the owner chose. Each Odoo start links these from the read-only mount.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        return static::query()
            ->orderBy('name')
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1)
            ->values()
            ->all();
    }
}
