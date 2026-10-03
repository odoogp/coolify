<?php

namespace App\Models;

use App\Jobs\DeleteResourceJob;
use App\Services\AdminCreationQuota;
use App\Support\OdooJupyter;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Environment model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'name' => ['type' => 'string'],
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

    protected $fillable = [
        'name',
        'description',
        'project_id',
        'uuid',
        'created_by',
    ];

    /**
     * Set by the delete dialog. Not a column.
     */
    public bool $deleteVolumesWithResources = true;

    protected static function booted()
    {
        static::creating(function (Environment $environment): void {
            app(AdminCreationQuota::class)->guardEnvironment($environment);
        });
        static::deleting(function (Environment $environment) {
            foreach ($environment->resources() as $resource) {
                if ($resource instanceof Service) {
                    OdooJupyter::rememberServiceVolumes($resource, $environment);
                }
                $resource->delete();
                DeleteResourceJob::dispatch($resource, $environment->deleteVolumesWithResources);
            }
            foreach ($environment->environment_variables as $sharedVariable) {
                $sharedVariable->delete();
            }
        });
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
