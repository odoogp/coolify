<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ProcessStatus;
use App\Jobs\DeleteResourceJob;
use App\Jobs\RestartOdooBranchJob;
use App\Jobs\SyncOdooAddonsJob;
use App\Livewire\Project\AddEmpty;
use App\Livewire\Project\DeleteEnvironment;
use App\Livewire\Project\Edit;
use App\Livewire\Project\Service\Heading;
use App\Livewire\Project\Show;
use App\Livewire\Settings\Odoo;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GpshNotice;
use App\Models\GpshOwnerModule;
use App\Models\InstanceSettings;
use App\Models\OdooEnvironmentBranch;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use App\Support\OdooMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->project->enableOdoo('20', 3, false);
    $this->project->createNextStagingEnvironment();
    $this->project->createNextStagingEnvironment();

    $this->githubApp = GithubApp::create([
        'name' => 'Acme GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 123,
        'installation_id' => 456,
        'team_id' => $this->team->id,
        'is_public' => false,
    ]);
});

it('stores each github branch without renaming production or staging', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $otherStaging = $this->project->environments()->where('name', 'staging-2')->first();

    OdooGit::assign(
        $this->project,
        $this->githubApp,
        'acme/odoo',
        99,
        ['main', 'develop', 'feature/nueva-facturacion'],
        [
            $production->id => 'main',
            $staging->id => 'develop',
            $otherStaging->id => 'feature/nueva-facturacion',
        ],
    );

    expect($production->fresh()->name)->toBe('production')
        ->and($staging->fresh()->name)->toBe('staging-1')
        ->and($otherStaging->fresh()->name)->toBe('staging-2')
        ->and($production->fresh()->odooBranch->git_branch)->toBe('main')
        ->and($staging->fresh()->odooBranch->git_branch)->toBe('develop')
        ->and($otherStaging->fresh()->odooBranch->git_branch)->toBe('feature/nueva-facturacion')
        ->and($this->project->odooProfile->fresh()->git_repository)->toBe('acme/odoo')
        ->and(Application::query()->count())->toBe(0);
});

it('rejects a branch name that is not the github branch', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $otherStaging = $this->project->environments()->where('name', 'staging-2')->first();

    expect(fn () => OdooGit::assign(
        $this->project,
        $this->githubApp,
        'acme/odoo',
        99,
        ['main', 'develop'],
        [
            $production->id => 'produccion',
            $staging->id => 'develop',
            $otherStaging->id => 'main',
        ],
    ))->toThrow(InvalidArgumentException::class);

    expect($production->fresh()->name)->toBe('production')
        ->and(OdooEnvironmentBranch::query()->count())->toBe(0)
        ->and($this->project->odooProfile->fresh()->git_repository)->toBeNull();
});

it('rejects using the same github branch on two environments', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $otherStaging = $this->project->environments()->where('name', 'staging-2')->first();

    expect(fn () => OdooGit::assign(
        $this->project,
        $this->githubApp,
        'acme/odoo',
        99,
        ['main', 'develop'],
        [
            $production->id => 'main',
            $staging->id => 'develop',
            $otherStaging->id => 'develop',
        ],
    ))->toThrow(InvalidArgumentException::class);
});

it('does not let a member save github branches', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('odooGithubAppId', $this->githubApp->id)
        ->set('odooRepositoryId', 99)
        ->call('saveOdooGit')
        ->assertDispatched('error')
        ->call('connectOdooGithub')
        ->assertDispatched('error');

    expect(OdooEnvironmentBranch::query()->count())->toBe(0)
        ->and(Application::query()->count())->toBe(0);
});

it('starts the github app install when the account is not connected', function () {
    $component = Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->assertSet('odooGithubConnected', false)
        ->assertSee('Connect your GitHub account')
        ->assertSee('Connect GitHub')
        ->assertSee('Launch environment')
        ->assertSee('JupyterLab')
        ->call('connectOdooGithub');

    $created = GithubApp::query()->where('team_id', $this->team->id)->latest('id')->first();

    $component->assertRedirect(route('source.github.show', ['github_app_uuid' => $created->uuid]));

    expect($created->id)->not->toBe($this->githubApp->id)
        ->and($created->installation_id)->toBeNull()
        ->and($created->private_key_id)->toBeNull()
        ->and(session('from.back'))->toBe('project.edit')
        ->and(session('from.source_id'))->toBe($created->id)
        ->and(session('from.parameters.project_uuid'))->toBe($this->project->uuid)
        ->and(Application::query()->count())->toBe(0);
});

it('reuses the github account that already has a key and a webhook', function () {
    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) str()->uuid(),
        'name' => 'odoo-github',
        'private_key' => 'test-key',
        'is_git_related' => true,
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->githubApp->update([
        'private_key_id' => $keyId,
        'webhook_secret' => 'odoo-hook',
    ]);

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->assertSet('odooGithubConnected', true)
        ->assertSet('odooGithubAppId', $this->githubApp->id)
        ->assertSee('Connected to GitHub')
        ->assertSee('The first launch creates the GitHub repository. The branch name is the environment name.')
        ->assertSee('Launch environment')
        ->assertSee('JupyterLab')
        ->assertDontSee('Connect GitHub');
});

it('marks only the matching github branch as updating when the webhook arrives', function () {
    Queue::fake();
    $this->githubApp->update(['webhook_secret' => 'odoo-hook']);
    $production = $this->project->environments()->where('name', 'production')->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $otherStaging = $this->project->environments()->where('name', 'staging-2')->first();
    OdooGit::assign(
        $this->project,
        $this->githubApp,
        'acme/odoo',
        99,
        ['main', 'develop', 'feature/nueva-facturacion'],
        [
            $production->id => 'main',
            $staging->id => 'develop',
            $otherStaging->id => 'feature/nueva-facturacion',
        ],
    );

    $payload = json_encode([
        'ref' => 'refs/heads/develop',
        'repository' => ['id' => 99],
        'after' => 'abc123',
        'commits' => [['message' => 'update addons']],
    ]);
    $response = $this->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'push',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => (string) $this->githubApp->app_id,
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'odoo-hook'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk();
    expect($response->getContent())->toContain("Odoo branch 'develop' is updating.");
    expect($staging->odooBranch->fresh()->status)->toBe('updating');
    expect($production->odooBranch->fresh()->status)->toBe('idle');
    expect($otherStaging->odooBranch->fresh()->status)->toBe('idle');
    Queue::assertPushed(SyncOdooAddonsJob::class, fn (SyncOdooAddonsJob $job): bool => $job->odooEnvironmentBranchId === $staging->odooBranch->id);
});

