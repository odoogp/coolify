<?php

namespace App\Support;

use App\Actions\Service\StartService;
use App\Domain\Odoo\OdooContainers;
use App\Domain\Odoo\OdooDomains;
use App\Domain\Odoo\OdooMail;
use App\Domain\Odoo\OdooStaging;
use App\Enums\ProcessStatus;
use App\Jobs\SyncOdooAddonsJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GpshNotice;
use App\Models\OdooEnvironmentBranch;
use App\Models\OdooProfile;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\User;
use App\Rules\ValidGitBranch;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * GitHub is optional. Without a repository, JupyterLab is the file manager.
 * With a repository, each environment keeps its own branch. Staging asks for one that is not already used.
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
            $response = githubWithRateLimit(fn () => Http::GitHub($app->api_url, $token)
                ->timeout(15)
                ->get('/installation/repositories', [
                    'per_page' => 100,
                    'page' => $page,
                ]));
            if ($response->status() !== 200) {
                if ($repositories !== []) {
                    break;
                }
                $message = (string) ($response->json('message') ?: 'GitHub repositories could not be loaded.');
                if (githubRateLimited($message)) {
                    throw githubFailedRequestException([
                        'status' => $response->status(),
                        'rate_limit_remaining' => $response->header('X-RateLimit-Remaining'),
                        'rate_limit_reset' => $response->header('X-RateLimit-Reset'),
                        'retry_after' => $response->header('Retry-After'),
                        'data' => collect($response->json()),
                    ]);
                }
                throw new RuntimeException($message);
            }

            $total = (int) $response->json('total_count');
            foreach ($response->json('repositories') ?? [] as $repository) {
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
            // ponytail: five pages, 500 repositories. Another page is the upgrade if an installation has more.
        } while (count($repositories) < $total && $page <= 5);

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
            $response = githubWithRateLimit(fn () => Http::GitHub($app->api_url, $token)
                ->timeout(20)
                ->get('/repos/'.$owner.'/'.$repo.'/branches', [
                    'per_page' => 100,
                    'page' => $page,
                ]));
            if ($response->status() !== 200) {
                $message = (string) ($response->json('message') ?: 'GitHub branches could not be loaded.');
                if (githubRateLimited($message)) {
                    throw githubFailedRequestException([
                        'status' => $response->status(),
                        'rate_limit_remaining' => $response->header('X-RateLimit-Remaining'),
                        'rate_limit_reset' => $response->header('X-RateLimit-Reset'),
                        'retry_after' => $response->header('Retry-After'),
                        'data' => collect($response->json()),
                    ]);
                }
                throw new RuntimeException($message);
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
        self::assertRepositoryFree($gitRepository, (int) $project->id);

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
        self::assertRepositoryFree($gitRepository, (int) $project->id);

        $branch = trim($branch);
        $githubBranches = array_values(array_unique(array_map(strval(...), $githubBranches)));
        $check = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', new ValidGitBranch]]);
        if ($check->fails()) {
            throw new InvalidArgumentException('The GitHub branch name is invalid.');
        }
        if (! in_array($branch, $githubBranches, true)) {
            throw new InvalidArgumentException('That branch does not exist on this GitHub repository. Use the branch name from GitHub, not the environment name.');
        }

        if (self::branchTakenOnRepository($project, $environment, $branch, $gitRepository)) {
            throw new InvalidArgumentException('Each environment needs its own GitHub branch on the same repository.');
        }

        DB::transaction(function () use ($profile, $githubApp, $gitRepository, $repositoryId, $environment, $branch): void {
            // First link fills the project profile. Later environments may use another
            // repository without rewriting production's profile tip.
            if (blank($profile->git_repository)) {
                $profile->update([
                    'github_app_id' => $githubApp->id,
                    'repository_id' => $repositoryId,
                    'git_repository' => $gitRepository,
                ]);
            } elseif ($profile->github_app_id === null) {
                $profile->update(['github_app_id' => $githubApp->id]);
            }

            OdooEnvironmentBranch::query()->updateOrCreate(
                ['environment_id' => $environment->id],
                [
                    'git_branch' => $branch,
                    'git_repository' => $gitRepository,
                    'repository_id' => $repositoryId,
                ],
            );
        });

        self::ensureAddonsApplication($environment->fresh() ?? $environment);
    }

    /**
     * Same tip name is fine on different repositories (odoogp20/main vs odoogp19_demo/main).
     * It is blocked only when another environment already uses that tip on this repository.
     */
    public static function branchTakenOnRepository(Project $project, Environment $environment, string $branch, string $gitRepository): bool
    {
        $branch = trim($branch);
        $gitRepository = trim($gitRepository);
        if ($branch === '' || $gitRepository === '') {
            return false;
        }

        $project->loadMissing('odooProfile');
        $profileRepo = (string) ($project->odooProfile?->git_repository ?? '');
        $environmentIds = $project->environments()->pluck('id')
            ->filter(fn ($id): bool => (int) $id !== (int) $environment->id)
            ->all();
        if ($environmentIds === []) {
            return false;
        }

        $rows = OdooEnvironmentBranch::query()
            ->whereIn('environment_id', $environmentIds)
            ->where('git_branch', $branch)
            ->get(['git_branch', 'git_repository']);

        foreach ($rows as $row) {
            $otherRepo = filled($row->git_repository)
                ? (string) $row->git_repository
                : $profileRepo;
            if ($otherRepo !== '' && strcasecmp($otherRepo, $gitRepository) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function environmentGitRepository(Environment $environment): ?string
    {
        $environment->loadMissing('odooBranch', 'project.odooProfile');
        $fromBranch = $environment->odooBranch?->git_repository;
        if (filled($fromBranch)) {
            return (string) $fromBranch;
        }
        $fromProfile = $environment->project?->odooProfile?->git_repository;

        return filled($fromProfile) ? (string) $fromProfile : null;
    }

    public static function environmentRepositoryId(Environment $environment): ?int
    {
        $environment->loadMissing('odooBranch', 'project.odooProfile');
        $fromBranch = $environment->odooBranch?->repository_id;
        if ($fromBranch !== null && (int) $fromBranch > 0) {
            return (int) $fromBranch;
        }
        $fromProfile = $environment->project?->odooProfile?->repository_id;

        return $fromProfile !== null && (int) $fromProfile > 0 ? (int) $fromProfile : null;
    }

    /**
     * Coolify Application the GitHub webhook matches (repo + branch → SyncOdooAddonsJob).
     * Launch / associate often set the profile without creating this row; pushes then do nothing
     * in the Applications list. Create or refresh it when the Odoo service already exists.
     */
    public static function ensureAddonsApplication(Environment $environment, ?Service $service = null): ?Application
    {
        $environment->loadMissing('project.odooProfile', 'odooBranch', 'services');
        $profile = $environment->project?->odooProfile;
        $branch = $environment->odooBranch;
        $gitRepository = self::environmentGitRepository($environment);
        $repositoryId = self::environmentRepositoryId($environment);
        if ($profile === null || $branch === null || blank($gitRepository) || $profile->github_app_id === null || blank($branch->git_branch)) {
            return null;
        }

        $service ??= $branch->service_id !== null
            ? Service::query()->find($branch->service_id)
            : null;
        $service ??= $environment->services->first(
            fn (Service $candidate): bool => $candidate->supportsOdooJupyter()
        );
        if (! $service instanceof Service) {
            return null;
        }

        $service->loadMissing('destination');
        $destination = $service->destination;
        if ($destination === null) {
            return null;
        }

        $application = $branch->addons_application_id !== null
            ? Application::query()->find($branch->addons_application_id)
            : null;

        $attributes = [
            'name' => 'odoo-addons-'.$environment->name,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'source_id' => $profile->github_app_id,
            'source_type' => GithubApp::class,
            'git_repository' => $gitRepository,
            'git_branch' => $branch->git_branch,
            'repository_project_id' => $repositoryId,
            'build_pack' => 'dockerfile',
            'ports_exposes' => '8069',
            'is_odoo_addons' => true,
        ];

        if ($application instanceof Application) {
            $application->fill($attributes)->save();
        } else {
            $application = Application::create($attributes);
        }

        $branch->forceFill([
            'addons_application_id' => $application->id,
            'service_id' => $branch->service_id ?: $service->id,
        ])->save();

        return $application;
    }

    public static function tracksBranch(Environment $environment): bool
    {
        return $environment->isProduction() || $environment->isStaging();
    }

    public static function repositoryName(Project $project): string
    {
        $name = Str::slug((string) $project->name);
        $name = substr($name, 0, 100);

        return $name !== '' ? $name : 'odoo-project';
    }

    /**
     * Creates the project repository on the first launch.
     * Production stores the repository default branch. Staging stores its own branch.
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
        if ($classification === 'production') {
            $repository = self::ensureRepositoryAndBranch($githubApp, $project, null);
            $branch = $repository['default_branch'];
        } else {
            $repository = self::ensureRepositoryAndBranch($githubApp, $project, $branch);
        }

        $environment = DB::transaction(function () use ($project, $profile, $githubApp, $repository, $branch, $createStaging, $environment): Environment {
            $environment = self::persistLaunchTarget($project, $createStaging, $environment);

            $profile->update([
                'github_app_id' => $githubApp->id,
                'repository_id' => $repository['id'],
                'git_repository' => $repository['full_name'],
            ]);

            $fullName = (string) $repository['full_name'];
            if (self::branchTakenOnRepository($project, $environment, $branch, $fullName)) {
                throw new InvalidArgumentException('Each environment needs its own GitHub branch on the same repository.');
            }

            OdooEnvironmentBranch::query()->updateOrCreate(
                ['environment_id' => $environment->id],
                [
                    'git_branch' => $branch,
                    'git_repository' => $fullName,
                    'repository_id' => $repository['id'],
                ],
            );

            return $environment;
        });

        self::ensureAddonsApplication($environment->fresh() ?? $environment);

        return $environment;
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

        return DB::transaction(function () use ($project, $createStaging, $environment): Environment {
            return self::persistLaunchTarget($project, $createStaging, $environment);
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
            $environment = $project->productionEnvironment();
            if (! $environment instanceof Environment) {
                throw new RuntimeException('This project has no production environment.');
            }
            $branch = $environment->name;
        } else {
            $environment = $project->environments()->with('odooBranch')->orderBy('id')->get()->first(
                fn (Environment $environment): bool => $environment->isStaging() && blank($environment->odooBranch?->git_branch)
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

    private static function persistLaunchTarget(Project $project, bool $createStaging, ?Environment $environment): Environment
    {
        if ($createStaging) {
            $environment = $project->createNextStagingEnvironment();
        }
        if (! $environment instanceof Environment) {
            throw new RuntimeException('This project has no production environment.');
        }

        return $environment;
    }

    /**
     * @return array{full_name: string, id: int, default_branch: string}
     */
    public static function ensureRepositoryAndBranch(GithubApp $githubApp, Project $project, ?string $branch): array
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
            self::assertRepositoryFree($account['login'].'/'.$name, (int) $project->id);
            $repository = self::createRepository($githubApp, $account['type'], $account['login'], $name, (string) $project->name);
            [$owner, $name] = self::splitRepository($repository['full_name']);
        }

        self::assertRepositoryFree($repository['full_name'], (int) $project->id);
        $default = self::defaultBranch($githubApp, $owner, $name);
        if ($branch !== null && $branch !== '') {
            self::ensureBranch($githubApp, $owner, $name, $branch);
        }

        return $repository + ['default_branch' => $default];
    }

    public static function databaseName(Service $service): ?string
    {
        if (! OdooJupyter::isOdooCompose((string) $service->docker_compose_raw)) {
            return null;
        }

        $service->loadMissing('environment.project');
        $project = Str::slug((string) $service->environment?->project?->name, '_');
        $branch = Str::slug((string) ($service->environment?->name ?: 'production'), '_');
        $name = trim($project.'_'.$branch, '_');
        $name = preg_replace('/[^a-z0-9_]/', '', $name) ?? '';
        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = 'odoo_'.$name;
        }

        return substr(rtrim($name, '_'), 0, 63);
    }

    public static function prepareInstance(Service $service): void
    {
        $database = self::databaseName($service);
        if ($database === null) {
            return;
        }

        self::rememberVariable($service, 'ODOO_DATABASE', $database, true);
        // Same as before Keys: invent once, never block create/start, never touch autologin token flow.
        if ($service->environment_variables()->where('key', 'ODOO_ADMIN_PASSWORD')->doesntExist()) {
            self::rememberVariable($service, 'ODOO_ADMIN_PASSWORD', Str::password(20, symbols: false), false);
        }
        if ($service->environment_variables()->where('key', 'ODOO_LOGIN_TOKEN')->doesntExist()) {
            self::rememberVariable($service, 'ODOO_LOGIN_TOKEN', Str::password(40, symbols: false), false);
        }
    }

    public static function assignCopiedBranch(Service $service): void
    {
        $database = self::databaseName($service);
        if ($database === null) {
            return;
        }

        $service->loadMissing('environment');
        self::rememberVariable($service, 'ODOO_DATABASE', $database, true);
        self::rememberVariable($service, 'ODOO_LOGIN_TOKEN', Str::password(40, symbols: false), true);
        if ($service->environment_variables()->where('key', 'ODOO_ADMIN_PASSWORD')->doesntExist()) {
            self::rememberVariable($service, 'ODOO_ADMIN_PASSWORD', Str::password(20, symbols: false), false);
        }
    }

    /**
     * Store the Odoo admin password in Coolify and push it into res_users when the container is up.
     * Does not ask for the previous password — Coolify is the source of truth for this tip.
     */
    public static function setAdminPassword(Service $service, string $password): void
    {
        if (! $service->supportsOdooJupyter()) {
            throw new InvalidArgumentException('This service is not an Odoo stack.');
        }
        $password = trim($password);
        if ($password === '' || strlen($password) < 8 || strlen($password) > 128) {
            throw new InvalidArgumentException('Choose an administrator password between 8 and 128 characters.');
        }

        self::rememberVariable($service, 'ODOO_ADMIN_PASSWORD', $password, true);
        $service->refresh();
        self::writeAdminPasswordToVolume($service, $password);
        if (! app()->runningUnitTests()) {
            $service->saveComposeConfigs();
        }
        self::applyAdminPassword($service, $password);
    }

    /**
     * Odoo cannot return a hashed password. When the admin password changes in Odoo,
     * gpsh_autoconnect writes the tip to the addons volume; Claves pulls it here.
     * Best-effort only — never throws, never used on create/start.
     */
    public static function syncAdminPasswordFromVolume(Service $service): bool
    {
        try {
            if (! $service->supportsOdooJupyter()) {
                return false;
            }
            $fromVolume = self::readAdminPasswordFromVolume($service);
            if ($fromVolume === null || $fromVolume === '') {
                return false;
            }
            $current = self::runtimeValue($service, 'ODOO_ADMIN_PASSWORD');
            if ($current === $fromVolume) {
                return false;
            }
            self::rememberVariable($service, 'ODOO_ADMIN_PASSWORD', $fromVolume, true);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function readAdminPasswordFromVolume(Service $service): ?string
    {
        try {
            if (app()->runningUnitTests()) {
                return null;
            }
            $service->loadMissing('destination.server');
            $server = $service->destination?->server ?? $service->server;
            $volume = OdooAddons::extraAddonsVolume($service);
            if ($server === null || $volume === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/', $volume) !== 1) {
                return null;
            }
            $output = instant_remote_process([
                'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons:ro alpine -c '.escapeshellarg(
                    'if [ -f /addons/.gpsh/admin_password ]; then cat /addons/.gpsh/admin_password; fi'
                ),
            ], $server, false);
            $password = trim((string) $output);
            if ($password === '' || strlen($password) > 128) {
                return null;
            }

            return $password;
        } catch (Throwable) {
            return null;
        }
    }

    public static function writeAdminPasswordToVolume(Service $service, string $password): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $password = trim($password);
        if ($password === '' || strlen($password) > 128) {
            return;
        }
        $service->loadMissing('destination.server');
        $server = $service->destination?->server ?? $service->server;
        $volume = OdooAddons::extraAddonsVolume($service);
        if ($server === null || $volume === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/', $volume) !== 1) {
            return;
        }
        $encoded = base64_encode($password);
        try {
            instant_remote_process([
                'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons alpine -c '.escapeshellarg(
                    'mkdir -p /addons/.gpsh; printf %s '.escapeshellarg($encoded).' | base64 -d > /addons/.gpsh/admin_password; chmod 600 /addons/.gpsh/admin_password'
                ),
            ], $server, false);
        } catch (Throwable) {
            // Env var still holds the tip; next start can rewrite the file.
        }
    }

    /**
     * Best-effort write into base.user_admin. Next start also applies ODOO_ADMIN_PASSWORD.
     */
    public static function applyAdminPassword(Service $service, string $password): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (! $service->supportsOdooJupyter() || ! self::odooIsUp($service)) {
            return;
        }

        try {
            $service->loadMissing('destination.server');
            $server = $service->destination?->server ?? $service->server;
            $database = self::databaseName($service);
            if ($server === null || blank($database) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/', (string) $service->uuid) !== 1) {
                return;
            }

            $container = 'odoo-'.$service->uuid;
            // Connect-as never sets a login password; Users → Change password then asks for a
            // current tip that is empty. Force-write admin without needing the old password.
            $script = 'admin = env.ref("base.user_admin").sudo()'
                ."\n".'admin.write({"password": '.json_encode($password, JSON_UNESCAPED_SLASHES).'})'
                ."\n".'env.cr.commit()'
                ."\n";
            $encoded = base64_encode($script);
            $inner = 'printf %s '.escapeshellarg($encoded).' | base64 -d | odoo shell --no-http --max-cron-threads=0 '
                .'--addons-path=/mnt/extra-addons,/usr/lib/python3/dist-packages/odoo/addons '
                .'-d '.escapeshellarg((string) $database);

            instant_remote_process([
                'docker exec '.escapeshellarg($container).' sh -c '.escapeshellarg($inner),
            ], $server, false, false, 60);
        } catch (Throwable) {
            // Stored in Coolify; the next start reapplies the password from ODOO_ADMIN_PASSWORD.
        }
    }

    /**
     * The Odoo container is up. Sidecars and the service-wide "starting" flag do not count.
     */
    public static function odooIsUp(Service $service): bool
    {
        $service->loadMissing('applications');
        $application = $service->applications->first(
            fn ($application): bool => $application instanceof ServiceApplication && self::isOdooApplication($application)
        );
        if (! $application instanceof ServiceApplication) {
            return false;
        }

        $status = strtolower((string) $application->status);

        return str_contains($status, 'running') && ! str_contains($status, 'exited');
    }

    /**
     * Open Odoo once it has a public URL and either its container is up or a notice already said so.
     * The status column can stay "exited" until the next container check.
     */
    public static function canOpen(Service $service): bool
    {
        if (self::enterUrl($service) === '') {
            return false;
        }

        if (self::odooIsUp($service) || self::startFinished($service)) {
            return true;
        }

        return GpshNotice::query()
            ->where('service_id', $service->id)
            ->whereIn('kind', ['mounted', 'accessible'])
            ->exists();
    }

    private static function startFinished(Service $service): bool
    {
        try {
            $status = data_get($service->latestProcessActivity(), 'properties.status');

            return (string) $status === ProcessStatus::FINISHED->value;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One start or database copy at a time on a server.
     * ponytail: the waiter gives up after 10 minutes; the lock itself expires after 30 if the job dies. A single dump is not size-capped.
     */
    public static function whileServerIsFree(Server $server, callable $work): mixed
    {
        $lock = Cache::lock('gpsh-host-'.$server->id, 1800);

        try {
            $lock->block(600);
        } catch (LockTimeoutException) {
            throw new RuntimeException(__('Another environment is still being copied on this server. Try again when it finishes.'));
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    public static function publicHttpsUrl(Service $service): string
    {
        $application = $service->applications()->get()->first(
            fn ($application): bool => $application instanceof ServiceApplication && self::isOdooApplication($application)
        );

        if ($application instanceof ServiceApplication) {
            return self::httpsUrl(firstDomainFromList((string) $application->fqdn));
        }

        $preferred = self::preferredPublicHttpApplication($service);
        if ($preferred instanceof ServiceApplication) {
            return self::httpsUrl(firstDomainFromList((string) $preferred->fqdn));
        }

        return '';
    }

    /**
     * Background containers (workers, databases, …) must not own the public
     * Coolify/sslip Host — Traefik ACME and Open would hit the wrong service.
     */
    public static function isLikelySidecarApplication(ServiceApplication $application): bool
    {
        $name = strtolower(str_replace('_', '-', (string) $application->name));
        if ($name === '') {
            return false;
        }

        $roles = [
            'worker', 'beat', 'scheduler', 'cron',
            'redis', 'postgres', 'postgresql', 'mysql', 'mariadb', 'mongo', 'mongodb',
            'rabbitmq', 'mailpit', 'mail', 'smtp', 'minio', 'meilisearch', 'elasticsearch',
            'clickhouse', 'keydb', 'dragonfly', 'valkey', 'nats', 'kafka', 'zookeeper', 'db',
        ];

        foreach ($roles as $role) {
            if ($name === $role || str_ends_with($name, '-'.$role) || str_starts_with($name, $role.'-')) {
                return true;
            }
        }

        return false;
    }

    public static function preferredPublicHttpApplication(Service $service): ?ServiceApplication
    {
        $applications = $service->applications()->get()
            ->filter(fn ($application): bool => $application instanceof ServiceApplication);

        $withFqdn = static fn (ServiceApplication $application): bool => trim((string) $application->fqdn) !== '';

        $public = $applications->reject(fn (ServiceApplication $application): bool => self::isLikelySidecarApplication($application));
        if ($public->isNotEmpty()) {
            $chosen = $public->first($withFqdn) ?? $public->first();

            return $chosen instanceof ServiceApplication ? $chosen : null;
        }

        $chosen = $applications->first($withFqdn) ?? $applications->first();

        return $chosen instanceof ServiceApplication ? $chosen : null;
    }

    public static function isCoolifyGeneratedServiceFqdn(Service $service, string $fqdn): bool
    {
        $host = parse_url(self::httpsUrl($fqdn), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $uuid = (string) $service->uuid;
        if ($uuid === '' || ! str_contains($host, $uuid)) {
            return false;
        }

        return str_ends_with($host, '.sslip.io')
            || $host === 'sslip.io'
            || str_ends_with($host, '.nip.io')
            || $host === 'nip.io';
    }

    public static function runtimeValue(Service $service, string $key): string
    {
        return (string) $service->environment_variables()->where('key', $key)->first()?->value;
    }

    public static function copyProductionData(Service $source, Service $target): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $server = $target->server;
        if ($server === null || ! $server->isFunctional()) {
            return;
        }

        instant_remote_process([self::copyProductionDataCommand($source, $target)], $server);
    }

    /**
     * True when this Odoo already answers its login page. A failed start can still leave it open.
     */
    public static function loginAnswers(Service $service): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        $server = $service->server;
        if ($server === null || ! $server->isFunctional()) {
            return false;
        }

        $host = parse_url(self::publicHttpsUrl($service), PHP_URL_HOST);
        $command = is_string($host) ? self::loginOpenCommand($host) : null;
        if ($command === null) {
            return false;
        }

        try {
            $output = instant_remote_process([$command], $server, false);
        } catch (Throwable) {
            return false;
        }

        return str_contains((string) $output, 'open');
    }

    public static function loginOpenCommand(string $host): ?string
    {
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        $resolve = escapeshellarg($host.':443:127.0.0.1');
        $url = escapeshellarg('https://'.$host.'/web/login');
        $script = 'code=$(curl -skL --resolve '.$resolve.' --max-time 8 -o /tmp/gpsh-open -w "%{http_code}" '.$url.' || true); if case "$code" in 2*|3*) true ;; *) false ;; esac && ! grep -qi "bad gateway" /tmp/gpsh-open && ! grep -q "no available server" /tmp/gpsh-open && ! grep -q "se actualiza sola" /tmp/gpsh-open && grep -Eqi "oe_login|/web/login|odoo" /tmp/gpsh-open; then echo open; fi; rm -f /tmp/gpsh-open';

        return 'bash -c '.escapeshellarg($script);
    }

    /**
     * The clone screen stays up until this branch's Odoo answers the real login page.
     */
    public static function waitUntilOpen(Service $service): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $server = $service->server;
        if ($server === null || ! $server->isFunctional()) {
            throw new RuntimeException('The staging service did not start.');
        }

        instant_remote_process([self::containersReadyCommand($service)], $server);
        $host = parse_url(self::publicHttpsUrl($service), PHP_URL_HOST);
        $command = is_string($host) ? self::httpsReadyCommand($host) : null;
        if ($command === null) {
            throw new RuntimeException('The staging service did not start.');
        }

        instant_remote_process([$command], $server);
    }

    public static function clientSeesLog(string $container): bool
    {
        return OdooContainers::clientSeesLog($container);
    }

    public static function usesSharedCertificate(string $serviceKey): bool
    {
        return OdooContainers::usesSharedCertificate($serviceKey);
    }

    public static function isOdooContainerLog(string $container): bool
    {
        return OdooContainers::isOdooContainerLog($container);
    }

    public static function terminalShell(string $container): ?string
    {
        $name = strtolower(ltrim($container, '/'));
        if ($name === '' || str_contains($name, 'jupyter') || str_contains($name, 'postgres')) {
            return null;
        }
        if (! str_starts_with($name, 'odoo-') && ! str_starts_with($name, 'odoo_')) {
            return null;
        }

        // 8069 is the waiting proxy, so a plain `odoo shell` dies with "Address already in use".
        // Without the db flags it also dials 127.0.0.1:5432. --no-http skips that bind.
        return <<<'BASH'
addons=$(cat /tmp/gpsh-addons-path 2>/dev/null || printf '%s' '/mnt/extra-addons,/usr/lib/python3/dist-packages/odoo/addons')
exec odoo shell --no-http --max-cron-threads=0 --no-database-list \
  --db_host="${HOST:-postgresql}" --db_port="${PORT:-5432}" --db_user="$USER" --db_password="$PASSWORD" \
  -d "$ODOO_DATABASE" --addons-path="$addons"
BASH;
    }

    public static function serviceForTerminalContainer(string $container): ?Service
    {
        if (self::terminalShell($container) === null) {
            return null;
        }

        $uuid = preg_replace('/^odoo[-_]/i', '', ltrim($container, '/')) ?? '';
        if ($uuid === '') {
            return null;
        }

        return Service::query()->where('uuid', $uuid)->first();
    }

    public static function copyProductionDataCommand(Service $source, Service $target): string
    {
        $source->loadMissing('environment');
        $target->loadMissing('environment');
        if (! $source->environment?->isProduction()) {
            throw new RuntimeException('The database copy starts from production.');
        }
        if (! $target->environment?->isStaging()) {
            throw new RuntimeException('Only a staging database is neutralized.');
        }
        if ((string) $source->uuid === (string) $target->uuid) {
            throw new RuntimeException('The database copy refused to write production.');
        }

        $sourceDatabase = self::runtimeValue($source, 'ODOO_DATABASE') ?: (string) self::databaseName($source);
        $targetDatabase = self::runtimeValue($target, 'ODOO_DATABASE') ?: (string) self::databaseName($target);
        foreach (['source database' => $sourceDatabase, 'staging database' => $targetDatabase] as $label => $name) {
            if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
                throw new RuntimeException('The '.$label.' name is invalid.');
            }
        }
        if ($sourceDatabase === $targetDatabase) {
            throw new RuntimeException('The database copy refused to write production.');
        }

        $sourceUser = self::runtimeValue($source, 'SERVICE_USER_POSTGRES');
        $targetUser = self::runtimeValue($target, 'SERVICE_USER_POSTGRES');
        $sourcePassword = self::runtimeValue($source, 'SERVICE_PASSWORD_POSTGRES');
        $targetPassword = self::runtimeValue($target, 'SERVICE_PASSWORD_POSTGRES');
        foreach ([$sourceUser, $targetUser] as $user) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $user) !== 1) {
                throw new RuntimeException('The staging database cannot be copied without the Postgres credentials.');
            }
        }
        if ($sourcePassword === '' || $targetPassword === '') {
            throw new RuntimeException('The staging database cannot be copied without the Postgres credentials.');
        }
        foreach ([$source->uuid, $target->uuid] as $uuid) {
            if (preg_match('/^[A-Za-z0-9]+$/', (string) $uuid) !== 1) {
                throw new RuntimeException('The database copy refused to write production.');
            }
        }
        if ($source->server_id !== null && $target->server_id !== null && (int) $source->server_id !== (int) $target->server_id) {
            throw new RuntimeException('The staging copy needs production and staging on the same server.');
        }

        foreach ([$source->id, $target->id] as $serviceId) {
            if (preg_match('/^[1-9][0-9]*$/', (string) $serviceId) !== 1) {
                throw new RuntimeException('The staging database cannot be copied.');
            }
        }

        $url = self::publicHttpsUrl($target);
        $urlSql = 'true';
        if (preg_match('#^https://[A-Za-z0-9.-]+$#', $url) === 1) {
            $sql = "UPDATE ir_config_parameter SET value='{$url}' WHERE key='web.base.url'; "
                ."INSERT INTO ir_config_parameter (key, value, create_uid, write_uid, create_date, write_date) SELECT 'web.base.url', '{$url}', 1, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM ir_config_parameter WHERE key='web.base.url'); "
                ."UPDATE ir_config_parameter SET value='True' WHERE key='web.base.url.freeze'; "
                ."INSERT INTO ir_config_parameter (key, value, create_uid, write_uid, create_date, write_date) SELECT 'web.base.url.freeze', 'True', 1, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM ir_config_parameter WHERE key='web.base.url.freeze');";
            $urlSql = 'docker exec -e PGPASSWORD="$(printf \'%s\' \''.base64_encode($targetPassword).'\' | base64 -d)" "$dst_pg" psql -U '.$targetUser.' -d '.$targetDatabase.' -v ON_ERROR_STOP=1 -c '.escapeshellarg($sql);
        }

        // ponytail: one-shot containers are capped so the kernel kills the copy, not a neighbor. Raise 2g if a large database fails to neutralize. A dump bigger than the free disk can still fill it.
        $script = <<<'BASH'
