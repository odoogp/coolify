<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Support\Facades\Gate;
use App\Rules\ValidGitBranch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Binds a Coolify environment to the GitHub branch of the same name.
 * The environment stays production or staging. The stored branch is the GitHub branch.
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

    public static function tracksBranch(Environment $environment): bool
    {
        return strcasecmp($environment->name, 'production') === 0 || OdooStaging::isStagingName($environment->name);
    }

    /**
     * Creates or fills one environment and its GitHub branch in the same transaction.
     * The branch is chosen here, before anything is deployed.
     */
    public static function launchEnvironment(Project $project, GithubApp $githubApp, string $gitRepository, int $repositoryId, array $githubBranches, string $classification, string $branch): Environment
    {
        $profile = $project->odooProfile;
        if ($profile === null) {
            throw new InvalidArgumentException('Odoo is not enabled for this project.');
        }
        if (! in_array($classification, ['production', 'staging'], true)) {
            throw new InvalidArgumentException('Choose production or staging.');
        }
        if ((int) $githubApp->team_id !== (int) $project->team_id && ! $githubApp->is_system_wide) {
            throw new InvalidArgumentException('This GitHub App is not available to the project team.');
        }
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $gitRepository)) {
            throw new InvalidArgumentException('Invalid GitHub repository.');
        }

        $branch = trim($branch);
        $check = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', new ValidGitBranch]]);
        if ($check->fails()) {
            throw new InvalidArgumentException('The GitHub branch name is invalid.');
        }
        $githubBranches = array_values(array_unique(array_map(strval(...), $githubBranches)));
        if (! in_array($branch, $githubBranches, true)) {
            throw new InvalidArgumentException('That branch does not exist on this GitHub repository. Use the branch name from GitHub, not the environment name.');
        }

        return DB::transaction(function () use ($project, $profile, $githubApp, $gitRepository, $repositoryId, $classification, $branch): Environment {
            $profile->update([
                'github_app_id' => $githubApp->id,
                'repository_id' => $repositoryId,
                'git_repository' => $gitRepository,
            ]);

            if ($classification === 'production') {
                $environment = $project->environments()->get()->first(
                    fn (Environment $environment): bool => strcasecmp($environment->name, 'production') === 0
                );
                if (! $environment instanceof Environment) {
                    throw new RuntimeException('This project has no production environment.');
                }
            } else {
                $environment = $project->environments()->with('odooBranch')->orderBy('id')->get()->first(
                    fn (Environment $environment): bool => OdooStaging::isStagingName($environment->name) && blank($environment->odooBranch?->git_branch)
                );
                if (! $environment instanceof Environment) {
                    $environment = $project->createNextStagingEnvironment();
                }
            }

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

    public static function ensureLaunchAllowed(Service $service): void
    {
        if (! $service->supportsOdooJupyter()) {
            return;
        }

        $service->loadMissing('environment.project');
        $teamId = (int) $service->environment?->project?->team_id;
        if (self::connectedApps($teamId)->isEmpty()) {
            throw new RuntimeException('Connect a GitHub account before launching Odoo.');
        }
    }

    public static function beginConnect(Project $project): GithubApp
    {
        Gate::authorize('createAnyResource');

        session([
            'from' => [
                'back' => 'project.edit',
                'parameters' => [
                    'project_uuid' => $project->uuid,
                ],
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