it('returns the branch to idle after the odoo restart finishes', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $row = OdooEnvironmentBranch::query()->create([
        'environment_id' => $production->id,
        'git_branch' => 'main',
        'status' => 'updating',
    ]);

    (new RestartOdooBranchJob($row->id))->handle();

    expect($row->fresh()->status)->toBe('idle');
});

it('keeps jupyter on when odoo starts without a repository', function () {
    $environment = $this->project->environments()->where('name', 'production')->first();
    $odoo = Service::factory()->create([
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
        'jupyter_enabled' => false,
    ]);
    $other = Service::factory()->create([
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  ghost:\n    image: ghost:5\n",
        'jupyter_enabled' => false,
    ]);

    OdooGit::ensureLaunchAllowed($odoo);
    OdooGit::ensureLaunchAllowed($other);

    expect($odoo->fresh()->jupyter_enabled)->toBeTrue()
        ->and($other->fresh()->jupyter_enabled)->toBeFalse();
});

it('launches an environment without github and leaves the addon files to jupyter', function () {
    $before = $this->project->environments()->count();

    $staging = OdooGit::launchLocalEnvironment($this->project, 'staging');

    expect($staging->name)->toBe('staging-1')
        ->and($staging->odooBranch)->toBeNull()
        ->and($staging->services()->count())->toBe(0)
        ->and($this->project->environments()->count())->toBe($before)
        ->and($this->project->odooProfile->git_repository)->toBeNull()
        ->and(Application::query()->count())->toBe(0);
});

it('stops when github is rate limited instead of calling the api again', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = PrivateKey::create([
        'name' => 'odoo-github-app',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);
    $this->project->update(['name' => 'Mi Empresa']);

    $urls = [];
    Http::fake(function ($request) use (&$urls) {
        $urls[] = $request->url();
        $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
        if (str_contains($request->url(), '/zen')) {
            return Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($request->url(), '/access_tokens')) {
            return Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($request->url(), '/app/installations/')) {
            return Http::response([
                'account' => ['login' => 'acme', 'type' => 'Organization'],
            ], 200, $date);
        }
        if (strtoupper($request->method()) === 'POST' && str_contains($request->url(), '/orgs/acme/repos')) {
            return Http::response(['message' => 'Rate Limit Exceeded'], 403, $date);
        }

        return Http::response(['message' => 'unexpected'], 500, $date);
    });

    expect(fn () => OdooGit::launchEnvironment($this->project, $this->githubApp, 'production'))
        ->toThrow(RuntimeException::class, 'GitHub asked to slow down. The hourly limit is still available. Wait a minute and try again.');

    expect(collect($urls)->contains(fn (string $url): bool => str_contains($url, '/repos/acme/')))->toBeFalse();
});

