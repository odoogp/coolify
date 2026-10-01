<?php

use App\Jobs\RestartOdooBranchJob;
use App\Jobs\SyncOdooAddonsJob;
use App\Livewire\Project\AddEmpty;
use App\Livewire\Project\Edit;
use App\Livewire\Project\Service\Heading;
use App\Models\Service;
use App\Models\ServiceApplication;
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
use RuntimeException;

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

    $urls = [];
    \Illuminate\Support\Facades\Http::fake(function ($request) use (&$urls) {
        $urls[] = $request->url();
        $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
        if (str_contains($request->url(), '/zen')) {
            return \Illuminate\Support\Facades\Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($request->url(), '/access_tokens')) {
            return \Illuminate\Support\Facades\Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($request->url(), '/app/installations/')) {
            return \Illuminate\Support\Facades\Http::response([
                'account' => ['login' => 'acme', 'type' => 'Organization'],
            ], 200, $date);
        }
        if (strtoupper($request->method()) === 'POST' && str_contains($request->url(), '/orgs/acme/repos')) {
            return \Illuminate\Support\Facades\Http::response(['message' => 'Rate Limit Exceeded'], 403, $date);
        }

        return \Illuminate\Support\Facades\Http::response(['message' => 'unexpected'], 500, $date);
    });

    expect(fn () => OdooGit::launchEnvironment($this->project, $this->githubApp, 'production'))
        ->toThrow(RuntimeException::class, 'GitHub is limiting requests. Wait a few minutes and try again.');

    expect(collect($urls)->contains(fn (string $url): bool => str_contains($url, '/repos/acme/')))->toBeFalse();
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
        ->and($production->fresh()->odooBranch->git_branch)->toBe('main')
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
        ->toContain('New repository')
        ->toContain('Existing repository')
        ->toContain('associateOdooRepository')
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

    expect(fn () => OdooGit::beginConnect($this->project))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('names a new github app after the product and keeps it on the user', function () {
    $app = OdooGit::beginConnect($this->project);

    expect($app->name)->toBe('gpsh')
        ->and(session('from.odoo'))->toBeTrue()
        ->and(OdooGit::beginConnect($this->project)->name)->toBe('gpsh-2');

    OdooGit::rememberForUser($this->user->id, $this->team->id, $app);

    expect(\Illuminate\Support\Facades\DB::table('team_user')
        ->where('user_id', $this->user->id)
        ->where('team_id', $this->team->id)
        ->value('github_app_id'))->toBe($app->id);
});

it('clones the production branch onto a different branch and asks github for one token', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = \App\Models\PrivateKey::create([
        'name' => 'odoo-clone-key',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);

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
        if (str_contains($url, '/git/ref/heads/production')) {
            return \Illuminate\Support\Facades\Http::response(['object' => ['sha' => 'prodsha']], 200, $date);
        }
        if (str_contains($url, '/git/ref/heads/')) {
            return \Illuminate\Support\Facades\Http::response(['message' => 'Not Found'], 404, $date);
        }
        if ($method === 'POST' && str_contains($url, '/git/refs')) {
            return \Illuminate\Support\Facades\Http::response(['ref' => 'refs/heads/staging-3'], 201, $date);
        }

        return \Illuminate\Support\Facades\Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    OdooGit::cloneBranch($this->githubApp, 'acme/mi-empresa', 'production', 'staging-3');

    $posted = \Illuminate\Support\Facades\Http::recorded(
        fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/git/refs')
    );
    $tokens = \Illuminate\Support\Facades\Http::recorded(
        fn ($request) => str_contains($request->url(), '/access_tokens')
    );
    expect($posted)->toHaveCount(1)
        ->and($posted[0][0]->data()['ref'])->toBe('refs/heads/staging-3')
        ->and($posted[0][0]->data()['sha'])->toBe('prodsha')
        ->and($tokens->count())->toBeLessThan(2);
});

it('rejects a staging branch that is already used', function () {
    expect(fn () => OdooGit::prepareStagingBranch($this->githubApp, 'acme/odoo', 'production', 'main', ['main']))
        ->toThrow(\InvalidArgumentException::class, 'That branch is already used by this repository.');
});

it('starts a new staging branch from the repository default when production is missing', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = \App\Models\PrivateKey::create([
        'name' => 'odoo-stage-key',
        'private_key' => $pem,
        'is_git_related' => true,
        'team_id' => $this->team->id,
    ]);
    $this->githubApp->update([
        'private_key_id' => $privateKey->id,
        'webhook_secret' => 'odoo-hook',
    ]);

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
        if (str_contains($url, '/git/ref/heads/main')) {
            return \Illuminate\Support\Facades\Http::response(['object' => ['sha' => 'defaultsha']], 200, $date);
        }
        if (str_contains($url, '/git/ref/heads/')) {
            return \Illuminate\Support\Facades\Http::response(['message' => 'Not Found'], 404, $date);
        }
        if ($method === 'GET' && str_contains($url, '/repos/acme/odoo')) {
            return \Illuminate\Support\Facades\Http::response(['default_branch' => 'main'], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/git/refs')) {
            return \Illuminate\Support\Facades\Http::response(['ref' => 'refs/heads/staging-1'], 201, $date);
        }

        return \Illuminate\Support\Facades\Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    OdooGit::prepareStagingBranch($this->githubApp, 'acme/odoo', 'production', 'staging-1', ['main']);

    $posted = \Illuminate\Support\Facades\Http::recorded(
        fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/git/refs')
    );
    expect($posted)->toHaveCount(1)
        ->and($posted[0][0]->data()['ref'])->toBe('refs/heads/staging-1')
        ->and($posted[0][0]->data()['sha'])->toBe('defaultsha');
});

