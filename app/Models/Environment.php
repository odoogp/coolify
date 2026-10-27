<?php

namespace App\Models;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StopService;
use App\Jobs\DeleteResourceJob;
use App\Services\AdminCreationQuota;
use App\Support\OdooJupyter;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Environment model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'name' => ['type' => 'string'],
        'role' => ['type' => 'string', 'description' => 'System role: production or staging. Independent of display name.'],
        'project_id' => ['type' => 'integer'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
        'description' => ['type' => 'string'],
    ]
)]
class Environment extends BaseModel
{
    use ClearsGlobalSearchCache;
    use HasFactory;
    use HasSafeStringAttribute;

    public const ROLE_PRODUCTION = 'production';

    public const ROLE_STAGING = 'staging';

    protected $fillable = [
        'name',
        'role',
        'description',
        'project_id',
        'uuid',
        'created_by',
    ];

    protected $attributes = [
        'role' => self::ROLE_STAGING,
    ];

    /**
     * Set by the delete dialog. Not a column.
     */
    public bool $deleteVolumesWithResources = true;

    protected static function booted()
    {
        static::creating(function (Environment $environment): void {
            $role = (string) ($environment->role ?: self::ROLE_STAGING);
            if (! in_array($role, [self::ROLE_PRODUCTION, self::ROLE_STAGING], true)) {
                $role = self::ROLE_STAGING;
            }
            $environment->role = $role;

            // Multiple production environments are allowed: one per catalog
            // service type (first launch of each service). Staging is only for
            // a second branch of a service that already has production.

            app(AdminCreationQuota::class)->guardEnvironment($environment);
        });
        static::deleting(function (Environment $environment) {
            foreach ($environment->resources() as $resource) {
                if ($resource instanceof Service) {
                    OdooJupyter::rememberServiceVolumes($resource, $environment);
                }

                // Stop/remove containers now. DeleteResourceJob is queued — if
                // Horizon is down (e.g. mid-upgrade), soft-delete alone leaves
                // orphaned containers filling the host.
                try {
                    if ($resource instanceof Service) {
                        StopService::run($resource, deleteConnectedNetworks: true, dockerCleanup: false);
                        $server = $resource->destination?->server ?? $resource->server;
                        if ($server && $server->isFunctional() && filled($resource->uuid)) {
                            instant_remote_process([
                                'docker ps -aq --filter name='.escapeshellarg($resource->uuid).' | xargs -r docker rm -f',
                            ], $server, false);
                        }
                    } elseif ($resource instanceof Application) {
                        StopApplication::run($resource, previewDeployments: true, dockerCleanup: false);
                    } else {
                        StopDatabase::run($resource, dockerCleanup: false);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to stop containers while deleting environment; queued cleanup will retry.', [
                        'environment_id' => $environment->id,
                        'resource_type' => $resource::class,
                        'resource_id' => $resource->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }

                $resource->delete();
                DeleteResourceJob::dispatch($resource, $environment->deleteVolumesWithResources);
            }
            foreach ($environment->environment_variables as $sharedVariable) {
                $sharedVariable->delete();
            }
        });
    }

    public function isProduction(): bool
    {
        return (string) $this->role === self::ROLE_PRODUCTION;
    }

    public function isStaging(): bool
    {
        return (string) $this->role === self::ROLE_STAGING;
    }

    /**
     * Client-facing row status on the project list.
     * Odoo uptime wins over a stale Git addons sync failure: clients must not
     * see "Failed" when Abrir Odoo already works.
     */
    public function clientFacingStatus(?Service $service = null): string
    {
        $service ??= $this->services
            ->first(fn (Service $candidate): bool => $candidate->supportsOdooJupyter())
            ?? $this->services->first();

        $branchStatus = (string) ($this->odooBranch?->status ?? '');

        if ($service instanceof Service && $service->isStarting()) {
            return 'starting';
        }

        if ($branchStatus === 'updating') {
            return 'updating';
        }

        if ($service instanceof Service && $service->isRunning()) {
            return 'ready';
        }

        if ($branchStatus === 'failed') {
            return 'failed';
        }

        if ($service instanceof Service) {
            return 'empty';
        }

        return match ($branchStatus) {
            'idle', 'finished', 'ready' => 'empty',
            '' => 'empty',
            default => $branchStatus,
        };
    }

    public static function ownedByCurrentTeam()
    {
        return Environment::whereRelation('project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return Environment::whereRelation('project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Applications, databases, and services that belong to this environment.
     *
     * @return Collection<int, Model>
     */
    public function resources(): Collection
    {
        return $this->applications
            ->concat($this->databases())
            ->concat($this->services)
            ->values();
    }

    public function isEmpty()
    {
        return $this->applications()->count() == 0 &&
            $this->redis()->count() == 0 &&
            $this->postgresqls()->count() == 0 &&
            $this->mysqls()->count() == 0 &&
            $this->keydbs()->count() == 0 &&
            $this->dragonflies()->count() == 0 &&
            $this->clickhouses()->count() == 0 &&
            $this->mariadbs()->count() == 0 &&
            $this->mongodbs()->count() == 0 &&
            $this->services()->count() == 0;
    }

    public function odooBranch(): HasOne
    {
        return $this->hasOne(OdooEnvironmentBranch::class);
    }

    public function environment_variables()
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->where('type', 'environment');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function postgresqls()
    {
        return $this->hasMany(StandalonePostgresql::class);
    }

    public function redis()
    {
        return $this->hasMany(StandaloneRedis::class);
    }

    public function mongodbs()
    {
        return $this->hasMany(StandaloneMongodb::class);
    }

    public function mysqls()
    {
        return $this->hasMany(StandaloneMysql::class);
    }

    public function mariadbs()
    {
        return $this->hasMany(StandaloneMariadb::class);
    }

    public function keydbs()
    {
        return $this->hasMany(StandaloneKeydb::class);
    }

    public function dragonflies()
    {
        return $this->hasMany(StandaloneDragonfly::class);
    }

    public function clickhouses()
    {
        return $this->hasMany(StandaloneClickhouse::class);
    }

    public function databases()
    {
        $postgresqls = $this->postgresqls;
        $redis = $this->redis;
        $mongodbs = $this->mongodbs;
        $mysqls = $this->mysqls;
        $mariadbs = $this->mariadbs;
        $keydbs = $this->keydbs;
        $dragonflies = $this->dragonflies;
        $clickhouses = $this->clickhouses;

        return $postgresqls->concat($redis)->concat($mongodbs)->concat($mysqls)->concat($mariadbs)->concat($keydbs)->concat($dragonflies)->concat($clickhouses);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    protected function customizeName($value)
    {
        return str($value)->lower()->trim()->replace('/', '-')->toString();
    }
}