it('creates the project repository and makes each environment its own branch', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = PrivateKey::create([
        'name' => 'odoo-github-app',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);
    $this->project->update(['name' => 'Mi Empresa']);
    $before = $this->project->environments()->count();

    Http::fake(function ($request) {
        $url = $request->url();
        $method = strtoupper($request->method());
        $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
        if (str_contains($url, '/zen')) {
            return Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/app/installations/')) {
            return Http::response([
                'account' => ['login' => 'acme', 'type' => 'Organization'],
            ], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/orgs/acme/repos')) {
            return Http::response([
                'id' => 99,
                'full_name' => 'acme/mi-empresa',
                'default_branch' => 'main',
            ], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/main')) {
            return Http::response(['object' => ['sha' => 'abc123']], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/git/refs')) {
            return Http::response(['ref' => 'refs/heads/created'], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/')) {
            return Http::response(['message' => 'Not Found'], 404, $date);
        }
        if (str_contains($url, '/repos/acme/')) {
            return Http::response([
                'id' => 99,
                'full_name' => 'acme/mi-empresa',
                'default_branch' => 'main',
            ], 200, $date);
        }

        return Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    $staging = OdooGit::launchEnvironment($this->project, $this->githubApp, 'staging');

    expect(OdooGit::repositoryName($this->project))->toBe('mi-empresa')
        ->and($staging->name)->toBe('staging-1')
        ->and($staging->fresh()->odooBranch->git_branch)->toBe('staging-1')
        ->and($staging->services()->count())->toBe(0)
        ->and($this->project->environments()->count())->toBe($before)
        ->and($this->project->odooProfile->fresh()->git_repository)->toBe('acme/mi-empresa')
        ->and(Application::query()->count())->toBe(0);

    $production = OdooGit::launchEnvironment($this->project, $this->githubApp, 'production');
    $secondStaging = OdooGit::launchEnvironment($this->project, $this->githubApp, 'staging');

    expect($production->name)->toBe('production')
        ->and($production->fresh()->odooBranch->git_branch)->toBe('main')
        ->and($secondStaging->name)->toBe('staging-2')
        ->and($secondStaging->fresh()->odooBranch->git_branch)->toBe('staging-2')
        ->and($this->project->environments()->count())->toBe($before);

    $created = Http::recorded(
        fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/orgs/acme/repos')
    );
    expect($created)->toHaveCount(1)
        ->and($created[0][0]->data()['name'])->toBe('mi-empresa');
});

it('opens jupyter outside the platform and keeps odoo logs on the panel', function () {
    $environment = $this->project->environments()->where('name', 'production')->first();
    $odoo = Service::factory()->create([
        'environment_id' => $environment->id,
        'jupyter_enabled' => true,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $odoo->id,
        'name' => 'jupyter',
        'human_name' => 'Jupyter',
        'image' => 'jupyter/datascience-notebook:latest',
        'fqdn' => 'https://jupyter.example.test',
    ]);
    $odoo->environment_variables()->create([
        'key' => 'SERVICE_PASSWORD_JUPYTER',
        'value' => 'labtoken',
        'is_preview' => false,
    ]);

    Livewire::test(Heading::class, [
        'service' => $odoo,
        'parameters' => [],
        'query' => [],
    ])->assertSee('Open Jupyter')
        ->assertSee('https://jupyter.example.test?token=labtoken')
        ->assertSee('Logs')
        ->assertDontSee('Owner Jupyter')
        ->assertDontSee('Open service links')
        ->assertDontSee('The latest configuration has not been applied');

    expect(file_get_contents(resource_path('views/livewire/project/service/heading.blade.php')))
        ->toContain('name="external-link"')
        ->toContain("{{ __('Open Jupyter') }}")
        ->toContain("{{ __('Logs') }}");
});

it('shows owner jupyter to an instance admin and not to a client', function () {
    $environment = $this->project->environments()->where('name', 'production')->first();
    $odoo = Service::factory()->create([
        'environment_id' => $environment->id,
        'jupyter_enabled' => true,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    ServiceApplication::create([
        'service_id' => $odoo->id,
        'name' => 'jupyterowner',
        'human_name' => 'Owner Jupyter',
        'image' => 'jupyter/datascience-notebook:latest',
        'fqdn' => 'https://jupyterowner.example.test',
    ]);
    $odoo->environment_variables()->create([
        'key' => 'SERVICE_PASSWORD_JUPYTEROWNER',
        'value' => 'ownertoken',
        'is_preview' => false,
    ]);

    Livewire::test(Heading::class, [
        'service' => $odoo,
        'parameters' => [],
        'query' => [],
    ])->assertDontSee('Owner Jupyter');

    $rootTeam = Team::factory()->make(['name' => 'Root jupyter']);
    $rootTeam->id = 0;
    $rootTeam->save();
    $this->user->teams()->attach($rootTeam->id, ['role' => 'admin']);
    $this->actingAs($this->user->fresh());

    Livewire::test(Heading::class, [
        'service' => $odoo->fresh(),
        'parameters' => [],
        'query' => [],
    ])->assertSee('Owner Jupyter')
        ->assertSee(route('gpsh.owner-jupyter'))
        ->assertDontSee('https://jupyterowner.example.test?token=ownertoken');
});

it('opens grafana for the odoo and postgresql containers only', function () {
    $environment = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'human_name' => 'Odoo',
        'image' => 'odoo:20',
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'jupyter',
        'human_name' => 'Jupyter',
        'image' => 'jupyter/datascience-notebook:latest',
    ]);
    ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'postgresql',
        'human_name' => 'PostgreSQL',
        'image' => 'postgres:16-alpine',
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'monitor',
        'human_name' => 'Monitor',
        'image' => 'grafana/grafana-oss',
        'fqdn' => 'https://monitor.example.test',
    ]);

    $expected = OdooMonitor::urlFor($service->fresh());

    Livewire::test(Heading::class, [
        'service' => $service->fresh(),
        'parameters' => [],
        'query' => [],
    ])->assertSee($expected)
        ->assertDontSee('jupyter-'.$service->uuid);
});

it('shows editor, monitor, and odoo log icons on the project environments', function () {
    $environment = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'jupyter_enabled' => true,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'human_name' => 'Odoo',
        'image' => 'odoo:20',
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'jupyter',
        'human_name' => 'Jupyter',
        'image' => 'jupyter/datascience-notebook:latest',
        'fqdn' => 'https://jupyter.example.test',
    ]);
    ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'postgresql',
        'human_name' => 'PostgreSQL',
        'image' => 'postgres:16-alpine',
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'monitor',
        'human_name' => 'Monitor',
        'image' => 'grafana/grafana-oss',
        'fqdn' => 'https://monitor.example.test',
    ]);
    $service->environment_variables()->create([
        'key' => 'SERVICE_PASSWORD_JUPYTER',
        'value' => 'labtoken',
        'is_preview' => false,
    ]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertSee('jupyter.example.test?token=labtoken')
        ->assertSee('monitor.example.test')
        ->assertSee('only=odoo')
        ->assertSee('Editor')
        ->assertSee('Monitor')
        ->assertSee('Logs')
        ->assertSee('Terminal')
        ->assertSee('shell=odoo')
        ->assertDontSee('Owner Jupyter');

    expect(file_get_contents(resource_path('views/livewire/project/show.blade.php')))
        ->toContain('environment-shortcuts')
        ->and(substr_count(file_get_contents(resource_path('views/livewire/project/show.blade.php')), 'environment-shortcuts'))->toBe(2)
        ->and(file_get_contents(resource_path('views/livewire/project/environment-shortcuts.blade.php')))
        ->toContain('group-hover/tool')
        ->toContain("{{ __('Editor') }}")
        ->toContain("{{ __('Monitor') }}")
        ->toContain("{{ __('Logs') }}")
        ->toContain('name="code"')
        ->toContain('name="dashboard"')
        ->toContain('name="file-content"')
        ->toContain('M6.75 8.25 10.75 12 6.75 15.75');
});

it('lets the instance owner add a module that the next start links read-only', function () {
    $rootTeam = Team::factory()->make(['name' => 'Root modules']);
    $rootTeam->id = 0;
    $rootTeam->save();
    $this->user->teams()->attach($rootTeam->id, ['role' => 'owner']);
    $this->actingAs($this->user->fresh());

    Livewire::test(Odoo::class)
        ->set('moduleName', 'sale_owner')
        ->call('addModule');

    expect(GpshOwnerModule::names())->toBe(['sale_owner']);

    $command = OdooJupyter::launchCommand('mi_empresa_production', '', '', '', GpshOwnerModule::names());

    expect($command)->toContain('for module in sale_owner;');

    Livewire::test(Odoo::class)
        ->call('removeModule', 'sale_owner');

    expect(GpshOwnerModule::names())->toBe([]);
});

it('does not ask for the branch or github while deploying', function () {
    $environment = $this->project->environments()->where('name', 'production')->first();
    $odoo = Service::factory()->create([
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);

    Livewire::test(Heading::class, [
        'service' => $odoo,
        'parameters' => [],
        'query' => [],
    ])->call('start')
        ->assertDispatched('error')
        ->assertDontSee('Launch environment');

    expect(GithubApp::query()->where('team_id', $this->team->id)->count())->toBe(1)
        ->and($this->project->environments()->count())->toBe(3);
});

it('associates an existing repository with one environment and keeps the others free', function () {
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $production = $this->project->environments()->where('name', 'production')->first();

    OdooGit::attachExisting($this->project, $this->githubApp, 'acme/odoo', 99, ['main', 'develop'], $staging, 'develop');

    expect($staging->fresh()->odooBranch->git_branch)->toBe('develop')
        ->and($this->project->odooProfile->fresh()->git_repository)->toBe('acme/odoo')
        ->and($production->fresh()->odooBranch)->toBeNull()
        ->and(Application::query()->count())->toBe(0);

    expect(fn () => OdooGit::attachExisting($this->project, $this->githubApp, 'acme/odoo', 99, ['main', 'develop'], $production, 'develop'))
        ->toThrow(InvalidArgumentException::class);
});

it('shows the repository choice on the odoo service page', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));

    expect($view)
        ->toContain('Launch production on a new repository')
        ->toContain('Use an existing repository')
        ->toContain('associateOdooRepository')
        ->toContain('launchWithoutGithub')
        ->toContain('Open on GitHub')
        ->toContain('odooAccountChanged')
        ->toContain('odooRepositoryQuery')
        ->toContain('reloadOdooRepositories')
        ->not->toContain('Load repositories')
        ->not->toContain('Subscription Code')
        ->not->toContain('odoo-service-branch')
        ->not->toContain('Clone to staging')
        ->toContain('$project->uuid')
        ->not->toContain("request()->route('project_uuid')");
});