it('loads one page of repositories and shows the github error', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = \App\Models\PrivateKey::create([
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
    \Illuminate\Support\Facades\Http::fake(function ($request) use ($date) {
        $url = $request->url();
        if (str_contains($url, '/zen')) {
            return \Illuminate\Support\Facades\Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return \Illuminate\Support\Facades\Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/installation/repositories')) {
            return \Illuminate\Support\Facades\Http::response(['message' => 'Bad credentials'], 401, $date);
        }

        return \Illuminate\Support\Facades\Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    expect(fn () => OdooGit::repositories($this->githubApp))->toThrow(RuntimeException::class, 'Bad credentials');

    \Illuminate\Support\Facades\Http::fake(function ($request) use ($date) {
        $url = $request->url();
        if (str_contains($url, '/zen')) {
            return \Illuminate\Support\Facades\Http::response('Keep it logically awesome.', 200, $date);
        }
        if (str_contains($url, '/access_tokens')) {
            return \Illuminate\Support\Facades\Http::response(['token' => 'ghs_test'], 201, $date);
        }
        if (str_contains($url, '/installation/repositories')) {
            return \Illuminate\Support\Facades\Http::response([
                'total_count' => 1,
                'repositories' => [[
                    'id' => 7,
                    'name' => 'odoo',
                    'default_branch' => 'main',
                    'owner' => ['login' => 'acme'],
                ]],
            ], 200, $date);
        }

        return \Illuminate\Support\Facades\Http::response(['message' => 'unexpected '.$url], 500, $date);
    });

    $repositories = OdooGit::repositories($this->githubApp);
    $pages = \Illuminate\Support\Facades\Http::recorded(
        fn ($request) => str_contains($request->url(), '/installation/repositories')
    );

    expect($repositories)->toHaveCount(1)
        ->and($repositories[0]['full_name'])->toBe('acme/odoo')
        ->and($pages)->toHaveCount(1);
});

it('creates the project repository without registering another github app', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $privateKey = \App\Models\PrivateKey::create([
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
    \Illuminate\Support\Facades\Cache::flush();

    $date = ['Date' => gmdate('D, d M Y H:i:s').' GMT'];
    \Illuminate\Support\Facades\Http::fake(function ($request) use ($date) {
        $url = $request->url();
        $method = strtoupper($request->method());
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
                'id' => 44,
                'full_name' => 'acme/cliente-dos',
                'default_branch' => 'main',
            ], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/main')) {
            return \Illuminate\Support\Facades\Http::response(['object' => ['sha' => 'abc123']], 200, $date);
        }
        if ($method === 'POST' && str_contains($url, '/git/refs')) {
            return \Illuminate\Support\Facades\Http::response(['ref' => 'refs/heads/production'], 201, $date);
        }
        if (str_contains($url, '/git/ref/heads/')) {
            return \Illuminate\Support\Facades\Http::response(['message' => 'Not Found'], 404, $date);
        }
        if (str_contains($url, '/repos/acme/')) {
            return \Illuminate\Support\Facades\Http::response([
                'id' => 44,
                'full_name' => 'acme/cliente-dos',
                'default_branch' => 'main',
            ], 200, $date);
        }

        return \Illuminate\Support\Facades\Http::response(['message' => 'unexpected '.$url], 500, $date);
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
        ->and($project->odooProfile->git_repository)->toBe('acme/cliente-dos')
        ->and($project->odooProfile->github_app_id)->toBe($this->githubApp->id);
});

it('names the database after the project and the branch', function () {
    $this->project->update(['name' => 'Mi Empresa']);
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);

    expect(OdooGit::databaseName($service))->toBe('mi_empresa_production');

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
    ServiceApplication::factory()->create([
        'service_id' => $target->id,
        'name' => 'odoo',
        'image' => 'odoo:20',
        'fqdn' => 'https://staging.example.test',
    ]);

    $command = OdooGit::copyProductionDataCommand($source, $target);

    expect($command)->toContain('pg_dump')
        ->toContain('label=com.docker.compose.project='.$source->uuid)
        ->toContain('label=com.docker.compose.project='.$target->uuid)
        ->toContain('label=coolify.serviceId='.$source->id)
        ->toContain('label=coolify.serviceId='.$target->id)
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
        ->toContain('neutralize -d acme_staging_1')
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
        ->and(file_get_contents(app_path('Actions/Service/StartService.php')))->toContain('containersReadyCommand');

    $target->environment_variables()->where('key', 'ODOO_DATABASE')->first()->update(['value' => 'acme_production']);
    expect(fn () => OdooGit::copyProductionDataCommand($source->fresh(), $target->fresh()))
        ->toThrow(RuntimeException::class, 'The database copy refused to write production.');

    $target->environment()->associate($production);
    $target->save();
    expect(fn () => OdooGit::copyProductionDataCommand($source->fresh(), $target->fresh()))
        ->toThrow(RuntimeException::class, 'Only a staging database is neutralized.');
});
