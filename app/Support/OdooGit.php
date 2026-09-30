<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Service;
use App\Rules\ValidGitBranch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * GitHub is optional. Without a repository, JupyterLab is the file manager.
 * With a repository, each environment is a branch named after that environment.
 */
class OdooGit
{
    /**
     * @return list<array{id: int, full_name: string, owner: string, name: string, default_branch: string}>
     */
    public static function repositories(GithubApp $app): array
    {
        $token = generateGithubInstallationToken($app);
        $page = 1;
        $repositories = [];
        $total = 0;

        do {
            $batch = loadRepositoryByPage($app, $token, $page);
            $total = (int) ($batch['total_count'] ?? 0);
            foreach ($batch['repositories'] ?? [] as $repository) {
                $owner = (string) data_get($repository, 'owner.login');
                $name = (string) data_get($repository, 'name');
                if ($owner === '' || $name === '') {
                    continue;
                }
                $repositories[] = [
                    'id' => (int) data_get($repository, 'id'),
                    'full_name' => $owner.'/'.$name,
                    'owner' => $owner,
                    'name' => $name,
                    'default_branch' => (string) (data_get($repository, 'default_branch') ?: 'main'),
                ];
            }
            $page++;
        } while (count($repositories) < $total && $page <= 50);

        return $repositories;
    }

    /**
     * @return list<string>
     */
    public static function branchNames(GithubApp $app, string $owner, string $repo): array
    {
        $token = generateGithubInstallationToken($app);
        $page = 1;
        $names = [];

        do {
            $response = Http::GitHub($app->api_url, $token)
                ->timeout(20)
                ->retry(3, 200, throw: false)
                ->get('/repos/'.$owner.'/'.$repo.'/branches', [
                    'per_page' => 100,
                    'page' => $page,
                ]);
            if ($response->status() !== 200) {
                throw new RuntimeException((string) ($response->json('message') ?: 'GitHub branches could not be loaded.'));
            }
            $batch = collect($response->json())->pluck('name')->filter(fn ($name): bool => is_string($name) && $name !== '')->values();
            $names = array_merge($names, $batch->all());
            $count = $batch->count();
            $page++;
        } while ($count === 100 && $page <= 50);

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $githubBranches
     * @param  array<int|string, string>  $branchByEnvironmentId
     */
    public static function assign(Project $project, GithubApp $githubApp, string $gitRepository, int $repositoryId, array $githubBranches, array $branchByEnvironmentId): void
    {
        $profile = $project->odooProfile;
        if ($profile === null) {
            throw new InvalidArgumentException('Odoo is not enabled for this project.');
        }
        if ((int) $githubApp->team_id !== (int) $project->team_id && ! $githubApp->is_system_wide) {
            throw new InvalidArgumentException('This GitHub App is not available to the project team.');
        }
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $gitRepository)) {
            throw new InvalidArgumentException('Invalid GitHub repository.');
        }

        $githubBranches = array_values(array_unique(array_map(strval(...), $githubBranches)));
        $environments = $project->environments()->get()->keyBy('id');
        $required = $environments->filter(fn (Environment $environment): bool => self::tracksBranch($environment));
        $normalized = [];

        foreach ($branchByEnvironmentId as $environmentId => $branch) {
            $environment = $environments->get((int) $environmentId);
            if (! $environment instanceof Environment || ! self::tracksBranch($environment)) {
                throw new InvalidArgumentException('Only production and staging environments can track a GitHub branch.');
            }
            $branch = trim((string) $branch);
            $check = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', new ValidGitBranch]]);
            if ($check->fails()) {
                throw new InvalidArgumentException('The GitHub branch name is invalid.');
            }
            if (! in_array($branch, $githubBranches, true)) {
                throw new InvalidArgumentException('That branch does not exist on this GitHub repository. Use the branch name from GitHub, not the environment name.');
            }
            $normalized[(int) $environmentId] = $branch;
        }

        foreach ($required as $environment) {
            if (! array_key_exists($environment->id, $normalized)) {
                throw new InvalidArgumentException('Choose a GitHub branch for '.$environment->name.'.');
            }
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new InvalidArgumentException('Each environment needs its own GitHub branch.');
        }

