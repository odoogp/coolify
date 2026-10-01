<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\OdooEnvironmentBranch;
use App\Models\OdooProfile;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\ServiceApplication;
use App\Rules\ValidGitBranch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

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
            $response = Http::GitHub($app->api_url, $token)
                ->timeout(15)
                ->get('/installation/repositories', [
                    'per_page' => 100,
                    'page' => $page,
                ]);
            if ($response->status() !== 200) {
                if ($repositories !== []) {
                    break;
                }
                throw new RuntimeException((string) ($response->json('message') ?: 'GitHub repositories could not be loaded.'));
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
            $response = Http::GitHub($app->api_url, $token)
                ->timeout(20)
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

        return DB::transaction(function () use ($project, $profile, $githubApp, $repository, $branch, $createStaging, $environment): Environment {
            $environment = self::persistLaunchTarget($project, $createStaging, $environment);

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
        if ($service->environment_variables()->where('key', 'ODOO_ADMIN_PASSWORD')->doesntExist()) {
            self::rememberVariable($service, 'ODOO_ADMIN_PASSWORD', Str::password(20, symbols: false), false);
        }
        if ($service->environment_variables()->where('key', 'ODOO_LOGIN_TOKEN')->doesntExist()) {
            self::rememberVariable($service, 'ODOO_LOGIN_TOKEN', Str::password(40, symbols: false), false);
        }
    }

    public static function publicHttpsUrl(Service $service): string
    {
        $application = $service->applications()->get()->first(
            fn ($application): bool => $application instanceof ServiceApplication && self::isOdooApplication($application)
        );

        return $application instanceof ServiceApplication
            ? self::httpsUrl(firstDomainFromList((string) $application->fqdn))
            : '';
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

    public static function copyProductionDataCommand(Service $source, Service $target): string
    {
        $source->loadMissing('environment');
        $target->loadMissing('environment');
        if (strcasecmp((string) $source->environment?->name, 'production') !== 0) {
            throw new RuntimeException('The database copy starts from production.');
        }
        if (! OdooStaging::isStagingName((string) $target->environment?->name)) {
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
        if (preg_match('/image:\s*(odoo:[0-9]+(?:\.[0-9]+)?)/', (string) $target->docker_compose_raw, $matches) !== 1) {
            throw new RuntimeException('The staging database cannot be neutralized without the Odoo image.');
        }

        $sourceVolume = OdooAddons::filestoreVolume($source);
        $targetVolume = OdooAddons::filestoreVolume($target);
        if ($sourceVolume === $targetVolume) {
            throw new RuntimeException('The database copy refused to write production.');
        }

        $url = self::publicHttpsUrl($target);
        $urlSql = 'true';
        if (preg_match('#^https://[A-Za-z0-9.-]+$#', $url) === 1) {
            $sql = "UPDATE ir_config_parameter SET value='{$url}' WHERE key='web.base.url'; "
                ."INSERT INTO ir_config_parameter (key, value, create_uid, write_uid, create_date, write_date) SELECT 'web.base.url', '{$url}', 1, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM ir_config_parameter WHERE key='web.base.url'); "
                ."UPDATE ir_config_parameter SET value='True' WHERE key='web.base.url.freeze'; "
                ."INSERT INTO ir_config_parameter (key, value, create_uid, write_uid, create_date, write_date) SELECT 'web.base.url.freeze', 'True', 1, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM ir_config_parameter WHERE key='web.base.url.freeze');";
            $urlSql = 'docker exec -e PGPASSWORD="$(printf \'%s\' \''.base64_encode($targetPassword).'\' | base64 -d)" postgresql-'.$target->uuid
                .' psql -U '.$targetUser.' -d '.$targetDatabase.' -v ON_ERROR_STOP=1 -c '.escapeshellarg($sql);
        }

        $script = <<<'BASH'
set -eu
dump=__DUMP__
docker stop __DST_ODOO__ >/dev/null 2>&1 || true
trap 'status=$?; rm -f "$dump"; if [ "$status" -ne 0 ]; then docker start __DST_ODOO__ >/dev/null 2>&1 || true; fi; exit "$status"' EXIT
docker exec -e PGPASSWORD="$(printf '%s' '__SRC_PW__' | base64 -d)" __SRC_PG__ pg_dump -U __SRC_USER__ --no-owner --no-acl __SRC_DB__ > "$dump"
docker exec -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" __DST_PG__ psql -U __DST_USER__ -d postgres -v ON_ERROR_STOP=1 -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '__DST_DB__' AND pid <> pg_backend_pid();"
docker exec -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" __DST_PG__ dropdb --if-exists -U __DST_USER__ __DST_DB__
docker exec -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" __DST_PG__ createdb -U __DST_USER__ __DST_DB__
docker exec -i -e PGPASSWORD="$(printf '%s' '__DST_PW__' | base64 -d)" __DST_PG__ psql -U __DST_USER__ -d __DST_DB__ -v ON_ERROR_STOP=1 < "$dump"
docker volume create __DST_VOLUME__
docker run --rm -v __SRC_VOLUME__:/source:ro -v __DST_VOLUME__:/target alpine sh -c 'find /target -mindepth 1 -maxdepth 1 -exec rm -rf {} +; cp -a /source/. /target/; if [ -d /target/filestore/__SRC_DB__ ]; then rm -rf /target/filestore/__DST_DB__; mv /target/filestore/__SRC_DB__ /target/filestore/__DST_DB__; fi; chown -R 101:101 /target || true'
__URL_SQL__
docker run --rm --network container:__DST_PG__ --entrypoint odoo __IMAGE__ neutralize -d __DST_DB__ --db_host=127.0.0.1 --db_port=5432 --db_user=__DST_USER__ --db_password="$(printf '%s' '__DST_PW__' | base64 -d)" --stop-after-init
docker start __DST_ODOO__
BASH;

        $script = str_replace(
            ['__SRC_PG__', '__DST_PG__', '__DST_ODOO__', '__SRC_USER__', '__DST_USER__', '__SRC_DB__', '__DST_DB__', '__SRC_PW__', '__DST_PW__', '__DUMP__', '__SRC_VOLUME__', '__DST_VOLUME__', '__IMAGE__', '__URL_SQL__'],
            [
                'postgresql-'.$source->uuid,
                'postgresql-'.$target->uuid,
                'odoo-'.$target->uuid,
                $sourceUser,
                $targetUser,
                $sourceDatabase,
                $targetDatabase,
                base64_encode($sourcePassword),
                base64_encode($targetPassword),
                '/tmp/gpsh-clone-'.$target->uuid.'.sql',
                $sourceVolume,
                $targetVolume,
                $matches[1],
                $urlSql,
            ],
            $script,
        );

        return 'bash -c '.escapeshellarg($script);
    }

    public static function enterUrl(Service $service): string
    {
        $base = self::publicHttpsUrl($service);
        $token = self::runtimeValue($service, 'ODOO_LOGIN_TOKEN');
        if ($base === '' || $token === '') {
            return $base;
        }

        return $base.'/_odoo/paas/connect?token='.urlencode($token);
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
        } catch (\Throwable) {
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
        if (! OdooJupyter::isOdooCompose((string) $service->docker_compose_raw)) {
            return false;
        }

        $changed = false;
        foreach ($service->applications()->get() as $application) {
            if (! $application instanceof ServiceApplication || ! self::isOdooApplication($application)) {
                continue;
            }
            $fqdn = collect(explode(',', (string) $application->fqdn))
                ->map(fn (string $domain): string => self::httpsUrl(trim($domain)))
                ->filter()
                ->implode(',');
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
        try {
            $output = instant_remote_process([
                "if docker exec coolify-proxy grep -F -e {$needle} -e {$needleSpaced} /traefik/acme.json >/dev/null; then echo applied; else echo pending; fi",
            ], $server, false);
        } catch (\Throwable) {
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
     * One remote command. The deploy stays running until this host has a real certificate and Odoo answers.
     */
    public static function httpsReadyCommand(string $host): ?string
    {
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        $script = 'host='.escapeshellarg($host).'; ready=0; for i in $(seq 1 120); do issuer=$(echo | openssl s_client -connect 127.0.0.1:443 -servername "$host" 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null || true); code=$(curl -sk --resolve "$host:443:127.0.0.1" --max-time 8 -o /tmp/gpsh-https-body -w "%{http_code}" "https://$host/" || true); if printf "%s" "$issuer" | grep -qi encrypt && printf "%s" "$issuer" | grep -qiv traefik && case "$code" in 2*|3*) true ;; *) false ;; esac && ! grep -q "no available server" /tmp/gpsh-https-body; then echo "HTTPS is ready for $host"; ready=1; break; fi; echo "Waiting for HTTPS on $host ($code)"; sleep 5; done; rm -f /tmp/gpsh-https-body; test "$ready" = 1';

        return 'bash -c '.escapeshellarg($script);
    }

    public static function startIfPossible(Service $service): void
    {
        $service->refresh();
        if ($service->server?->isFunctional()) {
            \App\Actions\Service\StartService::dispatch($service);
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

    private static function isOdooApplication(ServiceApplication $application): bool
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

        if ($connected->isEmpty()) {
            return null;
        }

        return $connected->count() === 1
            ? $connected->first()
            : $connected->sortByDesc('id')->first();
    }

    public static function rememberForUser(int $userId, int $teamId, GithubApp $githubApp): void
    {
        DB::table('team_user')
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->update(['github_app_id' => $githubApp->id]);
    }

    private static function installationAccount(GithubApp $githubApp): array
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
            $message = strtolower((string) data_get($created, 'data.message', ''));
            if (str_contains($message, 'resource not accessible') || str_contains($message, 'upgrade')) {
                throw new RuntimeException('This GitHub account cannot create repositories. Change the account and accept permission to create them.');
            }
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
     * @return list<string>
     */
    public static function cloneCommands(string $volume, string $cloneUrl, string $branch): array
    {
        $script = 'set -e; '
            .'if [ -d /addons/.git ]; then '
            .'git -C /addons fetch --depth 1 origin '.escapeshellarg($branch).' && git -C /addons checkout -B '.escapeshellarg($branch).' FETCH_HEAD; '
            .'else git clone --depth 1 --branch '.escapeshellarg($branch).' '.escapeshellarg($cloneUrl).' /tmp/src && cp -a /tmp/src/. /addons/; '
            .'fi; chown -R 100:101 /addons || true; chmod -R a+rX /addons || true';

        return [
            'docker volume create '.escapeshellarg($volume),
            'docker run --rm --entrypoint sh -v '.escapeshellarg($volume).':/addons alpine/git -c '.escapeshellarg($script),
        ];
    }

    public static function cloneIntoService(Service $service): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (! $service->supportsOdooJupyter()) {
            return;
        }

        $service->loadMissing('environment.project.odooProfile.githubApp', 'environment.odooBranch', 'destination.server');
        $profile = $service->environment?->project?->odooProfile;
        $repository = $profile?->git_repository;
        $githubApp = $profile?->githubApp;
        $server = $service->destination?->server;
        if (blank($repository) || ! $githubApp instanceof GithubApp || $server === null) {
            return;
        }

        $branch = $service->environment?->odooBranch?->git_branch ?: $service->environment?->name ?: 'main';
        $host = parse_url((string) $githubApp->html_url, PHP_URL_HOST) ?: 'github.com';
        $token = generateGithubInstallationToken($githubApp);
        $url = 'https://x-access-token:'.rawurlencode((string) $token).'@'.$host.'/'.$repository.'.git';
        instant_remote_process(self::cloneCommands(OdooAddons::extraAddonsVolume($service), $url, (string) $branch), $server);
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

    private static function appName(int $teamId): string
    {
        $base = str(product_name())->lower()->toString();
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
            \App\Jobs\SyncOdooAddonsJob::dispatch(odooEnvironmentBranchId: $row->id);
        }

        return $rows->count();
    }
}