it('clones the repository branch into the addon volume jupyter shows', function () {
    $commands = OdooGit::cloneCommands('svc_odoo-extra-addons', 'https://example.test/acme/odoo.git', 'production');
    $script = implode("\n", $commands);

    expect($script)->toContain('git clone')
        ->and($script)->toContain('production')
        ->and($script)->toContain('svc_odoo-extra-addons')
        ->and($script)->toContain('alpine/git')
        ->and($script)->toContain('/addons');
});

it('opens the working repository on github', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    OdooGit::attachExisting($this->project, $this->githubApp, 'acme/odoo', 99, ['main'], $production, 'main');

    expect($production->fresh()->name)->toBe('production')
        ->and($production->fresh()->odooBranch->git_branch)->toBe('main')
        ->and(OdooGit::repositoryUrl('acme/odoo', $production->fresh()->odooBranch->git_branch))->toBe('https://github.com/acme/odoo/tree/main')
        ->and(OdooGit::repositoryUrl('acme/odoo', 'feature/pay'))->toBe('https://github.com/acme/odoo/tree/feature/pay')
        ->and(OdooGit::repositoryUrl('not a repo', 'main'))->toBe('')
        ->and(file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php')))
        ->toContain('GitHub branch :branch.')
        ->toContain('loadLinkedOdooBranches')
        ->not->toContain('git_branch ?: $environment->name');

    expect(fn () => OdooGit::attachExisting($this->project, $this->githubApp, 'acme/odoo', 99, ['main'], $production, 'production'))
        ->toThrow(InvalidArgumentException::class, 'Use the branch name from GitHub, not the environment name.');
});

it('lets an admin change the github account and keeps members out', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    expect(OdooGit::beginConnect($this->project)->team_id)->toBe($this->team->id);

    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);

    expect(fn () => OdooGit::beginConnect($this->project))->toThrow(HttpException::class);
});

it('starts a new odoo project in stages and reuses an installed github app', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = PrivateKey::create([
        'name' => 'odoo-installed-app',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update(['private_key_id' => $privateKey->id, 'webhook_secret' => null]);

    expect(OdooGit::configuredAppName())->toBe('gpsh1')
        ->and(OdooGit::installedApp($this->team->id, $this->user->id)?->is($this->githubApp))->toBeTrue()
        ->and(file_get_contents(app_path('Jobs/LaunchOdooProjectJob.php')))->toContain('StartService::run')
        ->and(file_get_contents(resource_path('views/livewire/project/add-empty.blade.php')))->toContain('Starting the containers')
        ->and(file_get_contents(resource_path('views/livewire/project/add-empty.blade.php')))->toContain('serverId')
        ->and(file_get_contents(resource_path('views/livewire/project/add-empty.blade.php')))->toContain('Search services')
        ->and(file_get_contents(resource_path('views/livewire/settings/github.blade.php')))->toContain('github_app_icon');
});

it('creates the odoo service after github repositories are installed', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $environment = $this->project->environments()->where('name', 'production')->first();

    $response = $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $environment->uuid,
        'launch' => 'choose',
    ]));

    $service = Service::query()->where('environment_id', $environment->id)->where('service_type', 'odoo')->first();
    expect($service)->not->toBeNull()
        ->and($service->server_id)->toBe($server->id);
    $response->assertRedirect(route('project.service.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
        'launch' => 'choose',
    ]));
});

it('asks for a server before a project when the coolify host is not allowed', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(AddEmpty::class)
        ->assertSee('Do you want to create a server?')
        ->assertDontSee('Launch on the server where GPSH is installed')
        ->set('name', 'Sin servidor')
        ->set('service', 'odoo')
        ->call('submit');

    expect(Project::query()->where('name', 'Sin servidor')->exists())->toBeFalse();
});