set -eu
dump=__DUMP__
pick() {
  service_id="$1"
  project="$2"
  kind="$3"
  ids="$(docker ps -aq --filter "label=com.docker.compose.project=${project}"; docker ps -aq --filter "label=coolify.serviceId=${service_id}")"
  running=""
  stopped=""
  for id in $ids; do
    image=$(docker inspect --format '{{.Config.Image}}' "$id" 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
    name=$(docker inspect --format '{{.Name}}' "$id" 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
    case "$name" in *stdlib*|*jupyter*|*cadvisor*|*prometheus*|*monitor*|*beszel*) continue ;; esac
    subtype=$(docker inspect --format '{{ index .Config.Labels "coolify.service.subType" }}' "$id" 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
    state=$(docker inspect --format '{{.State.Running}}' "$id" 2>/dev/null || true)
    match=0
    if [ "$kind" = "postgres" ]; then
      case "$image$name" in *postgres*) match=1 ;; esac
      if [ "$subtype" = "database" ]; then match=1; fi
    fi
    if [ "$kind" = "odoo" ]; then
      case "$image" in odoo:*|*/odoo:*) match=1 ;; esac
      case "$name" in *odoo*) case "$name" in *jupyter*) ;; *) match=1 ;; esac ;; esac
    fi
    if [ "$match" -eq 1 ]; then
      if [ "$state" = "true" ]; then running="$id"; else [ -n "$stopped" ] || stopped="$id"; fi
    fi
  done
  if [ -n "$running" ]; then printf '%s\n' "$running"; return 0; fi
  if [ -n "$stopped" ]; then printf '%s\n' "$stopped"; return 0; fi
  return 1
}
src_pg=$(pick __SRC_ID__ __SRC_UUID__ postgres || true)
dst_pg=$(pick __DST_ID__ __DST_UUID__ postgres || true)
src_odoo=$(pick __SRC_ID__ __SRC_UUID__ odoo || true)
dst_odoo=$(pick __DST_ID__ __DST_UUID__ odoo || true)
if [ -z "$src_pg" ]; then echo "The database container of the service being cloned was not found." >&2; exit 1; fi
if [ -z "$dst_pg" ]; then echo "The database container of the new staging service was not found." >&2; exit 1; fi
if [ -z "$src_odoo" ]; then echo "The Odoo container of the service being cloned was not found." >&2; exit 1; fi
if [ -z "$dst_odoo" ]; then echo "The Odoo container of the new staging service was not found." >&2; exit 1; fi
avail=$(df -Pk "$(dirname "$dump")" | awk 'NR==2 {print $4}')
if [ "${avail:-0}" -lt 1048576 ]; then echo "Not enough free disk to clone without filling the server." >&2; exit 1; fi
if [ -d /var/lib/docker ]; then avail=$(df -Pk /var/lib/docker | awk 'NR==2 {print $4}'); if [ "${avail:-0}" -lt 1048576 ]; then echo "Not enough free disk to clone without filling the server." >&2; exit 1; fi; fi
docker start "$src_pg" >/dev/null
docker start "$dst_pg" >/dev/null
docker stop "$dst_odoo" >/dev/null 2>&1 || true
trap 'status=$?; rm -f "$dump"; if [ "$status" -ne 0 ]; then docker start "$dst_odoo" >/dev/null 2>&1 || true; fi; exit "$status"' EXIT
docker exec -e PGPASSWORD="$(printf '%s' '__SRC_PW__' | base64 -d)" "$src_pg" pg_dump -U __SRC_USER__ --no-owner --no-acl __SRC_DB__ > "$dump"
docker exec -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" "$dst_pg" psql -U __DST_USER__ -d postgres -v ON_ERROR_STOP=1 -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '__DST_DB__' AND pid <> pg_backend_pid();"
docker exec -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" "$dst_pg" dropdb --if-exists -U __DST_USER__ __DST_DB__
docker exec -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" "$dst_pg" createdb -U __DST_USER__ __DST_DB__
docker exec -i -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" "$dst_pg" psql -U __DST_USER__ -d __DST_DB__ -v ON_ERROR_STOP=1 < "$dump"
docker exec -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" "$dst_pg" psql -U __DST_USER__ -d __DST_DB__ -v ON_ERROR_STOP=1 -c "DROP TABLE IF EXISTS orm_signaling_registry, orm_signaling_assets, orm_signaling_default, orm_signaling_templates, orm_signaling_routing, orm_signaling_groups CASCADE"
src_vol=$(docker inspect --format '{{ range .Mounts }}{{ if eq .Destination "/var/lib/odoo" }}{{ .Name }}{{ end }}{{ end }}' "$src_odoo")
dst_vol=$(docker inspect --format '{{ range .Mounts }}{{ if eq .Destination "/var/lib/odoo" }}{{ .Name }}{{ end }}{{ end }}' "$dst_odoo")
if [ -z "$src_vol" ] || [ -z "$dst_vol" ] || [ "$src_vol" = "$dst_vol" ]; then
  echo "The database copy refused to write production." >&2
  exit 1
