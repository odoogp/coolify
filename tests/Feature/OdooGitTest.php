<?php

use App\Jobs\RestartOdooBranchJob;
use App\Jobs\SyncOdooAddonsJob;
use App\Livewire\Project\Edit;
use App\Livewire\Project\Service\Heading;
use App\Models\Service;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\OdooGit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

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

it('creates the project repository and makes each environment its own branch', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = \App\Models\PrivateKey::create([
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

    \Illuminate\Support\Facades\Http::fake(function ($request) {
        $url = $request->url();
        $method = strtoupper($request->method());
        $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
        if (str_contains($url, '/zen')) {
            return \Illuminate\Support\Facades\Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return \Illuminate\Support\Facades\Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/app/installations/')) {
            return \Illuminate\Support\Facades\Http::response([
                'account' => ['login' => 'acme', 'type' => 'Organization'],
            ], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/orgs/acme/repos')) {
            return \Illuminate\Support\Facades\Http::response([
                'id' => 99,
                'full_name' => 'acme/mi-empresa',
                'default_branch' => 'main',
            ], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/main')) {
            return \Illuminate\Support\Facades\Http::response(['object' => ['sha' => 'abc123']], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/git/refs')) {
            return \Illuminate\Support\Facades\Http::response(['ref' => 'refs/heads/created'], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/')) {
            return \Illuminate\Support\Facades\Http::response(['message' => 'Not Found'], 404, $date);
        }
        if (str_contains($url, '/repos/acme/')) {
            return \Illuminate\Support\Facades\Http::response([
                'id' => 99,
                'full_name' => 'acme/mi-empresa',
                'default_branch' => 'main',
            ], 200, $date);
        }

        return \Illuminate\Support\Facades\Http::response(['message' => 'unexpected '.$url], 500, $date);
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
        ->and($production->fresh()->odooBranch->git_branch)->toBe('production')
        ->and($secondStaging->name)->toBe('staging-2')
        ->and($secondStaging->fresh()->odooBranch->git_branch)->toBe('staging-2')
        ->and($this->project->environments()->count())->toBe($before);

    $created = \Illuminate\Support\Facades\Http::recorded(
        fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/orgs/acme/repos')
    );
    expect($created)->toHaveCount(1)
        ->and($created[0][0]->data()['name'])->toBe('mi-empresa');
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