it('offers the local server to an admin who is allowed to launch there', function () {
    $instanceTeam = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $instanceTeam->id]);
    Server::unguarded(fn () => Server::query()->forceCreate([
        'id' => 0,
        'name' => 'localhost',
        'ip' => '127.0.0.1',
        'user' => 'root',
        'port' => 22,
        'team_id' => $instanceTeam->id,
        'private_key_id' => $key->id,
    ]));
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, [
        'role' => 'admin',
        'can_launch_on_instance_server' => true,
        'can_add_servers' => true,
    ]);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(AddEmpty::class)
        ->assertSee('Launch on the server where GPSH is installed')
        ->assertSee('Create a new server')
        ->assertDontSee('Do you want to create a server?');
});

it('returns from github to the project so the repository can be chosen', function () {
    session([
        'from' => [
            'odoo' => true,
            'back' => 'project.service.configuration',
            'source_id' => 4,
            'parameters' => [
                'project_uuid' => 'project-uuid',
                'environment_uuid' => 'environment-uuid',
                'service_uuid' => 'service-uuid',
            ],
        ],
    ]);

    $response = OdooGit::resumeLaunchRedirect();

    expect(session('from'))->toBeNull()
        ->and($response->getTargetUrl())->toContain('launch=choose')
        ->and($response->getTargetUrl())->toContain('service-uuid');
});

it('names a new github app after the product and keeps it on the user', function () {
    $app = OdooGit::beginConnect($this->project);

    expect($app->name)->toBe('gpsh1')
        ->and(session('from.odoo'))->toBeTrue()
        ->and(OdooGit::beginConnect($this->project)->is($app))->toBeTrue()
        ->and(GithubApp::query()->where('team_id', $this->team->id)->where('name', 'like', 'gpsh%')->count())->toBe(1);

    OdooGit::rememberForUser($this->user->id, $this->team->id, $app);

    expect(DB::table('team_user')
        ->where('user_id', $this->user->id)
        ->where('team_id', $this->team->id)
        ->value('github_app_id'))->toBe($app->id);
});