fi
uid=$(docker exec "$src_odoo" id -u)
gid=$(docker exec "$src_odoo" id -g)
case "$uid" in ''|*[!0-9]*) echo "The Odoo data directory could not be made writable." >&2; exit 1 ;; esac
case "$gid" in ''|*[!0-9]*) echo "The Odoo data directory could not be made writable." >&2; exit 1 ;; esac
docker run --rm --memory=512m --cpus=1 -v "$src_vol":/source:ro -v "$dst_vol":/target alpine sh -c "find /target -mindepth 1 -maxdepth 1 -exec rm -rf {} +; cp -a /source/. /target/; if [ -d /target/filestore/__SRC_DB__ ]; then rm -rf /target/filestore/__DST_DB__; mv /target/filestore/__SRC_DB__ /target/filestore/__DST_DB__; fi; mkdir -p /target/sessions; chown -R ${uid}:${gid} /target; chmod -R u+rwX /target"
__URL_SQL__
image=$(docker inspect --format '{{.Image}}' "$src_odoo")
docker run --pull never --rm --memory=2g --cpus=1 --network "container:$dst_pg" --entrypoint odoo "$image" neutralize -d __DST_DB__ --db_host=127.0.0.1 --db_port=5432 --db_user=__DST_USER__ --db_password="$(printf '%s' '__DST_PW__' | base64 -d)" --stop-after-init
docker start "$dst_odoo"
BASH;

        $script = str_replace(
            ['__SRC_ID__', '__DST_ID__', '__SRC_UUID__', '__DST_UUID__', '__SRC_USER__', '__DST_USER__', '__SRC_DB__', '__DST_DB__', '__SRC_PW__', '__DST_PW__', '__DUMP__', '__URL_SQL__'],
            [
                (string) $source->id,
                (string) $target->id,
                $source->uuid,
                $target->uuid,
                $sourceUser,
                $targetUser,
                $sourceDatabase,
                $targetDatabase,
                base64_encode($sourcePassword),
                base64_encode($targetPassword),
                '/tmp/gpsh-clone-'.$target->uuid.'.sql',
                $urlSql,
            ],
            $script,
        );

        return 'bash -c '.escapeshellarg($script);
    }

    public static function enterUrl(Service $service, ?string $login = null): string
    {
        $base = self::publicHttpsUrl($service);
        $token = self::runtimeValue($service, 'ODOO_LOGIN_TOKEN');
        if ($base === '' || $token === '') {
            return $base;
        }

        $url = $base.'/_odoo/paas/connect?token='.urlencode($token);
        if (is_string($login) && preg_match('/^[A-Za-z0-9.@+_-]{1,128}$/', $login) === 1) {
            $url .= '&login='.urlencode($login);
        }

        return $url;
    }

    /**
     * Internal Odoo users for this instance. Read from that database: the public host may not resolve yet.
     *
     * @return list<array{name: string, login: string}>
     */
    public static function internalUsers(Service $service): array
    {
        $users = self::internalUsersFromDatabase($service);

        return $users !== [] ? $users : self::internalUsersFromHttp($service);
    }

    /**
     * @return list<array{name: string, login: string}>
     */
    public static function parseInternalUserRows(string $output): array
    {
        $users = [];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, "\x1f")) {
                continue;
            }
            [$login, $name] = array_pad(explode("\x1f", $line, 2), 2, '');
            $login = trim($login);
            if (preg_match('/^[A-Za-z0-9.@+_-]{1,128}$/', $login) !== 1) {
                continue;
            }
            $users[] = ['name' => self::internalUserName($name, $login), 'login' => $login];
        }

        return $users;
    }

    public static function internalUserName(string $raw, string $login): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $login;
        }
        if (str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach (['en_US', 'es_MX', 'es_ES'] as $key) {
                    if (is_string($decoded[$key] ?? null) && $decoded[$key] !== '') {
                        return $decoded[$key];
                    }
                }
                foreach ($decoded as $value) {
                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            }
        }

        return $raw;
    }

    /**
     * @return list<array{name: string, login: string}>
     */
    private static function internalUsersFromDatabase(Service $service): array
    {
        if (app()->runningUnitTests()) {
            return [];
        }
        $server = $service->server;
        $database = self::runtimeValue($service, 'ODOO_DATABASE');
        if ($database === '') {
            $database = (string) (self::databaseName($service) ?? '');
        }
        if ($server === null || ! $server->isFunctional() || preg_match('/\A[A-Za-z0-9_]{1,63}\z/', $database) !== 1) {
            return [];
        }
        if (preg_match('/\A[A-Za-z0-9]+\z/', (string) $service->uuid) !== 1 || preg_match('/\A[1-9][0-9]*\z/', (string) $service->id) !== 1) {
            return [];
        }

        $sql = 'SELECT u.login || chr(31) || COALESCE(p.name::text, chr(32)) FROM res_users u LEFT JOIN res_partner p ON p.id = u.partner_id WHERE u.active IS TRUE AND u.share IS NOT TRUE AND length(u.login) > 0 ORDER BY u.id';
        $script = <<<'BASH'
set -eu
ids="$(docker ps -q --filter label=coolify.serviceId=__ID__; docker ps -q --filter label=com.docker.compose.project=__UUID__)"
pg=""
for id in $ids; do
  image=$(docker inspect --format '{{.Config.Image}}' "$id" 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
  name=$(docker inspect --format '{{.Name}}' "$id" 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
  state=$(docker inspect --format '{{.State.Running}}' "$id" 2>/dev/null || true)
  case "$image$name" in *postgres*) ;; *) continue ;; esac
  if [ "$state" = "true" ]; then pg="$id"; break; fi
done
if [ -z "$pg" ]; then exit 1; fi
docker exec "$pg" sh -c 'psql -U "$POSTGRES_USER" -d __DB__ -tAc "__SQL__"'
BASH;
        $script = str_replace(['__ID__', '__UUID__', '__DB__', '__SQL__'], [(string) $service->id, (string) $service->uuid, $database, $sql], $script);

        try {
            $output = instant_remote_process(['bash -c '.escapeshellarg($script)], $server, false);
        } catch (Throwable) {
            return [];
        }

        return self::parseInternalUserRows((string) $output);
    }

    /**
     * @return list<array{name: string, login: string}>
     */
    private static function internalUsersFromHttp(Service $service): array
    {
        $base = self::publicHttpsUrl($service);
        $token = self::runtimeValue($service, 'ODOO_LOGIN_TOKEN');
        if ($base === '' || $token === '') {
            return [];
        }

        try {
            $response = Http::timeout(8)->withOptions(['verify' => false])->get($base.'/_odoo/paas/users', [
                'token' => $token,
            ]);
        } catch (Throwable) {
            return [];
        }
        $rows = $response->ok() ? $response->json() : null;
        if (! is_array($rows)) {
            return [];
        }

        $users = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $login = (string) ($row['login'] ?? '');
            if (preg_match('/^[A-Za-z0-9.@+_-]{1,128}$/', $login) !== 1) {
                continue;
            }
            $users[] = ['name' => self::internalUserName((string) ($row['name'] ?? ''), $login), 'login' => $login];
        }

        return $users;
    }

    public static function odooSmtpEncryption(?string $mode): string
    {
        return OdooMail::odooSmtpEncryption($mode);
    }

    /**
     * @return list<string>
     */
    public static function mailEnvironmentLines(?object $settings = null, ?int $teamId = null): array
    {
        return OdooMail::mailEnvironmentLines($settings, $teamId);
    }

    public static function baseDomain(): string
    {
        return self::normalizedBaseDomain((string) (instanceSettings()->odoo_base_domain ?? ''));
    }

    public static function normalizedBaseDomain(string $domain): string
    {
        return OdooDomains::normalizedBaseDomain($domain);
    }

    public static function projectHost(string $subdomain, string $baseDomain, ?string $environment = null, int $environmentId = 0, ?bool $isProduction = null): string
    {
        return OdooDomains::projectHost($subdomain, $baseDomain, $environment, $environmentId, $isProduction);
    }

    /**
     * Public host label for one environment. Prefer the branch row; fall back to the
     * project profile so older projects keep working until each environment is saved.
     */
    public static function environmentSubdomain(Project $project, ?Environment $environment = null): string
    {
        $project->loadMissing('odooProfile');
        if ($environment instanceof Environment) {
            $environment->loadMissing('odooBranch');
            $fromBranch = $environment->odooBranch?->subdomain;
            if (filled($fromBranch)) {
                return (string) $fromBranch;
            }
        }

        return (string) ($project->odooProfile?->subdomain ?? '');
    }

    public static function hostFor(Project $project, ?Environment $environment = null): string
    {
        return self::projectHost(
            self::environmentSubdomain($project, $environment),
            self::normalizedBaseDomain((string) (instanceSettings()->odoo_base_domain ?? '')),
            $environment?->name,
            (int) ($environment->id ?? 0),
            $environment instanceof Environment ? $environment->isProduction() : true,
        );
    }

    public static function workerCount(Service $service): int
    {
        $branch = $service->environment?->odooBranch?->workers;
        $profile = $service->environment?->project?->odooProfile?->workers;
        $value = $branch !== null && (int) $branch > 0 ? (int) $branch : (int) $profile;

        return max(0, min(32, $value));
    }

    public static function applyProjectHost(Service $service): bool
    {
        $environment = $service->environment;
        $project = $environment?->project;
        if (! $environment instanceof Environment || ! $project instanceof Project) {
            return false;
        }

        // Project subdomain is Odoo branding only. Catalog services keep their
        // own HTTPS host so Traefik Host/ACME never collide with Odoo.
        if (! OdooJupyter::isOdooCompose((string) $service->docker_compose_raw)) {
            return self::upgradeServiceApplicationsToHttps($service, isOdoo: false);
        }

        $host = self::hostFor($project, $environment);
        $projectUrl = $host !== '' ? 'https://'.$host : null;
        $fallbackUrl = self::coolifyPublicUrl($service);
        $changed = false;

        foreach ($service->applications()->get() as $application) {
            if (! $application instanceof ServiceApplication) {
                continue;
            }
            if (! self::isOdooApplication($application)) {
                continue;
            }
            if ($host === '' && ! self::usesBaseDomain((string) $application->fqdn)) {
                continue;
            }
            $target = $projectUrl ?? $fallbackUrl;
            if ($target === null || $target === '') {
                continue;
            }
            if ((string) $application->fqdn !== $target || ! $application->is_force_https_enabled) {
                $application->fqdn = $target;
                $application->is_force_https_enabled = true;
                $application->save();
                $changed = true;
            }
        }

        $branch = $environment->odooBranch;
        $url = $projectUrl ?? $fallbackUrl;
        if ($url !== null && $branch instanceof OdooEnvironmentBranch && ($host !== '' || self::usesBaseDomain((string) ($branch->domain ?? ''))) && $branch->domain !== $url) {
            $branch->forceFill(['domain' => $url])->save();
            $changed = true;
        }

        return $changed;
    }

    /**
     * The address Coolify assigns from the server, before a project subdomain replaces it.
     */
    public static function coolifyPublicUrl(Service $service): ?string
    {
        $server = $service->server;
        $ip = (string) ($server?->ip ?? '');
        if ($server === null || $ip === '' || preg_match('/\A[A-Za-z0-9.:-]+\z/', $ip) !== 1) {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9]+\z/', (string) $service->uuid) !== 1) {
            return null;
        }
        $prefix = OdooJupyter::isOdooCompose((string) $service->docker_compose_raw)
            ? 'odoo'
            : (str((string) ($service->service_type ?: $service->name))->slug('-')->toString() ?: 'svc');
        $url = self::httpsUrl(generateUrl($server, $prefix.'-'.$service->uuid, true));

        return $url === '' ? null : $url;
    }

    public static function usesBaseDomain(string $fqdn): bool
    {
        $base = self::baseDomain();
        $host = parse_url(self::httpsUrl($fqdn), PHP_URL_HOST);
        if ($base === '' || ! is_string($host) || $host === '') {
            return false;
        }

        return $host === $base || str_ends_with($host, '.'.$base);
    }

    /**
     * Databases on this instance's Postgres. A row is disabled only when Postgres rejects new connections.
     *
     * @return array<int, array{name: string, disabled: bool}>
     */
    public static function databaseList(Service $service): array
    {
        $active = self::databaseName($service);
        $fallback = $active === null ? [] : [['name' => $active, 'disabled' => false]];
        $server = $service->server;
        if ($active === null || $server === null || ! $server->isFunctional()) {
            return $fallback;
        }

        $command = 'docker exec '.escapeshellarg('postgresql-'.$service->uuid).' sh -c '.escapeshellarg('psql -U "$POSTGRES_USER" -d postgres -tAc "SELECT datname || \'|\' || datallowconn FROM pg_database WHERE NOT datistemplate AND datname <> \'postgres\' ORDER BY 1"');
        try {
            $output = instant_remote_process([$command], $server, false);
        } catch (Throwable) {
            return $fallback;
        }

        $rows = [];
        foreach (preg_split("/\r\n|\n|\r/", (string) $output) ?: [] as $line) {
            $line = trim($line);
            if (! str_contains($line, '|')) {
                continue;
            }
            [$name, $allowed] = array_pad(explode('|', $line, 2), 2, '');
            $name = trim($name);
            if ($name === '' || ! preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'disabled' => ! in_array(strtolower(trim($allowed)), ['t', 'true', '1'], true),
            ];
        }

        return $rows === [] ? $fallback : $rows;
    }

    public static function useHttps(Service $service): bool
    {
        $isOdoo = OdooJupyter::isOdooCompose((string) $service->docker_compose_raw);
        $project = $service->environment?->project;

        // Project subdomain applies to Odoo only. Non-Odoo must keep a distinct
        // HTTPS FQDN or Traefik has no Host rule / no certresolver for them.
        if ($isOdoo && $project instanceof Project && self::hostFor($project, $service->environment) !== '') {
            return self::applyProjectHost($service);
        }

        return self::upgradeServiceApplicationsToHttps($service, $isOdoo);
    }

    /**
     * Force https:// on public apps (scheme-less Coolify hosts included) and
     * assign a Coolify/sslip URL when no public app has an FQDN yet.
     *
     * Sidecars (workers, DBs, …) never receive the fallback host. Auto-generated
     * sslip/nip FQDNs on sidecars are cleared when a primary HTTP app exists so
     * Traefik ACME and Open hit the real entrypoint (e.g. authentik-server).
     */
    private static function upgradeServiceApplicationsToHttps(Service $service, bool $isOdoo): bool
    {
        $changed = false;
        $applications = $service->applications()->get()
            ->filter(fn ($application): bool => $application instanceof ServiceApplication);

        $hasPrimaryCandidate = $applications->contains(
            fn (ServiceApplication $application): bool => ! self::isLikelySidecarApplication($application)
        );

        foreach ($applications as $application) {
            if ($isOdoo && ! self::isOdooApplication($application)) {
                continue;
            }

            if (
                ! $isOdoo
                && $hasPrimaryCandidate
                && self::isLikelySidecarApplication($application)
                && self::isCoolifyGeneratedServiceFqdn($service, (string) $application->fqdn)
            ) {
                $application->fqdn = null;
                $application->is_force_https_enabled = false;
                $application->save();
                $changed = true;

                continue;
            }

            if (! $isOdoo && self::isLikelySidecarApplication($application) && $hasPrimaryCandidate) {
                continue;
            }

            // Capture :port from the FQDN before httpsUrl() strips it — Traefik
            // needs that listen port via ServiceApplication.ports / onlyPort.
            if (blank($application->ports)) {
                $listenPort = collect(explode(',', (string) $application->fqdn))
                    ->map(function (string $domain): ?string {
                        $domain = trim($domain);
                        if ($domain === '') {
                            return null;
                        }
                        if (! str_contains($domain, '://')) {
                            $domain = 'http://'.$domain;
                        }
                        $port = parse_url($domain, PHP_URL_PORT);

                        return is_numeric($port) ? (string) (int) $port : null;
                    })
                    ->filter()
                    ->first();
                if ($listenPort) {
                    $application->ports = $listenPort;
                    $changed = true;
                }
            }

            $fqdn = collect(explode(',', (string) $application->fqdn))
                ->map(fn (string $domain): string => self::httpsUrl(trim($domain)))
                ->filter()
                ->implode(',');

            if ($fqdn === '') {
                continue;
            }

            if ($fqdn !== (string) $application->fqdn) {
                $application->fqdn = $fqdn;
                $changed = true;
            }
            if (! $application->is_force_https_enabled) {
                $application->is_force_https_enabled = true;
                $changed = true;
            }
            if ($application->isDirty()) {
                $application->save();
            }
        }

        if ($isOdoo) {
            return $changed;
        }

        $hasPublicFqdn = $service->applications()->get()->contains(
            function ($application) use ($hasPrimaryCandidate): bool {
                if (! $application instanceof ServiceApplication || trim((string) $application->fqdn) === '') {
                    return false;
                }
                if ($hasPrimaryCandidate && self::isLikelySidecarApplication($application)) {
                    return false;
                }

                return true;
            }
        );

        if ($hasPublicFqdn) {
            return $changed;
        }

        $target = self::preferredPublicHttpApplication($service);
        $fallback = self::coolifyPublicUrl($service);
        if (! $target instanceof ServiceApplication || $fallback === null) {
            return $changed;
        }

        if ((string) $target->fqdn !== $fallback || ! $target->is_force_https_enabled) {
            $target->fqdn = $fallback;
            $target->is_force_https_enabled = true;
            $target->save();
            $changed = true;
        }

        return $changed;
    }

    /**
     * @return array{url: string, status: string, message: string}
     */
    public static function certificateStatus(Service $service): array
    {
        $application = $service->applications()->get()->first(
            fn ($application): bool => $application instanceof ServiceApplication && self::isOdooApplication($application)
        );
        $url = $application instanceof ServiceApplication
            ? self::httpsUrl(firstDomainFromList((string) $application->fqdn))
            : '';
        if ($url === '' || parse_url($url, PHP_URL_HOST) === null) {
            return [
                'url' => '',
                'status' => 'missing',
                'message' => 'This Odoo service has no public URL yet.',
            ];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (self::acmeHasCertificate($service, $host)) {
            return [
                'url' => $url,
                'status' => 'applied',
                'message' => 'Let\'s Encrypt already issued the certificate for :url.',
            ];
        }

        $parsed = self::peerCertificate($host);
        if ($parsed === null) {
            return [
                'url' => $url,
                'status' => 'unreachable',
                'message' => 'Could not connect to :url on port 443. The certificate cannot be confirmed yet.',
            ];
        }

        $issuerCn = (string) ($parsed['issuer']['CN'] ?? '');
        $subjectCn = (string) ($parsed['subject']['CN'] ?? '');
        $issuerOrg = (string) ($parsed['issuer']['O'] ?? '');
        if (self::classifyCertificateIssuer($issuerCn, $subjectCn, $issuerOrg) === 'applied') {
            return [
                'url' => $url,
                'status' => 'applied',
                'message' => 'Let\'s Encrypt already issued the certificate for :url.',
            ];
        }

        return [
            'url' => $url,
            'status' => 'pending',
            'message' => 'Traefik is still serving its temporary certificate for :url. It asks Let\'s Encrypt when the service is deployed. The browser shows the page as insecure until this check says the certificate is applied. Port 80 of the proxy must reach this server.',
        ];
    }

    public static function classifyCertificateIssuer(string $issuerCn, string $subjectCn, string $issuerOrg): string
    {
        $issuer = strtolower($issuerCn.' '.$issuerOrg);
        if ($issuerCn === '' && $issuerOrg === '') {
            return 'pending';
        }
        if (str_contains($issuer, 'traefik') || str_contains($issuer, 'default cert') || ($issuerCn !== '' && $issuerCn === $subjectCn)) {
            return 'pending';
        }

        return 'applied';
    }

    public static function httpsUrl(string $fqdn): string
    {
        $fqdn = trim($fqdn);
        if ($fqdn === '') {
            return '';
        }
        if (! str_contains($fqdn, '://')) {
            $fqdn = 'https://'.$fqdn;
        }
        $parts = parse_url($fqdn);
        if (! is_array($parts) || empty($parts['host'])) {
            return 'https://'.preg_replace('#^https?://#', '', $fqdn);
        }
        $path = ($parts['path'] ?? '') === '/' ? '' : (string) ($parts['path'] ?? '');

        return 'https://'.$parts['host'].$path;
    }

    private static function acmeHasCertificate(Service $service, string $host): bool
    {
        if (! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return false;
        }
        $server = $service->server;
        if ($server === null || ! $server->isFunctional()) {
            return false;
        }

        $needle = escapeshellarg('"main":"'.$host.'"');
        $needleSpaced = escapeshellarg('"main": "'.$host.'"');
        $proxy = 'coolify-proxy';
        if ($server->usesExternalProxyNetworkConnect()) {
            $filter = escapeshellarg((string) $server->externalProxyName());
            $proxy = "\$(docker ps --filter name={$filter} --format '{{.Names}}' | head -1)";
        }
        try {
            $output = instant_remote_process([
                "PROXY={$proxy}; if [ -n \"\$PROXY\" ] && docker exec \"\$PROXY\" grep -F -e {$needle} -e {$needleSpaced} /traefik/acme.json >/dev/null 2>&1; then echo applied; else echo pending; fi",
            ], $server, false);
        } catch (Throwable) {
            return false;
        }

        return str_contains((string) $output, 'applied');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function peerCertificate(string $host): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client('ssl://'.$host.':443', $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            return null;
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! is_resource($certificate) && ! $certificate instanceof \OpenSSLCertificate) {
            return null;
        }
        $parsed = openssl_x509_parse($certificate);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * One remote command. HTTPS starts only after Odoo and PostgreSQL are running.
     */
    public static function containersReadyCommand(Service $service): string
    {
        $uuid = (string) $service->uuid;
        $serviceId = (string) $service->id;
        if (preg_match('/^[A-Za-z0-9]+$/', $uuid) !== 1 || preg_match('/^[1-9][0-9]*$/', $serviceId) !== 1) {
            throw new RuntimeException('The staging service did not start.');
        }

        $script = <<<'BASH'
set -eu
project=__UUID__
service_id=__ID__
need_jupyter=0
ready=0
for i in $(seq 1 60); do
  ids="$(docker ps -q --filter "label=com.docker.compose.project=${project}"; docker ps -q --filter "label=coolify.serviceId=${service_id}")"
  pg=0
  oo=0
  ju=0
  for id in $ids; do
    image=$(docker inspect --format '{{.Config.Image}}' "$id" 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
    name=$(docker inspect --format '{{.Name}}' "$id" 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
    case "$name" in *stdlib*|*jupyterowner*) continue ;; esac
    subtype=$(docker inspect --format '{{ index .Config.Labels "coolify.service.subType" }}' "$id" 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
    case "$image$name" in *postgres*) pg=1 ;; esac
    if [ "$subtype" = "database" ]; then pg=1; fi
    case "$image$name" in *jupyter*) ju=1 ;; esac
    case "$image" in odoo:*|*/odoo:*) oo=1 ;; esac
    case "$name" in *odoo*) case "$name" in *jupyter*) ;; *) oo=1 ;; esac ;; esac
  done
  if [ "$pg" = 1 ] && [ "$oo" = 1 ] && { [ "$need_jupyter" != 1 ] || [ "$ju" = 1 ]; }; then
    echo "The service containers are running."
    ready=1
    break
  fi
  echo "Waiting until the service containers are running."
  sleep 5
