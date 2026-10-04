<?php

namespace App\Livewire\Project;

use App\Domain\Odoo\OdooStaging;
use App\Jobs\CloneOdooStagingJob;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminCreationQuota;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use App\Support\OdooMonitor;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;

class Show extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public string $name;

    public ?string $description = null;

    public ?string $selectedEnvironmentUuid = null;

    public bool $showCloneWizard = false;

    public string $cloneAddons = 'copy';

    public string $stagingBranch = '';

    /** @var list<string> */
    public array $cloneBranches = [];

    public bool $cloneRunning = false;

    public int $cloneStep = 0;

    public ?string $cloneError = null;

    public bool $workRunning = false;

    public bool $odooUsersOpen = false;

    public string $odooConnectUrl = '';

    /** @var list<array{name: string, login: string}> */
    public array $odooUsers = [];

    /** @var array<string, string> */
    public array $activityErrors = [];

    /** @var list<array<string, mixed>> */
    public array $environmentPayload = [];

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function mount(string $project_uuid)
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)
                ->where('uuid', $project_uuid)
                ->with([
                    'environments' => fn ($query) => $query
                        ->withCount([
                            'applications',
                            'services',
                            'postgresqls',
                            'redis',
                            'keydbs',
                            'dragonflies',
                            'clickhouses',
                            'mongodbs',
                            'mysqls',
                            'mariadbs',
                        ])
                        ->orderBy('created_at'),
                ])
                ->firstOrFail();
            $requested = request()->query('environment');
            if (is_string($requested) && $this->project->environments->contains(fn (Environment $environment): bool => $environment->uuid === $requested)) {
                $this->selectedEnvironmentUuid = $requested;
            }
            $this->absorbWork(redirectOnDone: false);
            $this->markWorkRunning();
            $this->project->loadMissing('odooProfile', 'environments.odooBranch', 'environments.services');
            $this->environmentPayload = $this->environmentRows(
                auth()->user()->can('update', $this->project),
                $this->project->odooProfile()->exists(),
            );
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('create', Environment::class);
            $this->validate();
            $environment = app(AdminCreationQuota::class)->createEnvironment(auth()->user(), $this->project, [
                'name' => $this->name,
                'uuid' => new_public_id(),
            ]);

            return redirectRoute($this, 'project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function openCloneWizard(): void
    {
        $production = $this->project->environments->first(
            fn (Environment $environment): bool => strcasecmp($environment->name, 'production') === 0
        );
        if ($production instanceof Environment) {
            $this->selectedEnvironmentUuid = $production->uuid;
        }
        $this->stagingBranch = '';
        $this->cloneBranches = [];
        $profile = $this->project->odooProfile;
        $app = $profile?->githubApp;
        if (filled($profile?->git_repository) && $app instanceof GithubApp) {
            try {
                $parts = explode('/', (string) $profile->git_repository, 2);
                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    throw new RuntimeException('GitHub did not return a repository name.');
                }
                $used = $this->usedOdooBranches();
                $this->cloneBranches = array_values(array_filter(
                    OdooGit::branchNames($app, $parts[0], $parts[1]),
                    fn (string $branch): bool => ! in_array($branch, $used, true),
                ));
                $this->stagingBranch = (string) ($this->cloneBranches[0] ?? '');
            } catch (InvalidArgumentException|RuntimeException $exception) {
                $this->dispatch('error', __($exception->getMessage()));
            }
        }
        $this->showCloneWizard = true;
    }

    public function closeCloneWizard(): void
    {
        $this->showCloneWizard = false;
    }

    public function openOdooUsers(?string $serviceUuid): void
    {
        $service = Service::query()->where('uuid', (string) $serviceUuid)->first();
        $environment = $service?->environment;
        if (! $service instanceof Service || $environment === null || $environment->project_id !== $this->project->id) {
            return;
        }
        $this->authorize('view', $service);
        $this->odooUsers = OdooGit::internalUsers($service);
        $this->odooConnectUrl = route('project.service.odoo.enter', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ]);
        $this->odooUsersOpen = true;
    }

    public function selectEnvironment(string $uuid): void
    {
        $this->selectedEnvironmentUuid = $uuid;
        $this->showCloneWizard = false;
    }

    public function cloneToStaging()
    {
        try {
            $this->authorize('update', $this->project);
            $this->project->load('odooProfile.githubApp', 'environments.odooBranch');
            $selected = $this->project->environments->firstWhere('uuid', $this->selectedEnvironmentUuid);
            if (! $selected instanceof Environment || strcasecmp($selected->name, 'production') !== 0) {
                throw new RuntimeException('Clone starts from the production environment.');
            }

            $profile = $this->project->odooProfile;
            if (filled($profile?->git_repository) && $profile->githubApp instanceof GithubApp) {
                $branch = trim($this->stagingBranch);
                if ($branch === '' || ! in_array($branch, $this->cloneBranches, true)) {
                    throw new InvalidArgumentException('Choose a GitHub branch that is not already used.');
                }
            } else {
                $branch = OdooStaging::nextName($this->project);
            }

            $this->cloneError = null;
            $this->cloneStep = 1;
            $this->cloneRunning = true;
            $this->showCloneWizard = false;
            Cache::put($this->cloneCacheKey(), [
                'step' => 1,
                'done' => false,
                'error' => null,
                'url' => null,
            ], now()->addMinutes(30));
            CloneOdooStagingJob::dispatch(
                $this->project->id,
                $selected->uuid,
                $branch,
                $this->cloneAddons,
                $this->cloneCacheKey(),
                (int) auth()->id(),
            );

            $this->workRunning = true;

            return $this->refreshCloneProgress();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->cloneRunning = false;
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            $this->cloneRunning = false;

            return handleError($e, $this);
        }
    }

    public function dismissCloneError(): void
    {
        $this->cloneError = null;
        $this->cloneStep = 0;
        $this->cloneRunning = false;
    }

    public function refreshCloneProgress()
    {
        $this->authorize('view', $this->project);

        try {
            $this->project->unsetRelation('environments');
            $this->project->load([
                'environments' => fn ($query) => $query
                    ->withCount([
                        'applications',
                        'services',
                        'postgresqls',
                        'redis',
                        'keydbs',
                        'dragonflies',
                        'clickhouses',
                        'mongodbs',
                        'mysqls',
                        'mariadbs',
                    ])
                    ->orderBy('created_at'),
                'environments.odooBranch',
                'environments.services',
            ]);
            $redirect = $this->absorbWork(redirectOnDone: true);
            $this->markWorkRunning();
            $this->environmentPayload = $this->environmentRows(
                auth()->user()->can('update', $this->project),
                $this->project->odooProfile()->exists(),
            );
            if ($redirect !== null) {
                return $redirect;
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function cloneRedirect(array $status): mixed
    {
        $redirect = $status['redirect'] ?? null;
        $name = is_array($redirect) ? (string) ($redirect['name'] ?? '') : '';
        $parameters = is_array($redirect) && is_array($redirect['parameters'] ?? null) ? $redirect['parameters'] : [];
        if (in_array($name, ['project.service.configuration', 'project.show'], true)) {
            return redirectRoute($this, $name, $parameters);
        }

        $path = parse_url((string) ($status['url'] ?? ''), PHP_URL_PATH);
        if (is_string($path) && str_starts_with($path, '/project/')) {
            return redirect()->to($path);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function usedOdooBranches(): array
    {
        $this->project->loadMissing('environments.odooBranch');

        return $this->project->environments
            ->map(fn (Environment $environment): ?string => $environment->odooBranch?->git_branch)
            ->filter(fn (?string $branch): bool => filled($branch))
            ->unique()
            ->values()
            ->all();
    }

    private function cloneCacheKey(): string
    {
        return 'odoo-clone-'.$this->project->id.'-'.auth()->id();
    }

    private function sharedCloneKey(): string
    {
        return 'odoo-clone-project-'.$this->project->id;
    }

    private function absorbWork(bool $redirectOnDone): mixed
    {
        $clone = Cache::get($this->cloneCacheKey());
        if (is_array($clone)) {
            if (filled($clone['error'] ?? null)) {
                $uuid = is_string($clone['environment'] ?? null) ? $clone['environment'] : 'pending-clone';
                $this->activityErrors[$uuid] = (string) $clone['error'];
                $this->cloneError = (string) $clone['error'];
                Cache::forget($this->cloneCacheKey());
                $this->cloneRunning = false;
            } elseif (($clone['done'] ?? false) === true) {
                $redirect = $redirectOnDone ? $this->cloneRedirect($clone) : null;
                Cache::forget($this->cloneCacheKey());
                $this->cloneRunning = false;
                if ($redirect !== null) {
                    return $redirect;
                }
            } else {
                $this->cloneRunning = true;
                $this->cloneStep = (int) ($clone['step'] ?? 1);
                $this->showCloneWizard = false;
            }
        } else {
            $this->cloneRunning = false;
        }

        $this->project->loadMissing('environments.services');
        foreach ($this->project->environments as $environment) {
            foreach ($environment->services as $service) {
                if (! $service->supportsOdooJupyter()) {
                    continue;
                }
                $key = 'launch-odoo-'.$service->uuid;
                $status = Cache::get($key);
                if (! is_array($status)) {
                    continue;
                }
                if (filled($status['error'] ?? null)) {
                    $this->activityErrors[$environment->uuid] = (string) $status['error'];
                    Cache::forget($key);

                    continue;
                }
                if (($status['done'] ?? false) === true) {
                    $redirect = is_array($status['redirect'] ?? null) ? $status['redirect'] : null;
                    $name = is_array($redirect) ? (string) ($redirect['name'] ?? '') : '';
                    $parameters = is_array($redirect) && is_array($redirect['parameters'] ?? null) ? $redirect['parameters'] : [];
                    Cache::forget($key);
                    if ($redirectOnDone && $name !== '') {
                        return redirectRoute($this, $name, $parameters);
                    }
                }
            }
        }

        return null;
    }

    private function markWorkRunning(): void
    {
        if ($this->cloneRunning) {
            $this->workRunning = true;

            return;
        }

        $sharedClone = Cache::get($this->sharedCloneKey());
        if (is_array($sharedClone) && ($sharedClone['done'] ?? false) !== true && ! filled($sharedClone['error'] ?? null)) {
            $this->workRunning = true;

            return;
        }

        $this->project->loadMissing('environments.services');
        foreach ($this->project->environments as $environment) {
            foreach ($environment->services as $service) {
                if (! $service->supportsOdooJupyter()) {
                    continue;
                }
                $status = Cache::get('launch-odoo-'.$service->uuid);
                if (is_array($status) && ($status['done'] ?? false) !== true && ! filled($status['error'] ?? null)) {
                    $this->workRunning = true;

                    return;
                }
                if ($service->isStarting()) {
                    $this->workRunning = true;

                    return;
                }
            }
        }

        $this->workRunning = false;
    }

    /**
     * @return array<string, array{running: bool, message: string, error: ?string}>
     */
    private function activityMap(): array
    {
        $this->project->loadMissing('environments.services');
        $map = [];
        foreach ($this->project->environments as $environment) {
            foreach ($environment->services as $service) {
                if (! $service->supportsOdooJupyter()) {
                    continue;
                }
                $status = Cache::get('launch-odoo-'.$service->uuid);
                if (is_array($status) && ($status['done'] ?? false) !== true) {
                    $map[$environment->uuid] = [
                        'running' => ! filled($status['error'] ?? null),
                        'message' => $this->launchMessage((int) ($status['step'] ?? 1)),
                        'error' => filled($status['error'] ?? null) ? (string) $status['error'] : null,
                    ];

                    continue;
                }
                if ($service->isStarting()) {
                    $map[$environment->uuid] = [
                        'running' => true,
                        'message' => $this->launchMessage(2),
                        'error' => null,
                    ];
                }
            }
        }

        $clone = Cache::get($this->sharedCloneKey());
        if (! is_array($clone)) {
            $clone = Cache::get($this->cloneCacheKey());
        }
        if (is_array($clone) && ($clone['done'] ?? false) !== true) {
            $uuid = is_string($clone['environment'] ?? null) ? $clone['environment'] : null;
            if ($uuid !== null && $this->project->environments->contains(fn (Environment $environment): bool => $environment->uuid === $uuid)) {
                $map[$uuid] = [
                    'running' => ! filled($clone['error'] ?? null),
                    'message' => $this->cloneMessage((int) ($clone['step'] ?? 1)),
                    'error' => filled($clone['error'] ?? null) ? (string) $clone['error'] : null,
                ];
            }
        }

        foreach ($this->activityErrors as $uuid => $message) {
            if ($this->project->environments->contains(fn (Environment $environment): bool => $environment->uuid === $uuid)) {
                $map[$uuid] = [
                    'running' => false,
                    'message' => '',
                    'error' => $message,
                ];
            }
        }

        return $map;
    }

    private function cloneMessage(int $step): string
    {
        return match ($step) {
            1 => __('Mounting the environment'),
            2 => __('Copying the service'),
            3 => __('Cloning the branch'),
            4 => __('Almost there.'),
            default => __('Done'),
        };
    }

    private function launchMessage(int $step): string
    {
        return match ($step) {
            1 => __('Creating the project'),
            2 => __('Starting the containers'),
            3 => __('Almost there.'),
            default => __('Done'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withPendingClone(array $rows): array
    {
        $clone = Cache::get($this->cloneCacheKey());
        $uuid = is_array($clone) && is_string($clone['environment'] ?? null) ? $clone['environment'] : null;
        $known = $uuid !== null && collect($rows)->contains(fn (array $row): bool => $row['uuid'] === $uuid);
        $pendingError = $this->activityErrors[$uuid ?? 'pending-clone'] ?? $this->activityErrors['pending-clone'] ?? null;
        $running = is_array($clone) && ($clone['done'] ?? false) !== true && ! filled($clone['error'] ?? null);
        if ($known || (! $running && ! is_string($pendingError))) {
            return $rows;
        }

        $rows[] = [
            'uuid' => $uuid ?? 'pending-clone',
            'name' => OdooStaging::nextName($this->project),
            'description' => null,
            'branch' => null,
            'odoo' => true,
            'serviceHref' => null,
            'enterHref' => null,
            'jupyterHref' => null,
            'monitorHref' => null,
            'logsHref' => null,
            'terminalHref' => null,
            'environmentHref' => null,
            'resourceCount' => 0,
            'href' => null,
            'settingsHref' => null,
            'addResourceHref' => null,
            'activity' => [
                'running' => $running,
                'message' => $running ? $this->cloneMessage((int) (is_array($clone) ? ($clone['step'] ?? 1) : 1)) : '',
                'error' => is_string($pendingError) ? $pendingError : null,
            ],
        ];

        return $rows;
    }

    public function navigateToEnvironment($projectUuid, $environmentUuid)
    {
        return redirectRoute($this, 'project.resource.index', [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
        ]);
    }

    public function continueOdoo(bool $withGithub = false): mixed
    {
        try {
            $this->authorize('update', $this->project);
            $production = $this->productionEnvironment();
            if (! $production instanceof Environment) {
                return null;
            }

            return redirect()->route('project.resource.index', $this->productionRoute($production, $withGithub));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function connectOdooGithub(): mixed
    {
        try {
            $this->authorize('update', $this->project);
            $production = $this->productionEnvironment();
            if (! $production instanceof Environment) {
                return null;
            }
            $githubApp = OdooGit::beginConnect($this->project, 'project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $production->uuid,
            ]);
            if (filled($githubApp->installation_id) && filled($githubApp->private_key_id)) {
                return redirect()->route('project.resource.index', $this->productionRoute($production, true));
            }
            if (filled($githubApp->app_id)) {
                return redirect()->away(getInstallationPath($githubApp));
            }

            return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function productionEnvironment(): ?Environment
    {
        if ($this->project->odooProfile === null) {
            return null;
        }
        $production = $this->project->environments()
            ->whereRaw('lower(name) = ?', ['production'])
            ->first();
        if ($production instanceof Environment) {
            return $production;
        }
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return app(AdminCreationQuota::class)->createEnvironment($user, $this->project, [
            'name' => 'production',
            'uuid' => new_public_id(),
        ]);
    }

    /**
     * @return array{project_uuid: string, environment_uuid: string, launch?: string}
     */
    private function productionRoute(Environment $production, bool $withGithub): array
    {
        $parameters = [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $production->uuid,
        ];
        if ($withGithub && $this->repositoryChoiceIsOpen()) {
            $parameters['launch'] = 'choose';
        }

        return $parameters;
    }

    private function repositoryChoiceIsOpen(): bool
    {
        return $this->project->odooProfile !== null
            && blank($this->project->odooProfile->git_repository)
            && OdooGit::installedApp((int) $this->project->team_id, auth()->id()) instanceof GithubApp;
    }

    public function render(): View
    {
        $this->project->loadMissing('environments.odooBranch', 'environments.services');
        $activities = $this->activityMap();

        return view('livewire.project.show', [
            'creationQuota' => app(AdminCreationQuota::class)->summaryForViewer(),
            'usedBranches' => $this->usedOdooBranches(),
            'selectedEnvironment' => $this->project->environments->firstWhere('uuid', $this->selectedEnvironmentUuid),
            'activities' => $activities,
            'odooGithubReady' => $this->repositoryChoiceIsOpen(),
            'hasProduction' => $this->project->environments->contains(
                fn (Environment $environment): bool => strcasecmp($environment->name, 'production') === 0
            ),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function environmentRows(bool $canUpdateProject, bool $odooOnly): array
    {
        $canCreateResource = auth()->user()->can('createAnyResource') && ! $odooOnly;
        $activities = $this->activityMap();

        return $this->withPendingClone($this->project->environments->map(function (Environment $environment) use ($canCreateResource, $canUpdateProject, $odooOnly, $activities): array {
            $resourceCount = collect([
                $environment->applications_count,
                $environment->services_count,
                $environment->postgresqls_count,
                $environment->redis_count,
                $environment->keydbs_count,
                $environment->dragonflies_count,
                $environment->clickhouses_count,
                $environment->mongodbs_count,
                $environment->mysqls_count,
                $environment->mariadbs_count,
            ])->sum();

            $service = $environment->services->first(fn (Service $service): bool => $service->supportsOdooJupyter());
            $domain = $odooOnly
                ? (string) ($environment->odooBranch?->domain
                    ?: ($service instanceof Service ? OdooGit::publicHttpsUrl($service) : ''))
                : '';
            $status = $odooOnly
                ? (string) ($environment->odooBranch?->status
                    ?: ($service instanceof Service && $service->isStarting() ? 'starting' : ($service instanceof Service ? 'ready' : 'empty')))
                : '';
            $version = $odooOnly ? (string) ($this->project->odooProfile?->odoo_version ?? '') : '';
            $serviceHref = ! $odooOnly && $service instanceof Service
                ? route('project.service.configuration', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'service_uuid' => $service->uuid,
                ])
                : null;
            $enterHref = $odooOnly && $service instanceof Service && OdooGit::canOpen($service)
                ? route('project.service.odoo.enter', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'service_uuid' => $service->uuid,
                ])
                : null;
            $jupyterHref = $odooOnly && $service instanceof Service ? OdooJupyter::sessionUrl($service) : null;
            $monitorHref = $odooOnly && $service instanceof Service ? OdooMonitor::urlFor($service) : null;
            $logsHref = $odooOnly && $service instanceof Service
                ? route('project.service.logs', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'service_uuid' => $service->uuid,
                    'only' => 'odoo',
                ])
                : null;
            $terminalHref = $odooOnly && $service instanceof Service && auth()->user()?->canOpenTerminal($service)
                ? route('project.service.command', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'service_uuid' => $service->uuid,
                    'shell' => 'odoo',
                ])
                : null;

            return [
                'uuid' => $environment->uuid,
                'name' => $environment->name,
                'description' => $environment->description,
                'branch' => $environment->odooBranch?->git_branch,
                'domain' => $domain !== '' ? $domain : null,
                'version' => $version !== '' ? $version : null,
                'status' => $status !== '' ? $status : null,
                'odoo' => $odooOnly,
                'serviceHref' => $serviceHref,
                'enterHref' => $enterHref,
                'serviceUuid' => $service instanceof Service ? $service->uuid : null,
                'jupyterHref' => $jupyterHref,
                'monitorHref' => $monitorHref,
                'logsHref' => $logsHref,
                'terminalHref' => $terminalHref,
                'environmentHref' => $odooOnly && $service instanceof Service
                    ? route('project.service.configuration', [
                        'project_uuid' => $this->project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'service_uuid' => $service->uuid,
                    ])
                    : route('project.resource.index', [
                        'project_uuid' => $this->project->uuid,
                        'environment_uuid' => $environment->uuid,
                    ]),
                'resourceCount' => $resourceCount,
                'href' => $odooOnly ? null : ($serviceHref ?? route('project.resource.index', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $environment->uuid,
                ])),
                'settingsHref' => $canUpdateProject
                    ? route('project.environment.edit', [
                        'project_uuid' => $this->project->uuid,
                        'environment_uuid' => $environment->uuid,
                    ])
                    : null,
                'addResourceHref' => $canCreateResource
                    ? route('project.resource.create', [
                        'project_uuid' => $this->project->uuid,
                        'environment_uuid' => $environment->uuid,
                    ])
                    : null,
                'activity' => $activities[$environment->uuid] ?? null,
            ];
        })->values()->all());
    }
}