it('clones the production branch onto a different branch and asks github for one token', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = PrivateKey::create([
        'name' => 'odoo-clone-key',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        $method = strtoupper($request->method());
        $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
        if (str_contains($url, '/zen')) {
            return Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/production')) {
            return Http::response(['object' => ['sha' => 'prodsha']], 200, $date);
        }
        if (str_contains($url, '/git/ref/heads/')) {
            return Http::response(['message' => 'Not Found'], 404, $date);
        }
        if ($method === 'POST' && str_contains($url, '/git/refs')) {
            return Http::response(['ref' => 'refs/heads/staging-3'], 201, $date);
        }

        return Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    OdooGit::cloneBranch($this->githubApp, 'acme/mi-empresa', 'production', 'staging-3');

    $posted = Http::recorded(
        fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/git/refs')
    );
    $tokens = Http::recorded(
        fn ($request) => str_contains($request->url(), '/access_tokens')
    );
    expect($posted)->toHaveCount(1)
        ->and($posted[0][0]->data()['ref'])->toBe('refs/heads/staging-3')
        ->and($posted[0][0]->data()['sha'])->toBe('prodsha')
        ->and($tokens->count())->toBeLessThan(2);
});

it('rejects a staging branch that is already used', function () {
    expect(fn () => OdooGit::prepareStagingBranch($this->githubApp, 'acme/odoo', 'production', 'main', ['main']))
        ->toThrow(InvalidArgumentException::class, 'That branch is already used by this repository.');
});

it('starts a new staging branch from the repository default when production is missing', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = PrivateKey::create([
        'name' => 'odoo-stage-key',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        $method = strtoupper($request->method());
        $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
        if (str_contains($url, '/zen')) {
            return Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/main')) {
            return Http::response(['object' => ['sha' => 'defaultsha']], 200, $date);
        }
        if (str_contains($url, '/git/ref/heads/')) {
            return Http::response(['message' => 'Not Found'], 404, $date);
        }
        if ($method === 'GET' && str_contains($url, '/repos/acme/odoo')) {
            return Http::response(['default_branch' => 'main'], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/git/refs')) {
            return Http::response(['ref' => 'refs/heads/staging-1'], 201, $date);
        }

        return Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    OdooGit::prepareStagingBranch($this->githubApp, 'acme/odoo', 'production', 'staging-1', ['main']);

    $posted = Http::recorded(
        fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/git/refs')
    );
    expect($posted)->toHaveCount(1)
        ->and($posted[0][0]->data()['ref'])->toBe('refs/heads/staging-1')
        ->and($posted[0][0]->data()['sha'])->toBe('defaultsha');
});

it('loads one page of repositories and shows the github error', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = PrivateKey::create([
        'name' => 'odoo-repos-key',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);

    $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
    Http::fake(function ($request) use ($date) {
        $url = $request->url();
        if (str_contains($url, '/zen')) {
            return Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/installation/repositories')) {
            return Http::response(['message' => 'Bad credentials'], 401, $date);
        }

        return Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    expect(fn () => OdooGit::repositories($this->githubApp))->toThrow(RuntimeException::class, 'Bad credentials');

    Http::fake(function ($request) use ($date) {
        $url = $request->url();
        if (str_contains($url, '/zen')) {
            return Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/installation/repositories')) {
            return Http::response([
                'total_count' => 1,
                'repositories' => [[
                    'id' => 7,
                    'name' => 'odoo',
                    'default_branch' => 'main',
                    'owner' => ['login' => 'acme'],
                ]],
            ], 200, $date);
        }

        return Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    $repositories = OdooGit::repositories($this->githubApp);
    $pages = Http::recorded(
        fn ($request) => str_contains($request->url(), '/installation/repositories')
    );

    expect($repositories)->toHaveCount(1)
        ->and($repositories[0]['full_name'])->toBe('acme/odoo')
        ->and($pages)->toHaveCount(1);
});

it('creates the project repository without registering another github app', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = PrivateKey::create([
        'name' => 'odoo-existing-app',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);
    OdooGit::rememberForUser($this->user->id, $this->team->id, $this->githubApp);
    Cache::flush();

    $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
    Http::fake(function ($request) use ($date) {
        $url = $request->url();
        $method = strtoupper($request->method());
        if (str_contains($url, '/zen')) {
            return Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/app/installations/')) {
            return Http::response([
                'account' => ['login' => 'acme', 'type' => 'Organization'],
            ], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/orgs/acme/repos')) {
            return Http::response([
                'id' => 44,
                'full_name' => 'acme/cliente-dos',
                'default_branch' => 'main',
            ], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/main')) {
            return Http::response(['object' => ['sha' => 'abc123']], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/git/refs')) {
            return Http::response(['ref' => 'refs/heads/production'], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/')) {
            return Http::response(['message' => 'Not Found'], 404, $date);
        }
        if (str_contains($url, '/repos/acme/')) {
            return Http::response([
                'id' => 44,
                'full_name' => 'acme/cliente-dos',
                'default_branch' => 'main',
            ], 200, $date);
        }

        return Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    $before = GithubApp::query()->count();
    Livewire::test(AddEmpty::class)
        ->set('name', 'Cliente Dos')
        ->set('description', 'demo')
        ->set('service', 'odoo')
        ->set('odooVersion', '20')
        ->set('connectGithub', true)
        ->call('submit')
        ->assertRedirect();

    $project = Project::query()->where('name', 'Cliente Dos')->first();
    expect(GithubApp::query()->count())->toBe($before)
        ->and($project->odooProfile->git_repository)->toBeNull()
        ->and($project->odooProfile->github_app_id)->toBeNull();
});

it('keeps the project and waits to choose a repository when github is already connected', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = PrivateKey::create([
        'name' => 'odoo-existing-app',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);
    OdooGit::rememberForUser($this->user->id, $this->team->id, $this->githubApp);
    Cache::flush();

    $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
    Http::fake(function ($request) use ($date) {
        $url = $request->url();
        if (str_contains($url, '/zen')) {
            return Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/app/installations/')) {
            return Http::response([
                'account' => ['login' => 'acme', 'type' => 'Organization'],
            ], 200, $date);
        }
        if (strtoupper($request->method()) === 'POST' && str_contains($url, '/orgs/acme/repos')) {
            return Http::response(['message' => 'Rate Limit Exceeded'], 403, $date);
        }

        return Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    $before = Project::query()->count();
    Livewire::test(AddEmpty::class)
        ->set('name', 'No Debe Quedar')
        ->set('description', 'demo')
        ->set('service', 'odoo')
        ->set('odooVersion', '20')
        ->set('connectGithub', true)
        ->call('submit')
        ->assertRedirect();

    $project = Project::query()->where('name', 'No Debe Quedar')->first();
    expect($project)->not->toBeNull()
        ->and($project->odooProfile->git_repository)->toBeNull()
        ->and(Project::query()->count())->toBe($before + 1);
});

it('names the database after the project and the branch', function () {
    $this->project->update(['name' => 'Mi Empresa']);
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);

    expect(OdooGit::databaseName($service))->toBe('mi_empresa_production');

    $staging = $this->project->environments()->where('name', '!=', 'production')->first();
    $copied = Service::factory()->create([
        'environment_id' => $staging->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    $copied->environment_variables()->createMany([
        ['key' => 'ODOO_DATABASE', 'value' => 'mi_empresa_production', 'is_preview' => false],
        ['key' => 'ODOO_LOGIN_TOKEN', 'value' => 'productiontokenproductiontokenproduction', 'is_preview' => false],
    ]);

    OdooGit::assignCopiedBranch($copied->fresh());

    expect($copied->environment_variables()->where('key', 'ODOO_DATABASE')->value('value'))->toBe(OdooGit::databaseName($copied->fresh()))
        ->and($copied->environment_variables()->where('key', 'ODOO_DATABASE')->value('value'))->not->toBe('mi_empresa_production')
        ->and($copied->environment_variables()->where('key', 'ODOO_LOGIN_TOKEN')->value('value'))->not->toBe('productiontokenproductiontokenproduction');

    OdooEnvironmentBranch::query()->create([
        'environment_id' => $production->id,
        'git_branch' => 'main',
    ]);

    expect(OdooGit::databaseName($service->fresh()))->toBe('mi_empresa_production')
        ->and($production->fresh()->odooBranch->git_branch)->toBe('main');
});

it('refuses a repository already used by another project', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    OdooGit::attachExisting($this->project, $this->githubApp, 'acme/odoo', 99, ['main'], $production, 'main');

    $other = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Otro']);
    $other->enableOdoo('20');
    $otherProduction = $other->environments()->where('name', 'production')->first();

    expect(fn () => OdooGit::attachExisting($other, $this->githubApp, 'acme/odoo', 99, ['main'], $otherProduction, 'main'))
        ->toThrow(InvalidArgumentException::class, 'That repository is already used by another project.');
});

it('turns an existing odoo link into https', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    $application = ServiceApplication::factory()->create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'image' => 'odoo:20',
        'fqdn' => 'http://odoo.example.test:8069',
    ]);

    expect(OdooGit::useHttps($service))->toBeTrue()
        ->and($application->fresh()->fqdn)->toBe('https://odoo.example.test')
        ->and($application->fresh()->is_force_https_enabled)->toBeTrue()
        ->and(OdooGit::classifyCertificateIssuer('TRAEFIK DEFAULT CERT', 'TRAEFIK DEFAULT CERT', ''))->toBe('pending')
        ->and(OdooGit::classifyCertificateIssuer('R10', 'odoo.example.test', "Let's Encrypt"))->toBe('applied');

    $this->project->update(['name' => 'Mi Empresa']);
    OdooGit::prepareInstance($service);

    expect(OdooGit::enterUrl($service->fresh()))->toStartWith('https://odoo.example.test/_odoo/paas/connect?token=')
        ->and(OdooGit::databaseList($service->fresh()))->toBe([
            ['name' => 'mi_empresa_production', 'disabled' => false],
        ]);

    $this->get(route('project.service.odoo.enter', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
        'service_uuid' => $service->uuid,
    ]))->assertRedirect(OdooGit::enterUrl($service->fresh()));
});

it('copies the production database and files into staging and neutralizes only that copy', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $compose = "services:\n  odoo:\n    image: odoo:20\n";
    $source = Service::factory()->create([
        'environment_id' => $production->id,
        'docker_compose_raw' => $compose,
    ]);
    $target = Service::factory()->create([
        'environment_id' => $staging->id,
        'docker_compose_raw' => $compose,
    ]);
    $sourcePassword = "s3cret\$quote'";
    $targetPassword = 'staging-secret';
    $source->environment_variables()->createMany([
        ['key' => 'ODOO_DATABASE', 'value' => 'acme_production', 'is_preview' => false],
        ['key' => 'SERVICE_USER_POSTGRES', 'value' => 'odoo', 'is_preview' => false],
        ['key' => 'SERVICE_PASSWORD_POSTGRES', 'value' => $sourcePassword, 'is_preview' => false],
    ]);
    $target->environment_variables()->createMany([
        ['key' => 'ODOO_DATABASE', 'value' => 'acme_staging_1', 'is_preview' => false],
        ['key' => 'SERVICE_USER_POSTGRES', 'value' => 'odoo', 'is_preview' => false],
        ['key' => 'SERVICE_PASSWORD_POSTGRES', 'value' => $targetPassword, 'is_preview' => false],
    ]);
    ServiceApplication::create([
        'service_id' => $target->id,
        'name' => 'odoo',
        'human_name' => 'Odoo',
        'image' => 'odoo:20',
        'fqdn' => 'https://staging.example.test',
    ]);

    $command = OdooGit::copyProductionDataCommand($source, $target);

    expect($command)->toContain('pg_dump')
        ->toContain('pick '.$source->id.' '.$source->uuid.' postgres')
        ->toContain('pick '.$target->id.' '.$target->uuid.' postgres')
        ->toContain('label=com.docker.compose.project=${project}')
        ->toContain('label=coolify.serviceId=${service_id}')
        ->toContain('docker ps -aq')
        ->toContain('*postgres*')
        ->toContain('acme_production')
        ->toContain('--no-owner')
        ->toContain(':/source:ro')
        ->toContain('id -u')
        ->toContain('chmod -R u+rwX /target')
        ->not->toContain('101:101')
        ->toContain('/var/lib/odoo')
        ->toContain('filestore/acme_production')
        ->toContain('filestore/acme_staging_1')
        ->toContain('dropdb')
        ->toContain('acme_staging_1')
        ->toContain('DROP TABLE IF EXISTS orm_signaling_registry, orm_signaling_assets')
        ->toContain('*stdlib*')
        ->toContain('neutralize -d acme_staging_1')
        ->toContain('--memory=512m')
        ->toContain('--memory=2g')
        ->toContain('Not enough free disk to clone without filling the server.')
        ->toContain('docker stop "$dst_odoo"')
        ->not->toContain('docker stop "$src_odoo"')
        ->toContain('container:$dst_pg')
        ->toContain('--db_host=127.0.0.1')
        ->toContain('--pull never')
        ->toContain('https://staging.example.test')
        ->toContain(base64_encode($sourcePassword))
        ->not->toContain($sourcePassword)
        ->not->toContain('postgresql-'.$source->uuid)
        ->not->toContain('postgresql-'.$target->uuid)
        ->not->toContain('service=postgresql')
        ->and(file_get_contents(app_path('Jobs/CloneOdooStagingJob.php')))->toContain('waitForServiceStart')
        ->and(file_get_contents(app_path('Jobs/CloneOdooStagingJob.php')))->toContain('OdooGit::copyProductionData')
        ->and(file_get_contents(app_path('Jobs/CloneOdooStagingJob.php')))->toContain('OdooGit::waitUntilOpen')
        ->and(file_get_contents(app_path('Jobs/CloneOdooStagingJob.php')))->toContain('isConfigurationChanged(true)')
        ->and(file_get_contents(app_path('Actions/Service/StartService.php')))->toContain('containersReadyCommand');

    $target->environment_variables()->where('key', 'ODOO_DATABASE')->first()->update(['value' => 'acme_production']);
    expect(fn () => OdooGit::copyProductionDataCommand($source->fresh(), $target->fresh()))
        ->toThrow(RuntimeException::class, 'The database copy refused to write production.');

    $target->environment()->associate($production);
    $target->save();
    expect(fn () => OdooGit::copyProductionDataCommand($source->fresh(), $target->fresh()))
        ->toThrow(RuntimeException::class, 'Only a staging database is neutralized.');
});

it('shows a launch or a clone on the environment row', function () {
    $environment = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    Cache::put('launch-odoo-'.$service->uuid, [
        'step' => 2,
        'done' => false,
        'error' => null,
        'redirect' => null,
    ], now()->addMinutes(30));

    $html = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])->html();

    expect($html)->toContain('Starting the containers')
        ->and($html)->not->toContain('Creating the staging');

    Cache::forget('launch-odoo-'.$service->uuid);
    Cache::put('odoo-clone-'.$this->project->id.'-'.$this->user->id, [
        'step' => 2,
        'done' => false,
        'error' => 'The staging service did not start.',
        'redirect' => null,
        'environment' => null,
    ], now()->addMinutes(30));

    $failed = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])->html();

    expect($failed)->toContain('The staging service did not start.')
        ->and($failed)->toContain('pending-clone');
});

it('shows open odoo when the odoo container is up and the rest of the stack is not', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n  jupyter:\n    image: jupyter:1\n",
    ]);
    ServiceApplication::query()->create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'image' => 'odoo:20',
        'fqdn' => 'https://odoo.example.test',
        'status' => 'running:healthy',
    ]);
    ServiceApplication::query()->create([
        'service_id' => $service->id,
        'name' => 'jupyter',
        'image' => 'jupyter:1',
        'status' => 'exited',
    ]);

    expect(OdooGit::odooIsUp($service->fresh()))->toBeTrue()
        ->and($service->fresh()->isRunning())->toBeFalse();

    $href = route('project.service.odoo.enter', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
        'service_uuid' => $service->uuid,
    ]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->assertSeeHtml($href);

    $service->applications()->where('name', 'odoo')->update(['status' => 'exited']);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->assertDontSeeHtml($href);
});

it('shows open odoo for the environment a notice points at', function () {
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $service = Service::factory()->create([
        'environment_id' => $staging->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    ServiceApplication::query()->create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'image' => 'odoo:20',
        'fqdn' => 'https://staging.example.test',
        'status' => 'exited',
    ]);

    $href = route('project.service.odoo.enter', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $staging->uuid,
        'service_uuid' => $service->uuid,
    ]);

    Livewire::withQueryParams(['environment' => $staging->uuid])
        ->test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertDontSeeHtml($href);

    GpshNotice::query()->create([
        'title' => 'Odoo is up',
        'body' => 'The containers are running.',
        'audience' => 'clients',
        'kind' => 'mounted',
        'team_id' => $this->team->id,
        'service_id' => $service->id,
    ]);

    Livewire::withQueryParams(['environment' => $staging->uuid])
        ->test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertSeeHtml($href);
});

