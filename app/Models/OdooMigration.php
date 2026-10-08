<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdooMigration extends BaseModel
{
    protected $fillable = [
        'project_id',
        'environment_id',
        'team_id',
        'user_id',
        'status',
        'odoo_version',
        'git_repository',
        'database_disk_path',
        'filestore_disk_path',
        'database_original_name',
        'filestore_original_name',
        'addons_disk_path',
        'addons_original_name',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasFiles(): bool
    {
        return filled($this->database_disk_path) && filled($this->filestore_disk_path);
    }

    public function hasAddonsZip(): bool
    {
        return filled($this->addons_disk_path);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => __('Draft'),
            'files_ready' => __('Files ready'),
            'queued' => __('Queued'),
            'running' => __('Running'),
            'complete' => __('Complete'),
            'failed' => __('Failed'),
            default => (string) $this->status,
        };
    }
}
