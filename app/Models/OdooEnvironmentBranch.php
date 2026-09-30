<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdooEnvironmentBranch extends Model
{
    protected $fillable = [
        'environment_id',
        'git_branch',
        'status',
        'domain',
        'odoo_version',
        'workers',
        'addons_path',
        'jupyter_enabled',
        'service_id',
        'addons_application_id',
    ];

    protected function casts(): array
    {
        return [
            'workers' => 'integer',
            'jupyter_enabled' => 'boolean',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