it('sends an empty odoo link back to the project', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);

    $this->get(route('project.service.odoo.enter', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
        'service_uuid' => $service->uuid,
    ]))->assertRedirect(route('project.show', [
        'project_uuid' => $this->project->uuid,
        'environment' => $production->uuid,
    ]));
});

it('reads whether a service is starting without loading the command log', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => str_repeat('x', 5000),
        'properties' => [
            'type_uuid' => $service->uuid,
            'status' => ProcessStatus::IN_PROGRESS->value,
        ],
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($service->isStarting())->toBeTrue();
    $statusSql = collect(DB::getQueryLog())->pluck('query')->first(
        fn ($query): bool => str_contains((string) $query, 'activity_log')
    );
    DB::disableQueryLog();

    expect($statusSql)->toBeString()
        ->and($statusSql)->toContain('properties')
        ->and($statusSql)->not->toContain('description')
        ->and($statusSql)->not->toContain('*')
        ->and(RunRemoteProcess::readStatus($activity))->toBe(ProcessStatus::IN_PROGRESS->value)
        ->and(RunRemoteProcess::logContains($activity, 'The service containers are running.'))->toBeFalse();

    $activity->description = json_encode([['order' => 1, 'output' => 'The service containers are running.']], JSON_THROW_ON_ERROR);
    $activity->save();

    expect(RunRemoteProcess::logContains($activity->fresh(), 'The service containers are running.'))->toBeTrue();
});