        DB::transaction(function () use ($profile, $githubApp, $gitRepository, $repositoryId, $normalized): void {
            $profile->update([
                'github_app_id' => $githubApp->id,
                'repository_id' => $repositoryId,
                'git_repository' => $gitRepository,
            ]);
            foreach ($normalized as $environmentId => $branch) {
                OdooEnvironmentBranch::query()->updateOrCreate(
                    ['environment_id' => $environmentId],
                    ['git_branch' => $branch],
                );
            }
        });
    }

    /**
     * Point one existing environment at a branch that already exists in a repository.
     *
     * @param  list<string>  $githubBranches
     */
    public static function attachExisting(Project $project, GithubApp $githubApp, string $gitRepository, int $repositoryId, array $githubBranches, Environment $environment, string $branch): void
    {
        $profile = $project->odooProfile;
        if ($profile === null) {
            throw new InvalidArgumentException('Odoo is not enabled for this project.');
        }
        if ((int) $environment->project_id !== (int) $project->id || ! self::tracksBranch($environment)) {
            throw new InvalidArgumentException('Only production and staging environments can track a GitHub branch.');
        }
        if ((int) $githubApp->team_id !== (int) $project->team_id && ! $githubApp->is_system_wide) {
            throw new InvalidArgumentException('This GitHub App is not available to the project team.');
        }
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $gitRepository)) {
            throw new InvalidArgumentException('Invalid GitHub repository.');
        }

        $branch = trim($branch);
        $githubBranches = array_values(array_unique(array_map(strval(...), $githubBranches)));
        $check = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', new ValidGitBranch]]);
        if ($check->fails() || ! in_array($branch, $githubBranches, true)) {
            throw new InvalidArgumentException('That branch does not exist on this GitHub repository. Use the branch name from GitHub, not the environment name.');
        }

        $taken = OdooEnvironmentBranch::query()
            ->whereIn('environment_id', $project->environments()->pluck('id'))
            ->where('git_branch', $branch)
            ->where('environment_id', '!=', $environment->id)
            ->exists();
        if ($taken) {
            throw new InvalidArgumentException('Each environment needs its own GitHub branch.');
        }

        DB::transaction(function () use ($profile, $githubApp, $gitRepository, $repositoryId, $environment, $branch): void {
            $profile->update([
                'github_app_id' => $githubApp->id,
                'repository_id' => $repositoryId,
                'git_repository' => $gitRepository,
            ]);
            OdooEnvironmentBranch::query()->updateOrCreate(
                ['environment_id' => $environment->id],
                ['git_branch' => $branch],
            );
        });
    }

    public static function tracksBranch(Environment $environment): bool
    {
        return strcasecmp($environment->name, 'production') === 0 || OdooStaging::isStagingName($environment->name);
    }

    public static function repositoryName(Project $project): string
    {
        $name = Str::slug((string) $project->name);
        $name = substr($name, 0, 100);

        return $name !== '' ? $name : 'odoo-project';
    }

    /**
     * Creates the project repository on the first launch, then the branch named
     * after the environment. Nothing is deployed.
     */
    public static function launchEnvironment(Project $project, GithubApp $githubApp, string $classification): Environment
    {
        $profile = $project->odooProfile;
        if ($profile === null) {
            throw new InvalidArgumentException('Odoo is not enabled for this project.');
        }
        if ((int) $githubApp->team_id !== (int) $project->team_id && ! $githubApp->is_system_wide) {
            throw new InvalidArgumentException('This GitHub App is not available to the project team.');
        }

        [$environment, $branch, $createStaging] = self::resolveLaunchTarget($project, $classification);
        $repository = self::ensureRepositoryAndBranch($githubApp, $project, $branch);

        return DB::transaction(function () use ($project, $profile, $githubApp, $repository, $branch, $createStaging, $environment): Environment {
            $environment = self::persistLaunchTarget($project, $branch, $createStaging, $environment);

            $profile->update([
                'github_app_id' => $githubApp->id,
                'repository_id' => $repository['id'],
                'git_repository' => $repository['full_name'],
            ]);

            $taken = OdooEnvironmentBranch::query()
                ->whereIn('environment_id', $project->environments()->pluck('id'))
                ->where('git_branch', $branch)
                ->where('environment_id', '!=', $environment->id)
                ->exists();
            if ($taken) {
                throw new InvalidArgumentException('Each environment needs its own GitHub branch.');
            }

            OdooEnvironmentBranch::query()->updateOrCreate(
                ['environment_id' => $environment->id],
                ['git_branch' => $branch],
            );

            return $environment;
        });
    }

    /**
     * Same environment, without GitHub. JupyterLab is the file manager until a repository is connected.
     */
    public static function launchLocalEnvironment(Project $project, string $classification): Environment
    {
        if ($project->odooProfile === null) {
            throw new InvalidArgumentException('Odoo is not enabled for this project.');
        }

        [$environment, $branch, $createStaging] = self::resolveLaunchTarget($project, $classification);

        return DB::transaction(function () use ($project, $branch, $createStaging, $environment): Environment {
            return self::persistLaunchTarget($project, $branch, $createStaging, $environment);
        });
    }

    /**
     * @return array{0: ?Environment, 1: string, 2: bool}
     */
    private static function resolveLaunchTarget(Project $project, string $classification): array
    {
        if (! in_array($classification, ['production', 'staging'], true)) {
            throw new InvalidArgumentException('Choose production or staging.');
        }

        $createStaging = false;
        if ($classification === 'production') {
            $environment = $project->environments()->get()->first(
                fn (Environment $environment): bool => strcasecmp($environment->name, 'production') === 0
            );
            if (! $environment instanceof Environment) {
                throw new RuntimeException('This project has no production environment.');
            }
            $branch = $environment->name;
        } else {
            $environment = $project->environments()->with('odooBranch')->orderBy('id')->get()->first(
                fn (Environment $environment): bool => OdooStaging::isStagingName($environment->name) && blank($environment->odooBranch?->git_branch)
            );
            if ($environment instanceof Environment) {
                $branch = $environment->name;
            } else {
                if (! $project->canCreateStagingEnvironment()) {
                    throw new RuntimeException('Staging environment limit reached.');
                }
                $branch = OdooStaging::nextName($project);
                $createStaging = true;
                $environment = null;
            }
        }

        $check = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', new ValidGitBranch]]);
        if ($check->fails()) {
            throw new InvalidArgumentException('The GitHub branch name is invalid.');
        }

        return [$environment, $branch, $createStaging];
    }

    private static function persistLaunchTarget(Project $project, string $branch, bool $createStaging, ?Environment $environment): Environment
    {
        if ($createStaging) {
            $environment = $project->createNextStagingEnvironment();
        }
        if (! $environment instanceof Environment || $environment->name !== $branch) {
            throw new RuntimeException('The environment name does not match the GitHub branch.');
        }

        return $environment;
    }

    /**
     * @return array{full_name: string, id: int}
     */
    public static function ensureRepositoryAndBranch(GithubApp $githubApp, Project $project, string $branch): array
    {
        $profile = $project->odooProfile;
        if (filled($profile?->git_repository) && filled($profile?->repository_id)) {
            [$owner, $name] = self::splitRepository((string) $profile->git_repository);
            $repository = [
                'full_name' => (string) $profile->git_repository,
                'id' => (int) $profile->repository_id,
            ];
        } else {
            $account = self::installationAccount($githubApp);
            $name = self::repositoryName($project);
            $repository = self::createRepository($githubApp, $account['type'], $account['login'], $name, (string) $project->name);
            [$owner, $name] = self::splitRepository($repository['full_name']);
        }

        self::ensureBranch($githubApp, $owner, $name, $branch);

        return $repository;
    }

    /**
     * @return array{login: string, type: string}
     */
    private static function installationAccount(GithubApp $githubApp): array
    {
        $jwt = generateGithubJwt($githubApp);
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$jwt}",
            'Accept' => 'application/vnd.github+json',
        ])->get("{$githubApp->api_url}/app/installations/{$githubApp->installation_id}");
        if (! $response->successful()) {
            throw new RuntimeException('GitHub could not read the connected account.');
        }

        $login = (string) $response->json('account.login');
        $type = (string) $response->json('account.type');
        if ($login === '' || ! in_array($type, ['User', 'Organization'], true)) {
            throw new RuntimeException('GitHub could not read the connected account.');
        }

        return ['login' => $login, 'type' => $type];
    }

    /**
     * @return array{full_name: string, id: int}
     */
    private static function createRepository(GithubApp $githubApp, string $type, string $owner, string $name, string $projectName): array
    {
        $endpoint = $type === 'Organization'
            ? '/orgs/'.rawurlencode($owner).'/repos'
            : '/user/repos';
        $created = githubApi($githubApp, $endpoint, 'post', [
            'name' => $name,
            'private' => true,
            'auto_init' => true,
            'description' => 'Odoo '.$projectName,
        ], false);
        $id = (int) data_get($created, 'data.id');
        $fullName = (string) data_get($created, 'data.full_name');
        if ($id === 0 || $fullName === '') {
            $existing = githubApi($githubApp, '/repos/'.rawurlencode($owner).'/'.rawurlencode($name), 'get', null, false);
            $id = (int) data_get($existing, 'data.id');
            $fullName = (string) data_get($existing, 'data.full_name');
        }
        if ($id === 0 || $fullName === '') {
            throw new RuntimeException((string) data_get($created, 'data.message', 'GitHub could not create the repository.'));
        }

        return ['full_name' => $fullName, 'id' => $id];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitRepository(string $fullName): array
    {
        $parts = explode('/', $fullName, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException('GitHub did not return a repository name.');
        }

        return [$parts[0], $parts[1]];
    }

    private static function ensureBranch(GithubApp $githubApp, string $owner, string $name, string $branch): void
    {
        $repo = rawurlencode($owner).'/'.rawurlencode($name);
        $existing = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($branch), 'get', null, false);
        if (filled(data_get($existing, 'data.object.sha'))) {
            return;
        }

        $details = githubApi($githubApp, "/repos/{$repo}");
        $default = (string) data_get($details, 'data.default_branch', 'main');
        if ($default === $branch) {
            return;
        }

        $head = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($default));
        $sha = (string) data_get($head, 'data.object.sha');
        if ($sha === '') {
            throw new RuntimeException('GitHub did not return a commit to start the branch.');
        }

        githubApi($githubApp, "/repos/{$repo}/git/refs", 'post', [
            'ref' => 'refs/heads/'.$branch,
            'sha' => $sha,
        ]);
    }

    public static function ensureLaunchAllowed(Service $service): void
    {
        if (! $service->supportsOdooJupyter()) {
            return;
        }

        $service->loadMissing('environment.project.odooProfile');
        if (filled($service->environment?->project?->odooProfile?->git_repository)) {
            return;
        }

        if (! $service->jupyter_enabled) {
            $service->forceFill(['jupyter_enabled' => true])->save();
        }
    }

    public static function beginConnect(Project $project, string $back = 'project.edit', array $parameters = []): GithubApp
    {
        Gate::authorize('createAnyResource');

        session([
            'from' => [
                'back' => $back,
                'parameters' => $parameters === [] ? ['project_uuid' => $project->uuid] : $parameters,
            ],
        ]);
        $githubApp = GithubApp::create([
            'name' => substr(generate_random_name(), 0, 30),
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $project->team_id,
        ]);
        session(['from' => session('from') + ['source_id' => $githubApp->id]]);

        return $githubApp;
    }

    /**
     * A GitHub account is connected when the App is installed and has its private key.
     *
     * @return Collection<int, GithubApp>
     */
    public static function connectedApps(int $teamId): Collection
    {
        return GithubApp::query()
            ->where(function ($query) use ($teamId) {
                $query->where('team_id', $teamId)->orWhere('is_system_wide', true);
            })
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->whereNotNull('installation_id')
            ->whereNotNull('private_key_id')
            ->whereNotNull('webhook_secret')
            ->orderBy('name')
            ->get();
    }

    /**
     * The existing GitHub webhook calls this for a push. It marks only the
     * environment whose saved branch is exactly that GitHub branch.
     */
    public static function queueBranchUpdate(GithubApp $githubApp, int $repositoryId, string $branch): int
    {
        $rows = OdooEnvironmentBranch::query()
            ->where('git_branch', $branch)
            ->whereHas('environment.project.odooProfile', function ($query) use ($githubApp, $repositoryId) {
                $query->where('github_app_id', $githubApp->id)
                    ->where('repository_id', $repositoryId);
            })
            ->get();

        foreach ($rows as $row) {
            $row->update(['status' => 'updating']);
            if ($row->addons_application_id !== null) {
                continue;
            }
            \App\Jobs\SyncOdooAddonsJob::dispatch(odooEnvironmentBranchId: $row->id);
        }

        return $rows->count();
    }
}
