<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "connect your server" run: a Cloudflare tunnel, its DNS record and the
 * one-time token the client's install command uses.
 */
class ServerAgentEnrollment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'uuid',
        'token_hash',
        'team_id',
        'server_id',
        'created_by',
        'hostname',
        'cloudflare_tunnel_id',
        'cloudflare_dns_record_id',
        'tunnel_token',
        'status',
        'expires_at',
        'reported_at',
        'connected_at',
        'error',
    ];

    protected $hidden = [
        'token_hash',
        'tunnel_token',
    ];

    protected function casts(): array
    {
        return [
            'tunnel_token' => 'encrypted',
            'expires_at' => 'datetime',
            'reported_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The enrollment behind a public install/ready token, if it can still be used.
     */
    public static function findUsableByToken(string $token): ?self
    {
        if (preg_match('/\A[A-Za-z0-9]{40,80}\z/', $token) !== 1) {
            return null;
        }

        $enrollment = self::query()->where('token_hash', self::hashToken($token))->first();
        if (! $enrollment instanceof self || ! hash_equals($enrollment->token_hash, self::hashToken($token))) {
            return null;
        }

        return $enrollment->isUsable() ? $enrollment : null;
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REPORTED], true)
            && $this->expires_at->isFuture()
            && $this->server_id !== null;
    }

    public function scopeOwnedByTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