it('deletes a loading environment and warns that production takes staging with it', function () {
    Queue::fake();
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    Cache::put('launch-odoo-'.$service->uuid, ['step' => 2, 'done' => false], 60);
    Cache::put('odoo-clone-project-'.$this->project->id, ['step' => 4, 'done' => false], 60);
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'log',
        'properties' => [
            'type_uuid' => $service->uuid,
            'status' => ProcessStatus::IN_PROGRESS->value,
        ],
    ]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->assertSee('Delete');

    Livewire::test(DeleteEnvironment::class, ['environment_id' => $production->id])
        ->assertSee('Deleting production also deletes these staging environments: staging-1, staging-2.')
        ->assertSee('Permanently delete all volumes associated with this resource.')
        ->call('delete', null, ['delete_volumes']);

    expect($this->project->environments()->pluck('name')->all())->toBe([])
        ->and(Cache::get('launch-odoo-'.$service->uuid))->toBeNull()
        ->and(Cache::get('odoo-clone-project-'.$this->project->id))->toBeNull()
        ->and(data_get($activity->fresh(), 'properties.status'))->toBe(ProcessStatus::CANCELLED->value);
    Queue::assertNotPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job): bool => $job->deleteVolumes === false);
    expect(Cache::get('gpsh-volume-owners')[$service->uuid.'_odoo-extra-addons'] ?? null)->toMatchArray([
        'client' => $this->team->name,
        'environment' => 'production',
    ]);

    $this->withoutExceptionHandling();
    expect(fn () => $this->get(route('gpsh.owner-jupyter')))
        ->toThrow(fn (HttpException $exception): bool => $exception->getStatusCode() === 403);
});

it('creates production when an odoo project has no environments', function () {
    $this->project->environments->each->delete();

    $component = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertSee('Launch without GitHub')
        ->assertSee('Connect GitHub')
        ->call('continueOdoo');

    $production = $this->project->environments()->where('name', 'production')->first();
    expect($production)->not->toBeNull();
    $component->assertRedirect(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ]));
});

it('opens the repository choice when github is already installed on a recovered project', function () {
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->githubApp->forceFill(['private_key_id' => $key->id])->save();
    $this->project->environments->each->delete();

    $component = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertSee('Launch without GitHub')
        ->call('continueOdoo');

    $production = $this->project->environments()->where('name', 'production')->first();
    $component->assertRedirect(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ]));

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('continueOdoo', true)
        ->assertRedirect(route('project.resource.index', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $production->uuid,
            'launch' => 'choose',
        ]));
});

it('tells the owner when the owner jupyter or its certificate was missing', function () {
    expect(OdooJupyter::ownerRepairMessage('gpsh-owner-status before=present running=true cert=applied', 'jupyter.example.test'))->toBeNull()
        ->and(OdooJupyter::ownerRepairMessage('gpsh-owner-status before=missing running=true cert=pending', 'jupyter.example.test'))
        ->toContain('was not running')
        ->toContain('jupyter.example.test')
        ->and(OdooJupyter::ownerRepairMessage('gpsh-owner-status before=present running=false cert=applied', 'jupyter.example.test'))
        ->toContain('did not start');
});
