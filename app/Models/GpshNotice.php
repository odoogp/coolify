<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
    ];

    public function reads(): HasMany
    {
        return $this->hasMany(GpshNoticeRead::class, 'notice_id');
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
