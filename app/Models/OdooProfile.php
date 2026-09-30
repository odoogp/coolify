<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdooProfile extends Model
{
    protected $fillable = [
        'project_id',
        'odoo_version',
        'max_staging_environments',
        'unlimited_staging_environments',
        'github_app_id',
        'repository_id',
        'git_repository',
    ];

    protected function casts(): array
    {
        return [
            'max_staging_environments' => 'integer',
            'unlimited_staging_environments' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class);
    }
}
