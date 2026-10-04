<?php

namespace App\Models;

use App\Services\AdminCreationQuota;
use App\Domain\Odoo\OdooStaging;
use App\Domain\Odoo\OdooVersion;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Project model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'name' => ['type' => 'string'],
        'description' => ['type' => 'string'],
    ]
)]
class Project extends BaseModel
{
    use ClearsGlobalSearchCache;
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'name',
        'description',
        'team_id',
        'uuid',
        'created_by',
    ];

    /**
     * Get query builder for projects owned by current team.
     * If you need all projects without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return Project::whereTeamId(currentTeam()->id)->orderByRaw('LOWER(name)');
    }

    /**
     * Get all projects owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return Project::ownedByCurrentTeam()->get();
        });
    }

    protected static function booted()
    {
        static::creating(function (Project $project): void {
            app(AdminCreationQuota::class)->guardProject($project);
        });
        static::created(function ($project) {
            ProjectSetting::create([
                'project_id' => $project->id,
            ]);
            Environment::create([
                'name' => 'production',
                'project_id' => $project->id,
                'uuid' => new_public_id(),
                'created_by' => $project->created_by,
            ]);
        });
        static::deleting(function ($project) {
            OdooEnvironmentBranch::query()->whereIn('environment_id', $project->environments()->pluck('id'))->delete();
            $project->odooProfile()->delete();
            $project->environments()->delete();
            $project->settings()->delete();
            $shared_variables = $project->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                $shared_variable->delete();
            }
        });
    }

    public function environment_variables()
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->where('type', 'project');
    }

    public function environments()
    {
        return $this->hasMany(Environment::class);
    }

    public function settings()
    {
        return $this->hasOne(ProjectSetting::class);
    }

    public function odooProfile(): HasOne
    {
        return $this->hasOne(OdooProfile::class);
    }

    /**
     * Save the Odoo profile. Does not create environments, services, or deployments.
     * An environment is created later, when it is launched as a GitHub branch.
     */
    public function enableOdoo(string $version, int $maxStagingEnvironments = 1, bool $unlimitedStagingEnvironments = false): OdooProfile
    {
        if (! in_array($version, OdooVersion::SUPPORTED, true)) {
            throw new InvalidArgumentException('Unsupported Odoo version.');
        }
        if ($maxStagingEnvironments < 0) {
            throw new InvalidArgumentException('Staging environment limit cannot be negative.');
        }

        return DB::transaction(function () use ($version, $maxStagingEnvironments, $unlimitedStagingEnvironments): OdooProfile {
            $profile = $this->odooProfile()->updateOrCreate(
                ['project_id' => $this->id],
                [
                    'odoo_version' => $version,
                    'max_staging_environments' => $maxStagingEnvironments,
                    'unlimited_staging_environments' => $unlimitedStagingEnvironments,
                ],
            );
            $this->setRelation('odooProfile', $profile);

            return $profile;
        });
    }

    public function canCreateStagingEnvironment(): bool
    {
        $this->loadMissing('odooProfile');

        return OdooStaging::canCreateStagingEnvironment($this);
    }

    public function createNextStagingEnvironment(): Environment
    {
        $this->loadMissing('odooProfile');
        if (! OdooStaging::canCreateStagingEnvironment($this)) {
            throw new RuntimeException('Staging environment limit reached.');
        }

        $name = OdooStaging::nextName($this);
        if (strcasecmp($name, 'production') === 0) {
            throw new RuntimeException('A clone cannot create another production environment.');
        }

        return Environment::create([
            'name' => $name,
            'project_id' => $this->id,
            'created_by' => auth()->id() ?? $this->created_by,
        ]);
    }

    /**
     * One new staging environment from the existing production category.
     * Does not create a second production, services, or a deployment.
     */
    public function cloneProductionAsStaging(): Environment
    {
        $this->loadMissing('odooProfile');
        if ($this->odooProfile === null) {
            throw new RuntimeException('Odoo is not enabled for this project.');
        }

        $productions = $this->environments()->get()->filter(
            fn (Environment $environment): bool => strcasecmp($environment->name, 'production') === 0
        );
        if ($productions->count() !== 1) {
            throw new RuntimeException('This project must have one production environment.');
        }

        return $this->createNextStagingEnvironment();
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function services()
    {
        return $this->hasManyThrough(Service::class, Environment::class);
    }

    public function applications()
    {
        return $this->hasManyThrough(Application::class, Environment::class);
    }

    public function postgresqls()
    {
        return $this->hasManyThrough(StandalonePostgresql::class, Environment::class);
    }

    public function redis()
    {
        return $this->hasManyThrough(StandaloneRedis::class, Environment::class);
    }

    public function keydbs()
    {
        return $this->hasManyThrough(StandaloneKeydb::class, Environment::class);
    }

    public function dragonflies()
    {
        return $this->hasManyThrough(StandaloneDragonfly::class, Environment::class);
    }

    public function clickhouses()
    {
        return $this->hasManyThrough(StandaloneClickhouse::class, Environment::class);
    }

    public function mongodbs()
    {
        return $this->hasManyThrough(StandaloneMongodb::class, Environment::class);
    }

    public function mysqls()
    {
        return $this->hasManyThrough(StandaloneMysql::class, Environment::class);
    }

    public function mariadbs()
    {
        return $this->hasManyThrough(StandaloneMariadb::class, Environment::class);
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

    public function databases(array $with = []): Collection
    {
        return $this->postgresqls()->with($with)->get()
            ->concat($this->redis()->with($with)->get())
            ->concat($this->mongodbs()->with($with)->get())
            ->concat($this->mysqls()->with($with)->get())
            ->concat($this->mariadbs()->with($with)->get())
            ->concat($this->keydbs()->with($with)->get())
            ->concat($this->dragonflies()->with($with)->get())
            ->concat($this->clickhouses()->with($with)->get());
    }

    public function navigateTo()
    {
        return route('project.show', ['project_uuid' => $this->uuid]);
    }
}
