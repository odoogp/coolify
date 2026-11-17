<?php

namespace App\Models;

use App\Support\OdooGit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GpshNotice extends Model
{
    protected $fillable = [
        'title',
        'body',
        'audience',
        'kind',
        'team_id',
        'service_id',
        'created_by',
        'action_url',
        'action_label',
    ];

    public function reads(): HasMany
    {
        return $this->hasMany(GpshNoticeRead::class, 'notice_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function href(): ?string
    {
        if (filled($this->action_url)) {
            return (string) $this->action_url;
        }

        $service = $this->service;
        $environment = $service?->environment;
        $project = $environment?->project;
        if (! $service instanceof Service || $environment === null || $project === null) {
            return null;
        }

        if ($this->kind === 'accessible') {
            $open = OdooGit::enterUrl($service);
            if ($open !== '') {
                return $open;
            }
        }

        return route('project.show', [
            'project_uuid' => $project->uuid,
            'environment' => $environment->uuid,
        ]);
    }

    public function actionLabel(): ?string
    {
        if (filled($this->action_label)) {
            return (string) $this->action_label;
        }

        if ($this->kind === 'accessible' && is_string($this->href()) && ! str_starts_with((string) $this->href(), url('/'))) {
            return __('Open Odoo');
        }

        return filled($this->action_url) ? __('Open') : null;
    }

    public function audienceLabel(): string
    {
        return $this->audience === 'owner' ? __('For the owner') : __('For clients');
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'mounted' => __('Instance is up'),
            'accessible' => __('Instance is accessible'),
            'expiration' => __('Expirations'),
            'deletion' => __('Instances to delete'),
            default => __('Custom messages'),
        };
    }
}
