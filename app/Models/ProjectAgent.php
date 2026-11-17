<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectAgent extends Model
{
    public const PROVIDERS = [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'openrouter' => 'OpenRouter',
        'google' => 'Google',
        'custom' => 'Custom OpenAI-compatible',
    ];

    protected $fillable = [
        'project_id',
        'name',
        'provider',
        'model',
        'api_key',
        'base_url',
        'is_enabled',
        'follow_in_cloud',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_enabled' => 'boolean',
            'follow_in_cloud' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ProjectAgentMessage::class);
    }

    public function isReady(): bool
    {
        return $this->is_enabled
            && filled($this->api_key)
            && filled($this->provider)
            && filled($this->model);
    }

    public function resolvedBaseUrl(): string
    {
        if (filled($this->base_url)) {
            return rtrim((string) $this->base_url, '/');
        }

        return match ($this->provider) {
            'anthropic' => 'https://api.anthropic.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'google' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            default => 'https://api.openai.com/v1',
        };
    }

    /**
     * @return list<string>
     */
    public function connectedNodeIds(): array
    {
        $settings = $this->settings ?? [];
        $ids = $settings['connected_node_ids'] ?? [];

        return collect(is_array($ids) ? $ids : [])
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
    }
}