done
if [ "$ready" != 1 ]; then
  echo "The staging containers are not running yet."
  exit 1
fi
BASH;

        $script = str_replace(
            ['__UUID__', '__ID__'],
            [$uuid, $serviceId],
            $script,
        );

        return 'bash -c '.escapeshellarg($script);
    }

    /**
     * One remote command. The deploy stays running until this host has a real certificate and Odoo answers.
     */
    public static function httpsReadyCommand(string $host): ?string
    {
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        $script = 'host='.escapeshellarg($host).'; ready=0; for i in $(seq 1 120); do issuer=$(echo | openssl s_client -connect 127.0.0.1:443 -servername "$host" 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null || true); code=$(curl -skL --resolve "$host:443:127.0.0.1" --max-time 8 -o /tmp/gpsh-https-body -w "%{http_code}" "https://$host/web/login" || true); if printf "%s" "$issuer" | grep -qi encrypt && printf "%s" "$issuer" | grep -qiv traefik && case "$code" in 2*|3*) true ;; *) false ;; esac && ! grep -q "no available server" /tmp/gpsh-https-body && ! grep -q "se actualiza sola" /tmp/gpsh-https-body && grep -Eqi "oe_login|/web/login|odoo" /tmp/gpsh-https-body; then echo "Odoo is ready for $host"; ready=1; break; fi; echo "Waiting until Odoo can be opened on $host ($code)"; sleep 5; done; rm -f /tmp/gpsh-https-body; test "$ready" = 1';

        return 'bash -c '.escapeshellarg($script);
    }

    public static function startIfPossible(Service $service): void
    {
        $service->refresh();
        if ($service->server?->isFunctional()) {
            StartService::dispatch($service);
        }
    }

    private static function assertRepositoryFree(string $gitRepository, int $projectId): void
    {
        $taken = OdooProfile::query()
            ->where('git_repository', $gitRepository)
            ->where('project_id', '!=', $projectId)
            ->exists();
        if ($taken) {
            throw new InvalidArgumentException('That repository is already used by another project.');
        }
    }

    public static function isOdooApplication(ServiceApplication $application): bool
    {
        $name = strtolower((string) $application->name);
        $image = strtolower((string) $application->image);

        return $name === 'odoo' || str_starts_with($image, 'odoo:') || str_contains($image, '/odoo:');
    }

    private static function rememberVariable(Service $service, string $key, string $value, bool $overwrite): void
    {
        $existing = $service->environment_variables()->where('key', $key)->first();
        if ($existing !== null && ! $overwrite) {
            return;
        }
        if ($existing !== null) {
            $existing->value = $value;
            $existing->save();

            return;
        }

        $service->environment_variables()->create([
            'key' => $key,
            'value' => $value,
            'is_preview' => false,
            'is_runtime' => true,
        ]);
    }

    /**
     * @return array{login: string, type: string}
     */
    public static function accountLogin(GithubApp $githubApp): string
    {
        return self::installationAccount($githubApp)['login'];
    }

    public static function repositoryUrl(string $repository, string $branch): string
    {
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
            return '';
        }

        $branch = trim($branch);
        if ($branch === '' || preg_match('/^[a-zA-Z0-9\-_\/.]+$/', $branch) !== 1) {
            return 'https://github.com/'.$repository;
        }

        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $branch)));

        return 'https://github.com/'.$repository.'/tree/'.$encoded;
    }

    public static function installedApp(int $teamId, ?int $userId): ?GithubApp
    {
        return self::userApp($teamId, $userId);
    }

    public static function configuredAppName(): string
    {
        $stored = instanceSettings()->github_app_name ?? null;
        $raw = is_string($stored) && $stored !== '' ? $stored : 'gpsh1';
        $name = strtolower((string) preg_replace('/[^a-z0-9-]/i', '', $raw));
        $name = trim($name, '-');

        return $name !== '' ? substr($name, 0, 34) : 'gpsh1';
    }

    public static function userApp(int $teamId, ?int $userId): ?GithubApp
    {
        $connected = self::connectedApps($teamId);
        if ($userId !== null) {
            $id = DB::table('team_user')
                ->where('user_id', $userId)
                ->where('team_id', $teamId)
                ->value('github_app_id');
            if ($id !== null) {
                $match = $connected->firstWhere('id', (int) $id);
                if ($match instanceof GithubApp) {
                    return $match;
                }
            }
        }

        return $connected->sortBy('id')->first();
    }

    /**
     * Prefer the GitHub App already stored on the project profile, then the user's remembered app.
     */
    public static function appForProject(Project $project, ?int $userId): ?GithubApp
    {
        $connected = self::connectedApps((int) $project->team_id);
        $profileAppId = (int) ($project->odooProfile?->github_app_id ?? 0);
        if ($profileAppId > 0) {
            $match = $connected->firstWhere('id', $profileAppId);
            if ($match instanceof GithubApp) {
                return $match;
            }
        }

        return self::userApp((int) $project->team_id, $userId);
    }

    /**
     * Add the invited person to each Odoo project's repository.
     * GitHub identifies collaborators by login, so an email GitHub cannot match is skipped.
     * A failed call does not undo the team invite.
     */
    public static function inviteEmailToRepositories(int $teamId, string $email): void
    {
        if ($email === '' || ! str_contains($email, '@')) {
            return;
        }

        $profiles = OdooProfile::query()
            ->whereNotNull('git_repository')
            ->whereHas('project', fn ($query) => $query->where('team_id', $teamId))
            ->with('githubApp')
            ->get();

        foreach ($profiles as $profile) {
            $app = $profile->githubApp;
            $repository = (string) $profile->git_repository;
            if (! $app instanceof GithubApp || ! str_contains($repository, '/')) {
                continue;
            }

            try {
                self::inviteCollaborator($app, $repository, $email);
            } catch (Throwable) {
                // The team invite still stands when GitHub cannot add the collaborator.
            }
        }
    }

    public static function inviteCollaborator(GithubApp $app, string $repository, string $email): void
    {
        $login = self::githubLoginForEmail($app, $email);
        if ($login === null || preg_match('#^[^/\s]+/[^/\s]+$#', $repository) !== 1) {
            return;
        }

        githubApi($app, '/repos/'.$repository.'/collaborators/'.rawurlencode($login), 'put', [
            'permission' => 'push',
        ], false);
    }

    public static function inviteGithubLogin(OdooProfile $profile, string $login): void
    {
        $login = self::githubLogin($login);
        $app = $profile->githubApp;
        $repository = (string) $profile->git_repository;
        if (! $app instanceof GithubApp || preg_match('#^[^/\s]+/[^/\s]+$#', $repository) !== 1) {
            throw new InvalidArgumentException('Connect a GitHub repository before inviting someone.');
        }
        if ($login === '') {
            throw new InvalidArgumentException('The GitHub username can only use letters, numbers, and hyphens.');
        }

        githubApi($app, '/repos/'.$repository.'/collaborators/'.rawurlencode($login), 'put', [
            'permission' => 'push',
        ]);
    }

    public static function removeGithubLogin(OdooProfile $profile, string $login): void
    {
        $login = self::githubLogin($login);
        $app = $profile->githubApp;
        $repository = (string) $profile->git_repository;
        if ($login === '' || ! $app instanceof GithubApp || preg_match('#^[^/\s]+/[^/\s]+$#', $repository) !== 1) {
            return;
        }

        githubApi($app, '/repos/'.$repository.'/collaborators/'.rawurlencode($login), 'delete');
    }

    /**
     * @return list<array{login: string}>
     */
    public static function repositoryCollaborators(OdooProfile $profile): array
    {
        $app = $profile->githubApp;
        $repository = (string) $profile->git_repository;
        if (! $app instanceof GithubApp || preg_match('#^[^/\s]+/[^/\s]+$#', $repository) !== 1) {
            return [];
        }

        $response = githubApi($app, '/repos/'.$repository.'/collaborators', 'get', null, false);
        $rows = data_get($response, 'data');
        if ($rows instanceof Collection) {
            $rows = $rows->all();
        }
        if (! is_array($rows)) {
            return [];
        }

        $people = [];
        foreach ($rows as $row) {
            $login = is_array($row) ? (string) ($row['login'] ?? '') : '';
            if (self::githubLogin($login) !== '') {
                $people[] = ['login' => $login];
            }
        }

        return $people;
    }

    private static function githubLogin(string $login): string
    {
        $login = trim($login);

        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $login) === 1 ? $login : '';
    }

    private static function githubLoginForEmail(GithubApp $app, string $email): ?string
    {
        $response = githubApi($app, '/search/users?q='.rawurlencode($email.' in:email'), 'get', null, false);
        $login = data_get($response, 'data.items.0.login');

        return is_string($login) && $login !== '' ? $login : null;
    }

    public static function rememberForUser(int $userId, int $teamId, GithubApp $githubApp): void
    {
        DB::table('team_user')
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->update(['github_app_id' => $githubApp->id]);
    }

    /**
     * @return array{login: string, type: 'User'|'Organization'}
     */
    public static function installationAccount(GithubApp $githubApp): array
    {
        $cached = Cache::get('github-installation-account:'.$githubApp->id);
        if (is_array($cached) && filled($cached['login'] ?? null) && filled($cached['type'] ?? null)) {
            return ['login' => (string) $cached['login'], 'type' => (string) $cached['type']];
        }

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

        $account = ['login' => $login, 'type' => $type];
        Cache::put('github-installation-account:'.$githubApp->id, $account, 3600);

        return $account;
    }

    public static function cannotAutoCreateOnPersonalAccountMessage(): string
    {
        return __('GitHub Apps cannot create repositories on a personal account — Administration write is not enough. Install the App on a GitHub Organization (auto-create works there), or create the repository yourself and choose an existing one on launch.');
    }

    /**
     * @return array{full_name: string, id: int}
     */
    private static function createRepository(GithubApp $githubApp, string $type, string $owner, string $name, string $projectName): array
    {
        // Installation tokens can create repos on Organizations only. POST /user/repos needs a
        // user-to-server OAuth token; GitHub often answers that with a misleading 403 "Rate Limit Exceeded".
        if ($type !== 'Organization') {
            throw new RuntimeException(self::cannotAutoCreateOnPersonalAccountMessage());
        }

        // Drop a cached installation token so Administration/Contents updates apply.
        forgetGithubInstallationToken($githubApp);

        $created = githubApi($githubApp, '/orgs/'.rawurlencode($owner).'/repos', 'post', [
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
            throw githubFailedRequestException($created);
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

    private static function defaultBranch(GithubApp $githubApp, string $owner, string $name): string
    {
        $details = githubApi($githubApp, '/repos/'.rawurlencode($owner).'/'.rawurlencode($name));
        $default = (string) data_get($details, 'data.default_branch', 'main');

        return $default !== '' ? $default : 'main';
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

    /**
     * Create or keep the staging branch the user chose.
     * A branch already stored on this project is rejected.
     * If the requested source is missing, the repository default branch is the source.
     *
     * @param  list<string>  $used
     */
    public static function prepareStagingBranch(GithubApp $githubApp, string $fullName, string $source, string $target, array $used): void
    {
        $target = trim($target);
        $used = array_values(array_unique(array_map(strval(...), $used)));
        if (in_array($target, $used, true)) {
            throw new InvalidArgumentException('That branch is already used by this repository.');
        }
        $check = Validator::make(['branch' => $target], ['branch' => ['required', 'string', new ValidGitBranch]]);
        if ($check->fails()) {
            throw new InvalidArgumentException('The GitHub branch name is invalid.');
        }

        [$owner, $name] = self::splitRepository($fullName);
        $repo = rawurlencode($owner).'/'.rawurlencode($name);
        $existing = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($target), 'get', null, false);
        if (filled(data_get($existing, 'data.object.sha'))) {
            return;
        }

        $source = trim($source);
        $sha = '';
        if ($source !== '' && $source !== $target) {
            $head = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($source), 'get', null, false);
            $sha = (string) data_get($head, 'data.object.sha');
        }
        if ($sha === '') {
            $details = githubApi($githubApp, "/repos/{$repo}");
            $default = (string) data_get($details, 'data.default_branch', '');
            if ($default === '' || $default === $target) {
                throw new RuntimeException('The source branch does not exist on GitHub.');
            }
            $head = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($default), 'get', null, false);
            $sha = (string) data_get($head, 'data.object.sha');
        }
        if ($sha === '') {
            throw new RuntimeException('The source branch does not exist on GitHub.');
        }

        githubApi($githubApp, "/repos/{$repo}/git/refs", 'post', [
            'ref' => 'refs/heads/'.$target,
            'sha' => $sha,
        ]);
    }

    /**
     * Create a brand-new staging branch. Fails if the name already exists on GitHub or is used by this project.
     * Source is production's branch when provided; otherwise the repository default branch.
     *
     * @param  list<string>  $used
     */
    public static function createStagingBranch(GithubApp $githubApp, string $fullName, string $source, string $target, array $used): void
    {
        $target = trim($target);
        $used = array_values(array_unique(array_map(strval(...), $used)));
        if (in_array($target, $used, true)) {
            throw new InvalidArgumentException('That branch is already used by this repository.');
        }
        $check = Validator::make(['branch' => $target], ['branch' => ['required', 'string', new ValidGitBranch]]);
        if ($check->fails()) {
            throw new InvalidArgumentException('The GitHub branch name is invalid.');
        }

        [$owner, $name] = self::splitRepository($fullName);
        $repo = rawurlencode($owner).'/'.rawurlencode($name);
        $existing = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($target), 'get', null, false);
        if (filled(data_get($existing, 'data.object.sha'))) {
            throw new InvalidArgumentException('That branch already exists on GitHub. Create a new name, or choose Use an existing branch.');
        }

        $source = trim($source);
        $sha = '';
        if ($source !== '' && $source !== $target) {
            $head = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($source), 'get', null, false);
            $sha = (string) data_get($head, 'data.object.sha');
        }
        if ($sha === '') {
            $details = githubApi($githubApp, "/repos/{$repo}");
            $default = (string) data_get($details, 'data.default_branch', '');
            if ($default === '' || $default === $target) {
                throw new RuntimeException('The source branch does not exist on GitHub.');
            }
            $head = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($default), 'get', null, false);
            $sha = (string) data_get($head, 'data.object.sha');
        }
        if ($sha === '') {
            throw new RuntimeException('The source branch does not exist on GitHub.');
        }

        githubApi($githubApp, "/repos/{$repo}/git/refs", 'post', [
            'ref' => 'refs/heads/'.$target,
            'sha' => $sha,
        ]);
    }

    public static function cloneBranch(GithubApp $githubApp, string $fullName, string $fromBranch, string $toBranch): void
    {
        if ($fromBranch === $toBranch) {
            throw new RuntimeException('The new branch must be different from the source branch.');
        }

        [$owner, $name] = self::splitRepository($fullName);
        $repo = rawurlencode($owner).'/'.rawurlencode($name);
        $source = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($fromBranch), 'get', null, false);
        $sha = (string) data_get($source, 'data.object.sha');
        if ($sha === '') {
            throw new RuntimeException('The source branch does not exist on GitHub.');
        }

        $existing = githubApi($githubApp, "/repos/{$repo}/git/ref/heads/".rawurlencode($toBranch), 'get', null, false);
        if (filled(data_get($existing, 'data.object.sha'))) {
            throw new RuntimeException('That branch already exists on GitHub.');
        }

        githubApi($githubApp, "/repos/{$repo}/git/refs", 'post', [
            'ref' => 'refs/heads/'.$toBranch,
            'sha' => $sha,
        ]);
    }

    /**
     * alpine/git runs as root against a volume often owned by Odoo/Jupyter (100:101).
     * Without safe.directory, git aborts with "detected dubious ownership".
     */
    public static function gitSafeDirectorySetup(): string
    {
        return 'git config --global --add safe.directory /addons; '
            .'git config --global --add safe.directory "*"; ';
    }

    /**
     * @return list<string>
     */
    public static function cloneCommands(string $volume, string $cloneUrl, string $branch): array
    {
        // Preserve .gpsh (logs / owner-package markers). A bare `git init` without a
        // working tree used to make the fetch path a no-op and leave Jupyter empty.
        // Drop stale *.lock files — concurrent syncs / killed containers leave index.lock
        // and make a good volume look like a failed update.
        $clearLocks = 'rm -f /addons/.git/index.lock /addons/.git/HEAD.lock '
            .'/addons/.git/refs/heads/*.lock /addons/.git/refs/remotes/*/HEAD.lock 2>/dev/null || true; ';
        $script = 'set -e; '
            .self::gitSafeDirectorySetup()
            .'mkdir -p /addons; '
            .$clearLocks
            .'if [ -d /addons/.gpsh ]; then cp -a /addons/.gpsh /tmp/gpsh-keep; fi; '
            .'if [ -d /addons/.git ] && git -C /addons remote get-url origin >/dev/null 2>&1 '
            .'&& git -C /addons rev-parse --verify HEAD >/dev/null 2>&1; then '
            .'git -C /addons remote set-url origin '.escapeshellarg($cloneUrl).'; '
            .$clearLocks
            .'git -C /addons fetch --depth 1 origin '.escapeshellarg($branch).'; '
            .$clearLocks
            .'git -C /addons checkout -B '.escapeshellarg($branch).' FETCH_HEAD; '
            .'else '
            .'find /addons -mindepth 1 -maxdepth 1 ! -name .gpsh -exec rm -rf {} +; '
            .'git clone --depth 1 --branch '.escapeshellarg($branch).' '.escapeshellarg($cloneUrl).' /tmp/src; '
            .'cp -a /tmp/src/. /addons/; '
            .'fi; '
            .'if [ -d /tmp/gpsh-keep ]; then mkdir -p /addons/.gpsh; cp -a /tmp/gpsh-keep/. /addons/.gpsh/; fi; '
            .'test -d /addons/.git; '
            .'chown -R 100:101 /addons || true; '
            .'chmod -R a+rX /addons || true';

        return [
            'docker volume create '.escapeshellarg($volume),
            'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons alpine/git -c '.escapeshellarg($script),
        ];
    }

    public static function cloneIntoService(Service $service): void
    {
        if (! $service->supportsOdooJupyter()) {
            return;
        }

        $service->loadMissing('environment.project.odooProfile.githubApp', 'environment.odooBranch', 'destination.server');
        $profile = $service->environment?->project?->odooProfile;
        $environment = $service->environment;
        $repository = $environment instanceof Environment
            ? self::environmentGitRepository($environment)
            : $profile?->git_repository;
        $githubApp = $profile?->githubApp;
        $server = $service->destination?->server;

        // No GitHub on the project yet — Jupyter-only until a repository is linked.
        if (blank($repository) && blank($profile?->github_app_id)) {
            return;
        }

        if (blank($repository) || ! $githubApp instanceof GithubApp) {
            throw new RuntimeException('GitHub is linked incompletely for this project. Open the service GitHub panel and save the branch again.');
        }
        if ($server === null) {
            throw new RuntimeException('This Odoo service has no server destination, so addons cannot be cloned.');
        }

        if (app()->runningUnitTests()) {
            return;
        }

        // Only the linked git_branch is valid. Never fall back to the environment
        // name (a second production named "odoo" is not a remote branch).
        $branch = trim((string) ($service->environment?->odooBranch?->git_branch ?? ''));
        if ($branch === '') {
            return;
        }
        $host = parse_url((string) $githubApp->html_url, PHP_URL_HOST) ?: 'github.com';
        $token = generateGithubInstallationToken($githubApp);
        if (blank($token)) {
            throw new RuntimeException('GitHub did not return an installation token. Reconnect the GitHub App and try again.');
        }
        $url = 'https://x-access-token:'.rawurlencode((string) $token).'@'.$host.'/'.$repository.'.git';
        $volume = OdooAddons::extraAddonsVolume($service);
        // Wait for a concurrent clone (webhook + start/retry) instead of failing immediately.
        // Owner auto-releases when the callback returns; TTL covers a crashed worker.
        $lock = Cache::lock('odoo-addons-clone:'.$volume, 300);

        try {
            $lock->block(90, function () use ($volume, $url, $branch, $server, $repository): void {
                try {
                    instant_remote_process(self::cloneCommands($volume, $url, (string) $branch), $server);
                } catch (Throwable $e) {
                    // One automatic retry after clearing locks when a stale index.lock blocked git.
                    if (! str_contains($e->getMessage(), 'index.lock')) {
                        throw $e;
                    }
                    instant_remote_process([
                        'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons alpine/git -c '.escapeshellarg(
                            'rm -f /addons/.git/index.lock /addons/.git/HEAD.lock /addons/.git/refs/heads/*.lock 2>/dev/null || true'
                        ),
                    ], $server, false);
                    instant_remote_process(self::cloneCommands($volume, $url, (string) $branch), $server);
                }
                self::assertAddonsVolumeCloned($server, $volume, (string) $repository, (string) $branch);
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException(
                "Timed out waiting for another module update on {$volume} to finish. Retry in a moment.",
                previous: $e,
            );
        }
    }

    /**
     * Loading UI must not flip to "ready" when the volume still only has GPSH logs.
     */
    public static function assertAddonsVolumeCloned(Server $server, string $volume, string $repository, string $branch): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/', $volume) !== 1) {
            throw new RuntimeException('Invalid addons volume name.');
        }

        $listing = instant_remote_process([
            'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons:ro alpine/git -c '.escapeshellarg(
                'set -e; '.self::gitSafeDirectorySetup()
                .'test -d /addons/.git; git -C /addons rev-parse --verify HEAD >/dev/null; '
                .'ls -la /addons | head -n 40'
            ),
        ], $server);

        $listing = trim((string) $listing);
        if ($listing === '' || ! str_contains($listing, '.git')) {
            throw new RuntimeException(
                "Clone of {$repository}@{$branch} into {$volume} did not leave a git checkout. Jupyter stays empty until this succeeds."
            );
        }

        // Only GPSH runtime files (no module / repo content besides .git / .gpsh / helper scripts).
        $meaningful = instant_remote_process([
            'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons:ro alpine -c '.escapeshellarg(
                'find /addons -mindepth 1 -maxdepth 1 '
                .'! -name .git ! -name .gpsh ! -name odoo-logs.sh ! -name odoo-logs.log ! -name .gitignore '
                .'| head -n 5'
            ),
        ], $server, false);

        if (trim((string) $meaningful) === '') {
            throw new RuntimeException(
                "Clone of {$repository}@{$branch} into {$volume} produced no addon files (volume only has GPSH helpers). Check that the branch has modules, then save the branch again."
            );
        }
    }

    /**
     * After files land in /mnt/extra-addons, Odoo still needs update_list() (Apps → Update Apps List).
     */
    public static function refreshAppsList(Service $service): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (! $service->supportsOdooJupyter()) {
            return;
        }

        try {
            $service->loadMissing('destination.server');
            $server = $service->destination?->server;
            $database = self::databaseName($service);
            if ($server === null || blank($database) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/', (string) $service->uuid) !== 1) {
                return;
            }

            $container = 'odoo-'.$service->uuid;
            $script = "env['ir.module.module'].update_list()\n";
            $encoded = base64_encode($script);
            $inner = 'printf %s '.escapeshellarg($encoded).' | base64 -d | odoo shell --no-http --max-cron-threads=0 '
                .'--addons-path=/mnt/extra-addons,/usr/lib/python3/dist-packages/odoo/addons '
                .'-d '.escapeshellarg((string) $database);

            // Best-effort only: a hung odoo shell must not fail a sync that already cloned modules.
            instant_remote_process([
                'docker exec '.escapeshellarg($container).' sh -c '.escapeshellarg($inner),
            ], $server, false, false, 45);
        } catch (Throwable) {
            // Restart already applied the new addons; Apps → Update Apps List can be run manually.
        }
    }

    /**
     * Project already running without GitHub: create the repo, save it on the
     * profile, and push whatever is already in the custom addon volume.
     */
    public static function associateNewRepository(Service $service, GithubApp $githubApp): void
    {
        $service->loadMissing('environment.project.odooProfile', 'destination.server');
        $project = $service->environment?->project;
        if ($project === null) {
            throw new InvalidArgumentException('Odoo is not enabled for this project.');
        }
        if (filled($project->odooProfile?->git_repository)) {
            throw new InvalidArgumentException('This project already has a GitHub repository.');
        }

        $classification = $service->environment?->isStaging() ? 'staging' : 'production';
        self::launchEnvironment($project, $githubApp, $classification);
        $userId = auth()->id();
        if ($userId !== null) {
            self::rememberForUser((int) $userId, (int) $project->team_id, $githubApp);
        }
        self::pushAddonsFromService($service->fresh() ?? $service);
    }

    public static function pushAddonsFromService(Service $service): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (! $service->supportsOdooJupyter()) {
            return;
        }

        $service->loadMissing('environment.project.odooProfile.githubApp', 'environment.odooBranch', 'destination.server');
        $profile = $service->environment?->project?->odooProfile;
        $environment = $service->environment;
        $repository = $environment instanceof Environment
            ? self::environmentGitRepository($environment)
            : $profile?->git_repository;
        $githubApp = $profile?->githubApp;
        $server = $service->destination?->server;
        if (blank($repository) || ! $githubApp instanceof GithubApp || $server === null) {
            return;
        }

        $branch = $service->environment?->odooBranch?->git_branch ?: 'main';
        $host = parse_url((string) $githubApp->html_url, PHP_URL_HOST) ?: 'github.com';
        $token = generateGithubInstallationToken($githubApp);
        $url = 'https://x-access-token:'.rawurlencode((string) $token).'@'.$host.'/'.$repository.'.git';
        instant_remote_process(self::pushCommands(OdooAddons::extraAddonsVolume($service), $url, (string) $branch), $server, false);
    }

    /**
     * @return list<string>
     */
    public static function pushCommands(string $volume, string $cloneUrl, string $branch): array
    {
        $script = 'set -e; '
            .self::gitSafeDirectorySetup()
            .'printf %s\\n ".gpsh/" > /addons/.gitignore; '
            .'if [ ! -d /addons/.git ]; then git -C /addons init; fi; '
            .'git -C /addons config user.email "gpsh@localhost"; '
            .'git -C /addons config user.name "GPSH"; '
            .'git -C /addons remote remove origin 2>/dev/null || true; '
            .'git -C /addons remote add origin '.escapeshellarg($cloneUrl).'; '
            .'git -C /addons fetch --depth 1 origin '.escapeshellarg($branch).' || true; '
            .'git -C /addons checkout -B '.escapeshellarg($branch).'; '
            .'git -C /addons add -A; '
            .'if git -C /addons diff --cached --quiet; then exit 0; fi; '
            .'git -C /addons commit -m "GPSH custom addons"; '
            .'git -C /addons push -u origin '.escapeshellarg($branch);

        return [
            'docker volume create '.escapeshellarg($volume),
            'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons alpine/git -c '.escapeshellarg($script),
        ];
    }

    public static function normalizeRepository(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^https?://[^/]+/#', '', $value) ?? $value;
        $value = preg_replace('#\.git$#', '', $value) ?? $value;

        return trim($value, '/');
    }

    /**
     * @return list<string>
     */
    public static function repositoryBranches(GithubApp $githubApp, string $fullName): array
    {
        [$owner, $name] = self::splitRepository(self::normalizeRepository($fullName));
        $response = githubApi($githubApp, '/repos/'.rawurlencode($owner).'/'.rawurlencode($name).'/branches?per_page=100');

        return collect(data_get($response, 'data'))
            ->map(fn (mixed $row): string => (string) data_get($row, 'name'))
            ->filter(fn (string $branch): bool => $branch !== '' && preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * GitHub App used for house/owner modules. Stored on instance settings and
     * independent of the instance owner's team_user.github_app_id profile.
     */
    public static function ownerGithubApp(): ?GithubApp
    {
        $connected = self::connectedAppsForOwnerModules();
        $selectedId = (int) (instanceSettings()->odoo_owner_github_app_id ?? 0);
        if ($selectedId > 0) {
            $selected = $connected->firstWhere('id', $selectedId);
            if ($selected instanceof GithubApp) {
                return $selected;
            }
        }

        return $connected->sortBy('id')->first();
    }

    /**
     * Apps the instance owner may pick for house modules (any team). Owner-only UI.
     *
     * @return Collection<int, GithubApp>
     */
    public static function connectedAppsForOwnerModules(): Collection
    {
        return GithubApp::query()
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->whereNotNull('installation_id')
            ->whereNotNull('private_key_id')
            ->orderBy('name')
            ->get();
    }

    public static function githubAppLabel(GithubApp $githubApp): string
    {
        try {
            $login = self::accountLogin($githubApp);
            if ($login !== '') {
                return $login;
            }
        } catch (Throwable) {
            // Fall back to the Coolify app name when GitHub is unreachable.
        }

        return (string) ($githubApp->name ?: ('#'.$githubApp->id));
    }

    /**
     * @return list<string>
     */
    public static function ownerRepositoryCommands(string $cloneUrl, string $branch, string $repository): array
    {
        $module = basename(self::normalizeRepository($repository));
        $module = preg_match('/\A[A-Za-z0-9_]+\z/', $module) === 1 ? $module : '';
        $stamp = $module === ''
            ? 'rm -f /addons/.gpsh-module-name; '
            : 'if [ -f /addons/__manifest__.py ] || [ -f /addons/__openerp__.py ]; then printf %s\\n '.escapeshellarg($module).' > /addons/.gpsh-module-name; else rm -f /addons/.gpsh-module-name; fi; ';
        $script = 'set -e; '
            .self::gitSafeDirectorySetup()
            .'if [ -d /addons/.git ]; then '
            .'git -C /addons fetch --depth 1 origin '.escapeshellarg($branch).' && git -C /addons checkout -B '.escapeshellarg($branch).' FETCH_HEAD; '
            .'else rm -rf /tmp/src; git clone --depth 1 --branch '.escapeshellarg($branch).' '.escapeshellarg($cloneUrl).' /tmp/src && find /addons -mindepth 1 -maxdepth 1 -exec rm -rf {} + && cp -a /tmp/src/. /addons/; '
            .'fi; '
            .$stamp
            .'chmod -R a+rX /addons || true';

        return [
            'mkdir -p /data/coolify/gpsh-owner-modules',
            'docker run --rm --entrypoint sh -v /data/coolify/gpsh-owner-modules:/addons alpine/git -c '.escapeshellarg($script),
        ];
    }

    public static function syncOwnerRepository(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $settings = instanceSettings();
        $repository = trim((string) $settings->odoo_owner_repository);
        $branch = trim((string) $settings->odoo_owner_branch);
        if ($repository === '' || $branch === '') {
            self::clearOwnerModules();

            return;
        }

        $githubApp = self::ownerGithubApp();
        if (! $githubApp instanceof GithubApp) {
            throw new RuntimeException('Connect a GitHub App before cloning owner modules.');
        }

        $host = parse_url((string) $githubApp->html_url, PHP_URL_HOST) ?: 'github.com';
        $token = generateGithubInstallationToken($githubApp);
        $url = 'https://x-access-token:'.rawurlencode((string) $token).'@'.$host.'/'.$repository.'.git';
        $commands = self::ownerRepositoryCommands($url, $branch, $repository);
        foreach (self::ownerModuleServers() as $server) {
            if ($server->isFunctional()) {
                instant_remote_process($commands, $server);
            }
        }
    }

    /**
     * Copy the owner package branch into a client extra-addons volume.
     * Keeps the client's own addons and .git; only replaces previously stamped owner-package modules.
     *
     * @return list<string>
     */
    public static function installOwnerPackageCommands(string $volume, string $cloneUrl, string $branch, string $repository): array
    {
        $module = basename(self::normalizeRepository($repository));
        $module = preg_match('/\A[A-Za-z0-9_]+\z/', $module) === 1 ? $module : 'owner_package';
        $script = 'set -e; '
            .'rm -rf /tmp/owner-pkg; '
            .'git clone --depth 1 --branch '.escapeshellarg($branch).' '.escapeshellarg($cloneUrl).' /tmp/owner-pkg; '
            .'mkdir -p /addons/.gpsh; '
            .'if [ -f /addons/.gpsh/owner-package-modules ]; then '
            .'while IFS= read -r mod; do [ -n "$mod" ] || continue; rm -rf "/addons/$mod"; done < /addons/.gpsh/owner-package-modules; '
            .'fi; '
            .': > /addons/.gpsh/owner-package-modules; '
            .'if [ -f /tmp/owner-pkg/__manifest__.py ] || [ -f /tmp/owner-pkg/__openerp__.py ]; then '
            .'rm -rf /addons/'.escapeshellarg($module).'; mkdir -p /addons/'.escapeshellarg($module).'; '
            .'cp -a /tmp/owner-pkg/. /addons/'.escapeshellarg($module).'/; '
            .'rm -rf /addons/'.escapeshellarg($module).'/.git; '
            .'printf %s\\n '.escapeshellarg($module).' >> /addons/.gpsh/owner-package-modules; '
            .'else '
            .'for dir in /tmp/owner-pkg/*/; do '
            .'[ -d "$dir" ] || continue; '
            .'base=$(basename "$dir"); '
            .'[ -f "$dir/__manifest__.py" ] || [ -f "$dir/__openerp__.py" ] || continue; '
            .'rm -rf "/addons/$base"; cp -a "$dir" "/addons/$base"; '
            .'printf %s\\n "$base" >> /addons/.gpsh/owner-package-modules; '
            .'done; '
            .'fi; '
            .'printf %s\\n '.escapeshellarg($branch).' > /addons/.gpsh/owner-package-branch; '
            .'chown -R 100:101 /addons || true; chmod -R a+rX /addons || true';

        return [
            'docker volume create '.escapeshellarg($volume),
            'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons alpine/git -c '.escapeshellarg($script),
        ];
    }

    public static function installOwnerPackageIntoService(Service $service, string $branch): void
    {
        $branch = trim($branch);
        if ($branch === '' || preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) !== 1) {
            throw new InvalidArgumentException('Pick a valid owner package branch.');
        }
        if (! $service->supportsOdooJupyter()) {
            throw new InvalidArgumentException('This service is not an Odoo stack.');
        }

        $settings = instanceSettings();
        $repository = trim((string) $settings->odoo_owner_repository);
        if ($repository === '') {
            throw new InvalidArgumentException('Set the owner package repository in Settings → Odoo first.');
        }

        $service->loadMissing('environment');
        $environment = $service->environment;
        if ($environment === null) {
            throw new InvalidArgumentException('This service has no environment.');
        }

        $row = OdooEnvironmentBranch::query()->firstOrNew(['environment_id' => $environment->id]);
        if (! $row->exists) {
            $row->git_branch = (string) ($environment->name ?: 'main');
        }
        $row->owner_package_branch = $branch;
        $row->service_id = $service->id;
        $row->save();

        SyncOdooAddonsJob::dispatch(odooEnvironmentBranchId: $row->id);
    }

    /**
     * Re-apply the saved owner package branch into the client volume.
     * No DB write and no restart — callers sync the volume then restart once.
     */
    public static function reinstallOwnerPackageIntoService(Service $service): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (! $service->supportsOdooJupyter()) {
            return;
        }

        $service->loadMissing('environment.odooBranch', 'destination.server');
        $branch = trim((string) ($service->environment?->odooBranch?->owner_package_branch ?? ''));
        if ($branch === '' || preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) !== 1) {
            return;
        }

        $repository = trim((string) instanceSettings()->odoo_owner_repository);
        if ($repository === '') {
            return;
        }

        $githubApp = self::ownerGithubApp();
        if (! $githubApp instanceof GithubApp) {
            return;
        }

        $server = $service->destination?->server;
        if ($server === null || ! $server->isFunctional()) {
            return;
        }

        $host = parse_url((string) $githubApp->html_url, PHP_URL_HOST) ?: 'github.com';
        $token = generateGithubInstallationToken($githubApp);
        $url = 'https://x-access-token:'.rawurlencode((string) $token).'@'.$host.'/'.$repository.'.git';
        instant_remote_process(
            self::installOwnerPackageCommands(OdooAddons::extraAddonsVolume($service), $url, $branch, $repository),
            $server,
        );
    }

    public static function clearOwnerModules(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        foreach (self::ownerModuleServers() as $server) {
            if ($server->isFunctional()) {
                instant_remote_process([
                    'find /data/coolify/gpsh-owner-modules -mindepth 1 -maxdepth 1 -exec rm -rf {} +',
                ], $server, false);
            }
        }
    }

    /**
     * @return Collection<int, Server>
     */
    private static function ownerModuleServers(): Collection
    {
        $servers = collect();
        $local = Server::query()->find(0);
        if ($local instanceof Server) {
            $servers->push($local);
        }

        Service::query()->whereNotNull('server_id')->orderBy('id')->each(function (Service $service) use ($servers): void {
            if (! OdooJupyter::isOdooCompose((string) $service->docker_compose_raw)) {
                return;
            }
            if ($servers->contains(fn (Server $server): bool => (int) $server->id === (int) $service->server_id)) {
                return;
            }
            $server = $service->server;
            if ($server instanceof Server) {
                $servers->push($server);
            }
        });

        return $servers;
    }

    public static function ensureLaunchAllowed(Service $service): void
    {
        if (! $service->supportsOdooJupyter()) {
            return;
        }

        if (! $service->jupyter_enabled) {
            $service->forceFill(['jupyter_enabled' => true])->save();
        }
    }

    public static function beginConnect(Project $project, string $back = 'project.edit', array $parameters = [], bool $forceNew = false): GithubApp
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->isAdminOfTeam((int) $project->team_id)) {
            abort(403);
        }

        session([
            'from' => [
                'back' => $back,
                'odoo' => true,
                'parameters' => $parameters === [] ? ['project_uuid' => $project->uuid] : $parameters,
            ],
        ]);
        if (! $forceNew) {
            $installed = self::installedApp((int) $project->team_id, (int) $user->id);
            if ($installed instanceof GithubApp) {
                session(['from' => session('from') + ['source_id' => $installed->id]]);

                return $installed;
            }
            $githubApp = GithubApp::query()
                ->where('team_id', $project->team_id)
                ->whereNull('installation_id')
                ->latest('id')
                ->first();
            if ($githubApp instanceof GithubApp) {
                session(['from' => session('from') + ['source_id' => $githubApp->id]]);

                return $githubApp;
            }
        }

        // Change account (or first connect with no draft): new App row, then manifest / install.
        // Clear remembered + profile apps so appForProject does not keep the previous account.
        if ($forceNew) {
            DB::table('team_user')
                ->where('user_id', $user->id)
                ->where('team_id', $project->team_id)
                ->update(['github_app_id' => null]);
            $project->loadMissing('odooProfile');
            if ($project->odooProfile !== null) {
                $project->odooProfile->update(['github_app_id' => null]);
            }
        }

        $githubApp = GithubApp::create([
            'name' => self::appName((int) $project->team_id),
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
     * Servers this user may launch on. The instance server (id 0) is included
     * only when that permission is on.
     *
     * @return Collection<int, Server>
     */
    public static function allowedLaunchServers(): Collection
    {
        $user = auth()->user();

        $servers = Server::ownedByCurrentTeam()->orderBy('name')->get()
            ->reject(fn (Server $server): bool => (int) $server->id === 0)
            ->values();

        if ($user?->canLaunchOnInstanceServer()) {
            $local = Server::query()->find(0);
            if ($local instanceof Server) {
                $servers->prepend($local);
            }
        }

        return $servers;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function launchChoices(): array
    {
        $choices = self::allowedLaunchServers()
            ->map(fn (Server $server): array => [
                'value' => (string) $server->id,
                'label' => (int) $server->id === 0
                    ? __('Launch on the server where GPSH is installed')
                    : $server->name,
            ])
            ->values()
            ->all();

        if (auth()->user()?->canAddServers()) {
            $choices[] = ['value' => 'new', 'label' => __('Create a new server')];
        }

        return $choices;
    }

    public static function firstLaunchDestination(): StandaloneDocker|SwarmDocker|null
    {
        foreach (self::allowedLaunchServers() as $server) {
            $destination = $server->standaloneDockers()->first() ?? $server->swarmDockers()->first();
            if ($destination !== null) {
                return $destination;
            }
        }

        return null;
    }

    /**
     * After GitHub returns, the project asks whether to launch production
     * on a new repository or to search an existing one.
     */
    public static function resumeLaunchRedirect(): ?RedirectResponse
    {
        $from = session('from');
        if (! is_array($from) || ! data_get($from, 'odoo')) {
            return null;
        }

        $back = data_get($from, 'back');
        if (! is_string($back) || $back === '') {
            return null;
        }

        $parameters = data_get($from, 'parameters');
        session()->forget('from');
        $routeParameters = array_filter([
            'environment_uuid' => data_get($parameters, 'environment_uuid'),
            'project_uuid' => data_get($parameters, 'project_uuid'),
            'service_uuid' => data_get($parameters, 'service_uuid'),
            'type' => data_get($parameters, 'type'),
            'destination' => data_get($parameters, 'destination'),
            'launch' => 'choose',
        ], fn ($value) => filled($value));

        return redirect()->route($back, $routeParameters);
    }

    private static function appName(int $teamId): string
    {
        $base = self::configuredAppName();
        $name = $base;
        $suffix = 2;
        while (GithubApp::query()->where('team_id', $teamId)->where('name', $name)->exists()) {
            $name = substr($base.'-'.$suffix, 0, 30);
            $suffix++;
        }

        return $name;
    }

    /**
     * A GitHub account is connected when the App is installed and has its private key.
     *
     * @return Collection<int, GithubApp>
     */
    public static function connectedApps(int $teamId): Collection
    {
        return GithubApp::query()
            ->where('team_id', $teamId)
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->whereNotNull('installation_id')
            ->whereNotNull('private_key_id')
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
            ->with('environment')
            ->get();

        foreach ($rows as $row) {
            if ($row->environment instanceof Environment) {
                self::ensureAddonsApplication($row->environment);
            }
            $row->update(['status' => 'updating']);
            SyncOdooAddonsJob::dispatch(odooEnvironmentBranchId: $row->id);
        }

        return $rows->count();
    }
}
