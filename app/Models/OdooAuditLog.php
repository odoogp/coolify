<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OdooAuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'environment_id',
        'action',
        'result',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function write(?int $userId, ?int $projectId, ?int $environmentId, string $action, string $result, array $metadata = []): self
    {
        unset($metadata['token'], $metadata['private_key'], $metadata['password'], $metadata['webhook_secret']);

        $log = self::query()->create([
            'user_id' => $userId,
            'project_id' => $projectId,
            'environment_id' => $environmentId,
            'action' => $action,
            'result' => $result,
            'metadata' => $metadata,
        ]);

        if (function_exists('auditLog')) {
            auditLog($action, [
                'project_id' => $projectId,
                'environment_id' => $environmentId,
                'result' => $result,
            ] + $metadata);
        }

        return $log;
    }
}
