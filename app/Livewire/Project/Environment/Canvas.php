<?php

namespace App\Livewire\Project\Environment;

use App\Actions\Service\StartService;
use App\Helpers\SshMultiplexingHelper;
use App\Livewire\Project\Agent\CloudShell;
use App\Livewire\Project\Concerns\LaunchesCatalogService;
use App\Models\Environment;
use App\Models\EnvironmentCanvasLayout;
use App\Models\EnvironmentStackTemplate;
use App\Models\GithubApp;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Services\GetOdoo\GetOdooAreaEntitlements;
use App\Support\EnvironmentCanvasGraph;
use App\Support\EnvironmentStackTemplateCatalog;
use App\Support\OdooGit;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

class Canvas extends Component
{
    use AuthorizesRequests;
    use LaunchesCatalogService;

    public const CREATE_OPTION_AI_AGENT = '__ai_agent__';

    public const CREATE_OPTION_GITHUB = '__github__';

    public const CREATE_OPTION_STACK_PREFIX = '__stack__:';

    public Project $project;

    public Environment $environment;

    /** @var array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>} */
    public array $graph = ['nodes' => [], 'edges' => [], 'meta' => []];

    #[Url(as: 'inspect')]
    public ?string $inspector = null;

    #[Url(as: 'node')]
    public ?string $inspectorNodeId = null;

    public string $inspectorTab = 'details';

    public ?string $inspectorTitle = null;

    public ?string $inspectorKind = null;

    public ?string $inspectorStatus = null;

    /** running|stopped|pending|neutral — drives the status pill tone. */
    public string $inspectorStatusTone = 'neutral';

    public ?string $monitorEmbedUrl = null;

    public string $logOutput = '';

    /** @var list<string> */
    public array $stackRules = [];

    public ?string $githubServiceUuid = null;

    public ?string $sentinelUrl = null;

    public ?string $sentinelStatus = null;

    public ?string $metricsServerUuid = null;

    public bool $metricsEnabled = false;

    public ?string $configurationUrl = null;

    public ?string $volumeMountPath = null;

    public ?string $volumeParentId = null;

    public ?int $volumeStorageId = null;

    public bool $volumeIsOrphan = false;

    public string $volumeDeleteConfirm = '';

    public ?string $deleteServiceUrl = null;

    public ?string $domainFqdn = null;

    public ?string $domainsUrl = null;

    public bool $createAssistantOpen = false;

    /** category|service|configure */
    public string $createStep = 'category';

    public ?string $createCategory = null;

    public string $createSearch = '';

    public ?string $openHref = null;

    /** @var list<array{id: string, label: string, kind: string}> */
    public array $connectedNodes = [];

    /** @var list<array{id: string, label: string, fqdn?: string|null, config_href?: string|null}> */
    public array $inspectorDomains = [];

    /** @var list<array{id: string, label: string, mount_path?: string|null, config_href?: string|null}> */
    public array $inspectorVolumes = [];

    public bool $saveStackOpen = false;

    public string $stackTemplateName = '';

    public string $stackTemplateDescription = '';

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->syncCreateAssistantContext();
        $this->refreshGraph(replacePositions: false, notify: false);

        if (filled($this->inspector) && filled($this->inspectorNodeId)) {
            $this->openInspector($this->inspectorNodeId, $this->inspector === 'github' ? 'github' : 'details');
        }
    }

    public function openCreateAssistant(): void
    {
        $this->authorize('createAnyResource');
        $this->resetCreateAssistant(open: true);
    }

    public function closeCreateAssistant(): void
    {
        $this->resetCreateAssistant(open: false);
    }

    public function updatedCreateAssistantOpen(bool $open): void
    {
        if ($open) {
            $this->authorize('createAnyResource');
            $this->resetCreateAssistant(open: true);

            return;
        }

        $this->createStep = 'category';
        $this->createCategory = null;
        $this->createSearch = '';
    }

    public function selectCreateCategory(string $key): void
    {
        $this->authorize('createAnyResource');
        $key = trim($key);
        if ($key === '') {
            return;
        }

        if ($key === 'agent') {
            $this->selectCreateOption(self::CREATE_OPTION_AI_AGENT);

            return;
        }

        if ($key === 'github') {
            $this->selectCreateOption(self::CREATE_OPTION_GITHUB);

            return;
        }

        $this->createCategory = $key;
        $this->createStep = 'service';
        $this->createSearch = '';
        $this->selectedService = '';
        $this->serviceVersion = '';
    }

    public function createAssistantBack(): void
    {
        if ($this->createStep === 'configure') {
            $this->createStep = 'service';
            $this->selectedService = '';
            $this->serviceVersion = '';

            return;
        }

        if ($this->createStep === 'service') {
            $this->createStep = 'category';
            $this->createCategory = null;
            $this->createSearch = '';
            $this->selectedService = '';
        }
    }

    public function selectCreateOption(string $value): mixed
    {
        $this->authorize('createAnyResource');
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($value === self::CREATE_OPTION_AI_AGENT) {
            $this->resetCreateAssistant(open: false);
            $this->addAgentNode();

            return null;
        }

        if ($value === self::CREATE_OPTION_GITHUB) {
            $this->resetCreateAssistant(open: false);

            return $this->connectGithubNode();
        }

        if (str_starts_with($value, self::CREATE_OPTION_STACK_PREFIX)) {
            $this->resetCreateAssistant(open: false);

            return $this->applyStackTemplate((int) substr($value, strlen(self::CREATE_OPTION_STACK_PREFIX)));
        }

        $this->selectedService = $value;
        $this->serviceVersion = '';
        $this->ensureDefaultOdooVersion();
        $this->createStep = 'configure';

        return null;
    }

    /**
     * Add the chosen catalog service to the canvas without starting containers.
     * Deploy is a separate toolbar action once nodes are connected.
     */
    public function addCatalogServiceToCanvas(): mixed
    {
        try {
            $this->authorize('createAnyResource');
            if ($this->serverId === 'new') {
                if (! auth()->user()?->canAddServers()) {
                    throw new \RuntimeException(__('The owner has to add a server, or allow you to add servers, before you can create a project.'));
                }

                return redirect()->route('server.create');
            }

            $name = trim($this->selectedService);
            if ($name === '' || $name === self::CREATE_OPTION_AI_AGENT || $name === self::CREATE_OPTION_GITHUB
                || str_starts_with($name, self::CREATE_OPTION_STACK_PREFIX)) {
                throw new \RuntimeException(__('Choose a service to launch.'));
            }
            $this->assertCatalogEnvironmentRole();

            $project = $this->catalogLaunchProject();
            $environment = $this->catalogLaunchEnvironment();
            $service = null;

            if ($name === 'odoo') {
                $this->validateCatalogOdooVersion();
                if ($project->odooProfile === null) {
                    $project->enableOdoo($this->odooVersion);
                    $project->refresh();
                }
                $service = $this->existingOdooService($environment)
                    ?? $this->createOdooService($project, $environment, start: false, destination: $this->chosenDestination($project, $environment));
            } else {
                GetOdooAreaEntitlements::assertServiceAllowed(currentTeam(), $name);
                $this->assertCatalogServiceVersion($name);
                $service = $this->createCatalogService($name, $environment, $this->chosenDestination($project, $environment));
            }

            if (! $service instanceof Service) {
                return null;
            }

            $this->resetCreateAssistant(open: false);
            $this->environment->unsetRelation('services');
            $this->refreshGraph(replacePositions: false, notify: false);
            $this->persistMissingFlowPositions();
            $this->dispatch(
                'canvas-graph-updated',
                graph: $this->graph,
                replacePositions: false,
            );

            $hub = collect($this->graph['nodes'])->first(
                fn (array $node): bool => ($node['service_uuid'] ?? null) === $service->uuid
                    && str_starts_with((string) ($node['id'] ?? ''), 'stack-app:')
            );
            if (is_array($hub) && filled($hub['id'] ?? null)) {
                $this->openInspector((string) $hub['id'], 'details');
            }

            $this->dispatch('success', __('Added to canvas. Connect it to other nodes, then Deploy when ready.'));

            return null;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deployPendingServices(): void
    {
        try {
            $pending = $this->pendingDeployServices();
            if ($pending->isEmpty()) {
                $this->dispatch('info', __('Nothing waiting to deploy.'));

                return;
            }

            foreach ($pending as $service) {
                $this->authorize('deploy', $service);
                StartService::run($service, pullLatestImages: true);
            }

            $this->refreshGraph(replacePositions: false, notify: true);
            $this->dispatch('success', __('Deploy started for :count service(s).', ['count' => $pending->count()]));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    protected function catalogLaunchProject(): Project
    {
        return $this->project;
    }

    protected function catalogLaunchEnvironment(): Environment
    {
        return $this->environment;
    }

    private function syncCreateAssistantContext(): void
    {
        $role = (string) ($this->environment->role ?? Environment::ROLE_PRODUCTION);
        $this->environmentRole = in_array($role, [Environment::ROLE_PRODUCTION, Environment::ROLE_STAGING], true)
            ? $role
            : Environment::ROLE_PRODUCTION;
        $this->defaultCatalogServerId($this->project, $this->environment);
        $this->ensureDefaultOdooVersion();
    }

    private function resetCreateAssistant(bool $open): void
    {
        $this->syncCreateAssistantContext();
        $this->createStep = 'category';
        $this->createCategory = null;
        $this->createSearch = '';
        $this->selectedService = '';
        $this->serviceVersion = '';
        $this->createAssistantOpen = $open;
    }

    /**
     * @return list<array{key: string, label: string, description: string, count: int}>
     */
    protected function createAssistantCategories(): array
    {
        $groups = collect($this->createAssistantGroups(includeAgent: true, categoryFilter: null, search: ''));
        $categories = [];

        foreach ($groups as $group) {
            $categories[] = [
                'key' => (string) $group['key'],
                'label' => (string) $group['label'],
                'description' => match ($group['key']) {
                    'agent' => __('Project assistant with tools limited to connected canvas nodes.'),
                    'github' => __('Link your GitHub account and choose a repository for Odoo addons.'),
                    'stacks' => __('Saved multi-service layouts for this team.'),
                    'databases' => __('Postgres, Redis, Mongo, and other data stores.'),
                    'ai' => __('LLM apps, vector DBs, and AI tooling.'),
                    'monitoring' => __('Metrics, logs, and observability stacks.'),
                    'storage' => __('Object storage, search, and file systems.'),
                    'messaging' => __('Mail, chat, and queues.'),
                    'devtools' => __('Portainer, CI, Git, and developer utilities.'),
                    default => __('Apps and backends for this environment.'),
                },
                'count' => count($group['items'] ?? []),
            ];
        }

        return $categories;
    }

    /**
     * High-level buckets for the in-canvas Create assistant (Databases, AI, Services, …).
     *
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    protected function createAssistantGroups(?bool $includeAgent = null, ?string $categoryFilter = null, ?string $search = null): array
    {
        $includeAgent ??= true;
        $categoryFilter ??= $this->createCategory;
        $search ??= mb_strtolower(trim($this->createSearch));
        $groups = [];

        if ($includeAgent && ! $this->project->agent()->exists() && ($categoryFilter === null || $categoryFilter === 'agent')) {
            $agentLabel = __('AI agent');
            $agentDescription = __('Optional project assistant. Connect it to stack nodes to grant tool access.');
            $agentHaystack = mb_strtolower($agentLabel.' '.$agentDescription.' ai agent agente');
            if ($search === '' || str_contains($agentHaystack, $search)) {
                $groups[] = [
                    'key' => 'agent',
                    'label' => __('AI agent'),
                    'items' => [[
                        'value' => self::CREATE_OPTION_AI_AGENT,
                        'label' => $agentLabel,
                        'description' => $agentDescription,
                        'category' => 'agent',
                        'logo' => null,
                        'kind' => 'agent',
                        'services' => [],
                        'volumes' => [],
                        'parameters' => [],
                        'needs_github' => false,
                    ]],
                ];
            }
        }

        $odooService = $this->existingOdooService($this->environment);
        if ($odooService instanceof Service && ($categoryFilter === null || $categoryFilter === 'github')) {
            $githubLabel = __('GitHub');
            $githubDescription = __('Sign in with GitHub, install the app, and link repositories to this user.');
            $githubHaystack = mb_strtolower($githubLabel.' '.$githubDescription.' github git repo');
            if ($search === '' || str_contains($githubHaystack, $search)) {
                $groups[] = [
                    'key' => 'github',
                    'label' => __('GitHub'),
                    'items' => [[
                        'value' => self::CREATE_OPTION_GITHUB,
                        'label' => $githubLabel,
                        'description' => $githubDescription,
                        'category' => 'github',
                        'logo' => null,
                        'kind' => 'github',
                        'services' => [],
                        'volumes' => [],
                        'parameters' => [],
                        'needs_github' => true,
                    ]],
                ];
            }
        }

        if ($categoryFilter === null || $categoryFilter === 'stacks') {
            $stackItems = [];
            foreach (EnvironmentStackTemplateCatalog::forTeam((int) $this->project->team_id) as $template) {
                $label = (string) $template->display_name;
                $description = (string) ($template->description ?: __('Saved stack template'));
                $haystack = mb_strtolower($label.' '.$description.' stack template plantilla');
                if ($search !== '' && ! str_contains($haystack, $search)) {
                    continue;
                }
                $stackItems[] = [
                    'value' => self::CREATE_OPTION_STACK_PREFIX.$template->id,
                    'label' => $label,
                    'description' => $description,
                    'category' => 'stacks',
                    'logo' => null,
                    'kind' => 'stack',
                    'services' => [],
                    'volumes' => [],
                    'parameters' => [],
                    'needs_github' => false,
                ];
            }
            if ($stackItems !== []) {
                $groups[] = [
                    'key' => 'stacks',
                    'label' => __('My stacks'),
                    'items' => $stackItems,
                ];
            }
        }

        $buckets = [
            'databases' => ['label' => __('Databases'), 'order' => 10, 'items' => []],
            'ai' => ['label' => __('Artificial intelligence'), 'order' => 20, 'items' => []],
            'monitoring' => ['label' => __('Monitoring'), 'order' => 30, 'items' => []],
            'storage' => ['label' => __('Storage'), 'order' => 40, 'items' => []],
            'messaging' => ['label' => __('Messaging'), 'order' => 50, 'items' => []],
            'devtools' => ['label' => __('Developer tools'), 'order' => 60, 'items' => []],
            'services' => ['label' => __('Services'), 'order' => 100, 'items' => []],
        ];

        foreach ($this->catalogServiceOptions() as $row) {
            $bucket = $this->createAssistantBucket(is_string($row['category'] ?? null) ? $row['category'] : null);
            if ($categoryFilter !== null && $categoryFilter !== $bucket) {
                continue;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['label'] ?? null,
                $row['value'] ?? null,
                $row['category'] ?? null,
                $row['description'] ?? null,
            ], fn ($part): bool => is_string($part) && $part !== '')));
            if ($search !== '' && ! str_contains($haystack, $search)) {
                continue;
            }

            $buckets[$bucket]['items'][] = $row + ['kind' => 'service'];
        }

        foreach (collect($buckets)->sortBy('order') as $key => $bucket) {
            if ($bucket['items'] === []) {
                continue;
            }
            $groups[] = [
                'key' => (string) $key,
                'label' => $bucket['label'],
                'items' => $bucket['items'],
            ];
        }

        return $groups;
    }

    private function createAssistantBucket(?string $category): string
    {
        $raw = strtolower(trim((string) $category));
        $parts = preg_split('/[,\s]+/', $raw) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

        foreach ($parts as $part) {
            if (str_contains($part, 'database') || $part === 'databases') {
                return 'databases';
            }
            if (in_array($part, ['ai', 'mcp'], true)) {
                return 'ai';
            }
            if (in_array($part, ['monitoring', 'analytics'], true) || str_contains($part, 'observab')) {
                return 'monitoring';
            }
            if (in_array($part, ['storage', 'search'], true)) {
                return 'storage';
            }
            if (in_array($part, ['messaging', 'email', 'mail', 'communication'], true)) {
                return 'messaging';
            }
            if (in_array($part, ['devtools', 'development', 'developer-tools', 'git', 'ci'], true)) {
                return 'devtools';
            }
        }

        return 'services';
    }

    /**
     * @return Collection<int, Service>
     */
    protected function pendingDeployServices(): Collection
    {
        $this->environment->loadMissing('services');

        return $this->environment->services
            ->filter(fn (Service $service): bool => ! $service->isRunning() && ! $service->isStarting())
            ->values();
    }

    /**
     * Save-as-template only when the canvas has something worth reusing
     * (multiple services, user links, or an agent) — not a single stock launch.
     */
    protected function canSaveStackTemplate(): bool
    {
        $this->environment->loadMissing(['services', 'canvasLayout']);
        $this->project->loadMissing('agent');

        if ($this->environment->services->count() >= 2) {
            return true;
        }

        if (count($this->environment->canvasLayout?->connectionsList() ?? []) > 0) {
            return true;
        }

        return $this->project->agent !== null;
    }

    public function refreshGraph(bool $replacePositions = false, bool $notify = true): void
    {
        $this->authorize('view', $this->project);
        $this->environment->refresh();
        $this->environment->unsetRelation('services');
        $this->graph = EnvironmentCanvasGraph::build($this->environment);

        if ($notify) {
            $this->dispatch(
                'canvas-graph-updated',
                graph: $this->graph,
                replacePositions: $replacePositions,
            );
        }
    }

    public function pollGraph(): void
    {
        // Skip heavy rebuilds while the create modal is open — keeps the assistant snappy on remote servers.
        if ($this->createAssistantOpen) {
            return;
        }

        $before = $this->graphFingerprint($this->graph);
        $this->authorize('view', $this->project);
        $this->environment->refresh();
        $this->environment->unsetRelation('services');
        $this->graph = EnvironmentCanvasGraph::build($this->environment);
        $after = $this->graphFingerprint($this->graph);

        if ($before !== $after) {
            $this->dispatch(
                'canvas-graph-updated',
                graph: $this->graph,
                replacePositions: false,
            );
        }

        if ($this->inspectorTab === 'logs' && filled($this->inspectorNodeId)) {
            $this->refreshLogs();
        }
    }

    /**
     * @param  array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>}  $graph
     */
    private function graphFingerprint(array $graph): string
    {
        $nodes = collect($graph['nodes'] ?? [])
            ->map(fn (array $node): array => [
                $node['id'] ?? null,
                $node['status'] ?? null,
                $node['status_label'] ?? null,
                $node['open_href'] ?? null,
            ])
            ->all();
        $edges = collect($graph['edges'] ?? [])
            ->map(fn (array $edge): array => [$edge['id'] ?? null, $edge['from'] ?? null, $edge['to'] ?? null])
            ->all();

        return hash('sha256', (string) json_encode([$nodes, $edges]));
    }

    public function openInspector(string $nodeId, string $panel = 'details'): void
    {
        $this->authorize('view', $this->project);
        $node = collect($this->graph['nodes'])->firstWhere('id', $nodeId);
        if (! is_array($node)) {
            return;
        }

        $kind = (string) ($node['kind'] ?? 'service');
        $this->inspectorNodeId = $nodeId;
        $this->inspectorTitle = (string) ($node['label'] ?? $kind);
        $this->inspectorKind = $kind;
        $this->inspectorStatus = (string) ($node['status_label'] ?? $node['status'] ?? '');
        $this->inspectorStatusTone = $this->statusToneFor((string) ($node['status'] ?? ''), $this->inspectorStatus);
        $this->monitorEmbedUrl = is_string($node['monitor_url'] ?? null) ? $node['monitor_url'] : null;
        $this->logOutput = '';
        $this->stackRules = is_array($node['rules'] ?? null)
            ? array_values(array_filter($node['rules'], fn ($rule): bool => is_string($rule) && $rule !== ''))
            : [];
        $this->githubServiceUuid = is_string($node['service_uuid'] ?? null) ? $node['service_uuid'] : null;
        $this->configurationUrl = is_string($node['config_href'] ?? null) ? $node['config_href'] : null;
        $this->openHref = is_string($node['open_href'] ?? null)
            ? $node['open_href']
            : (is_string($node['href'] ?? null) ? $node['href'] : null);
        $this->connectedNodes = $this->connectedNodesFor($nodeId);
        $this->inspectorDomains = collect($node['domains'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['label'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (string) ($row['id'] ?? $row['label']),
                'label' => (string) $row['label'],
                'fqdn' => is_string($row['fqdn'] ?? null) ? $row['fqdn'] : null,
                'config_href' => is_string($row['config_href'] ?? null) ? $row['config_href'] : null,
            ])
            ->values()
            ->all();
        $this->inspectorVolumes = collect($node['volumes'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['label'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (string) ($row['id'] ?? $row['label']),
                'label' => (string) $row['label'],
                'mount_path' => is_string($row['mount_path'] ?? null) ? $row['mount_path'] : null,
                'config_href' => is_string($row['config_href'] ?? null) ? $row['config_href'] : null,
            ])
            ->values()
            ->all();
        $this->volumeMountPath = is_string($node['mount_path'] ?? null) ? $node['mount_path'] : null;
        $this->volumeParentId = is_string($node['parent_id'] ?? null) ? $node['parent_id'] : null;
        $this->volumeStorageId = is_numeric($node['storage_id'] ?? null) ? (int) $node['storage_id'] : null;
        $this->volumeIsOrphan = (bool) ($node['orphan'] ?? false);
        $this->volumeDeleteConfirm = '';
        $this->deleteServiceUrl = null;
        if (filled($this->githubServiceUuid)) {
            $this->deleteServiceUrl = route('project.service.danger', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'service_uuid' => $this->githubServiceUuid,
            ]);
        }
        $this->domainFqdn = is_string($node['fqdn'] ?? null) ? $node['fqdn'] : (is_string($node['href'] ?? null) ? $node['href'] : null);
        $this->domainsUrl = null;
        if ($kind === 'domain' && filled($this->githubServiceUuid)) {
            $this->domainsUrl = is_string($node['config_href'] ?? null)
                ? $node['config_href']
                : route('project.service.domains', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $this->environment->uuid,
                    'service_uuid' => $this->githubServiceUuid,
                ]);
            $this->configurationUrl = $this->domainsUrl;
        }
        $this->resolveSentinel($node);
        if (! filled($this->configurationUrl) && filled($this->githubServiceUuid)) {
            $this->configurationUrl = route('project.service.configuration', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'service_uuid' => $this->githubServiceUuid,
            ]);
        }

        $panel = trim($panel);
        if ($panel === 'config' && filled($this->configurationUrl)) {
            $this->redirect($this->configurationUrl, navigate: true);

            return;
        }
        if ($kind === 'github' || $panel === 'github') {
            $this->inspector = 'github';
            $this->inspectorTab = 'github';
            if (! filled($this->githubServiceUuid) && str_starts_with($nodeId, 'github:')) {
                $this->githubServiceUuid = substr($nodeId, strlen('github:'));
            }
            $this->syncAgentCloudVisibility();

            return;
        }

        if ($kind === 'agent' || $panel === 'chat' || ($panel === 'settings' && $kind === 'agent')) {
            $this->inspector = 'agent';
            $this->inspectorTab = $panel === 'settings' ? 'settings' : 'chat';
            $this->syncAgentCloudVisibility();

            return;
        }

        $this->inspector = 'node';
        $this->inspectorTab = match ($panel) {
            'logs' => 'logs',
            'monitor', 'metrics' => 'metrics',
            'settings' => 'settings',
            'chat' => 'chat',
            default => in_array($kind, ['volume', 'domain'], true) ? 'settings' : 'details',
        };
        $this->syncAgentCloudVisibility();

        if ($this->inspectorTab === 'logs') {
            $this->refreshLogs();
        }
    }

    public function setInspectorTab(string $tab): void
    {
        if (! in_array($tab, ['details', 'logs', 'metrics', 'github', 'settings', 'chat'], true)) {
            return;
        }
        $this->inspectorTab = $tab;
        if ($tab === 'logs') {
            $this->refreshLogs();
        }
        if ($tab === 'github') {
            $this->inspector = 'github';
        }
        if (in_array($tab, ['chat', 'settings'], true) && $this->inspectorKind === 'agent') {
            $this->inspector = 'agent';
        }
        $this->syncAgentCloudVisibility();
    }

    /**
     * Any open canvas inspector owns the right edge — hide the floating cloud.
     */
    private function syncAgentCloudVisibility(): void
    {
        $this->dispatch('canvas-agent-side-chat', active: filled($this->inspector))->to(CloudShell::class);
    }

    private function statusToneFor(string $status, string $label): string
    {
        $haystack = strtolower($status.' '.$label);
        if (str_contains($haystack, 'running') || str_contains($haystack, 'healthy') || str_contains($haystack, 'ready')) {
            return 'running';
        }
        if (str_contains($haystack, 'exited') || str_contains($haystack, 'stopped') || str_contains($haystack, 'deten') || str_contains($haystack, 'failed') || str_contains($haystack, 'unhealthy')) {
            return 'stopped';
        }
        if (str_contains($haystack, 'starting') || str_contains($haystack, 'building') || str_contains($haystack, 'pending') || str_contains($haystack, 'restart')) {
            return 'pending';
        }

        return 'neutral';
    }

    public function addAgentNode(): void
    {
        $this->authorize('update', $this->project);
        $agent = $this->project->agent()->firstOrCreate(
            ['project_id' => $this->project->id],
            [
                'name' => 'Project agent',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'is_enabled' => false,
                'follow_in_cloud' => true,
            ],
        );
        $this->refreshGraph(replacePositions: false, notify: true);
        $this->openInspector('agent:'.$this->project->uuid, 'settings');
        $this->dispatch('success', __('AI agent added. Connect it to stack nodes, then configure the provider.'));
        unset($agent);
    }

    /**
     * Add/open the GitHub node: install the App when missing, remember it on
     * team_user.github_app_id, then open the canvas GitHub panel.
     */
    public function connectGithubNode(): mixed
    {
        try {
            $this->authorize('update', $this->project);
            $service = $this->existingOdooService($this->environment);
            if (! $service instanceof Service) {
                $this->dispatch('error', __('Add an Odoo service before connecting GitHub.'));

                return null;
            }

            $user = auth()->user();
            $teamId = (int) $this->project->team_id;
            $returnParams = [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'inspect' => 'github',
                'node' => 'github:'.$service->uuid,
            ];

            $installed = OdooGit::installedApp($teamId, $user?->id);
            if ($installed instanceof GithubApp && filled($installed->installation_id)) {
                if ($user !== null) {
                    OdooGit::rememberForUser((int) $user->id, $teamId, $installed);
                }
                $this->project->loadMissing('odooProfile');
                if ($this->project->odooProfile !== null && (int) ($this->project->odooProfile->github_app_id ?? 0) === 0) {
                    $this->project->odooProfile->update(['github_app_id' => $installed->id]);
                }
                $this->refreshGraph(replacePositions: false, notify: true);
                $this->openInspector('github:'.$service->uuid, 'github');
                $this->dispatch('success', __('GitHub account linked. Choose a repository and branch, then save.'));

                return null;
            }

            $githubApp = OdooGit::beginConnect(
                $this->project,
                'project.environment.canvas',
                $returnParams,
                forceNew: false,
            );

            return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function openSaveStackTemplate(): void
    {
        $this->authorize('update', $this->project);
        $this->stackTemplateName = trim((string) $this->project->name.' '.$this->environment->name);
        $this->stackTemplateDescription = '';
        $this->saveStackOpen = true;
    }

    public function closeSaveStackTemplate(): void
    {
        $this->saveStackOpen = false;
        $this->stackTemplateName = '';
        $this->stackTemplateDescription = '';
    }

    public function saveStackTemplate(): void
    {
        try {
            $this->authorize('update', $this->project);
            $template = EnvironmentStackTemplateCatalog::saveFromEnvironment(
                $this->environment->fresh(['services.applications', 'services.databases', 'canvasLayout', 'project.odooProfile']),
                $this->stackTemplateName,
                $this->stackTemplateDescription,
                (int) $this->project->team_id,
                auth()->id(),
            );
            $this->closeSaveStackTemplate();
            $this->dispatch('success', __('Stack template “:name” saved. It appears under My stacks when you Create.', [
                'name' => $template->display_name,
            ]));
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('error', __($e->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function applyStackTemplate(int $templateId): mixed
    {
        try {
            $this->authorize('createAnyResource');
            $template = EnvironmentStackTemplate::query()
                ->where('team_id', $this->project->team_id)
                ->whereKey($templateId)
                ->firstOrFail();

            if ($this->serverId === 'new') {
                if (! auth()->user()?->canAddServers()) {
                    throw new \RuntimeException(__('The owner has to add a server, or allow you to add servers, before you can create a project.'));
                }

                return redirect()->route('server.create');
            }

            $project = $this->catalogLaunchProject();
            $environment = $this->catalogLaunchEnvironment();
            $destination = $this->chosenDestination($project, $environment);
            $keyToService = [];

            foreach ($template->servicesList() as $slot) {
                $type = $slot['service_type'];
                if ($type === 'odoo') {
                    if ($project->odooProfile === null) {
                        $this->odooVersion = (string) ($slot['odoo_version'] ?: '20');
                        $project->enableOdoo($this->odooVersion);
                        $project->refresh();
                    }
                    $service = $this->existingOdooService($environment)
                        ?? $this->createOdooService($project, $environment, start: false, destination: $destination);
                } else {
                    GetOdooAreaEntitlements::assertServiceAllowed(currentTeam(), $type);
                    $service = $this->createCatalogService($type, $environment, $destination);
                }
                if (! $service instanceof Service) {
                    continue;
                }
                if ($slot['connect_to_docker_network'] && ! $service->connect_to_docker_network) {
                    $service->connect_to_docker_network = true;
                    $service->save();
                }
                $keyToService[$slot['key']] = $service;
            }

            $this->environment->unsetRelation('services');
            $this->environment->load(['services.applications', 'services.databases']);
            $this->refreshGraph(replacePositions: false, notify: false);

            $keyToHub = [];
            foreach ($keyToService as $key => $service) {
                $hub = collect($this->graph['nodes'])->first(
                    fn (array $node): bool => ($node['service_uuid'] ?? null) === $service->uuid
                        && str_starts_with((string) ($node['id'] ?? ''), 'stack-app:')
                );
                if (is_array($hub) && filled($hub['id'] ?? null)) {
                    $keyToHub[$key] = (string) $hub['id'];
                }
            }

            $layout = EnvironmentCanvasLayout::query()->firstOrCreate(
                ['environment_id' => $this->environment->id],
                ['positions' => [], 'groups' => [], 'connections' => []],
            );
            $connections = $layout->connectionsList();
            foreach ($template->connectionsList() as $edge) {
                $from = $keyToHub[$edge['from_key']] ?? null;
                $to = $keyToHub[$edge['to_key']] ?? null;
                if ($from === null || $to === null || $from === $to) {
                    continue;
                }
                if (! collect($connections)->contains(fn (array $row): bool => $row['from'] === $from && $row['to'] === $to)) {
                    $connections[] = [
                        'id' => (string) $edge['id'],
                        'from' => $from,
                        'to' => $to,
                        'label' => (string) ($edge['label'] ?? 'intranet'),
                    ];
                }
                $this->ensureIntranetTunnel($from, $to);
            }
            $layout->connections = $connections;
            $layout->save();

            $this->refreshGraph(replacePositions: false, notify: false);
            $this->persistMissingFlowPositions();
            $this->dispatch('canvas-graph-updated', graph: $this->graph, replacePositions: false);
            $this->dispatch('success', __('Stack “:name” added to the canvas. Connect anything else, then Deploy.', [
                'name' => $template->display_name,
            ]));

            return null;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    #[Renderless]
    public function saveConnection(string $from, string $to, ?string $edgeId = null): void
    {
        $this->authorize('update', $this->project);
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        $nodeIds = collect($this->graph['nodes'])->pluck('id')->all();
        if (! in_array($from, $nodeIds, true) || ! in_array($to, $nodeIds, true)) {
            return;
        }

        $isAgentLink = str_starts_with($from, 'agent:') || str_starts_with($to, 'agent:');
        $label = $isAgentLink ? 'context' : 'intranet';

        $layout = EnvironmentCanvasLayout::query()->firstOrCreate(
            ['environment_id' => $this->environment->id],
            ['positions' => [], 'groups' => [], 'connections' => []],
        );
        $connections = $layout->connectionsList();
        $id = filled($edgeId) ? (string) $edgeId : 'link:'.$from.'->'.$to;
        if (! collect($connections)->contains(fn (array $row): bool => $row['from'] === $from && $row['to'] === $to)) {
            $connections[] = [
                'id' => $id,
                'from' => $from,
                'to' => $to,
                'label' => $label,
            ];
        }
        $layout->connections = $connections;
        $layout->save();

        if ($isAgentLink) {
            $agentNode = str_starts_with($from, 'agent:') ? $from : $to;
            $target = $agentNode === $from ? $to : $from;
            $agent = $this->project->agent()->firstOrCreate(
                ['project_id' => $this->project->id],
                ['name' => 'Project agent', 'provider' => 'openai', 'model' => 'gpt-4o-mini'],
            );
            $connected = $agent->connectedNodeIds();
            if (! in_array($target, $connected, true)) {
                $connected[] = $target;
                $settings = $agent->settings ?? [];
                $settings['connected_node_ids'] = array_values($connected);
                $agent->settings = $settings;
                $agent->save();
            }
        } else {
            $this->ensureIntranetTunnel($from, $to);
        }

        $this->refreshGraph(replacePositions: false, notify: true);
        if (! $isAgentLink) {
            $this->dispatch('success', __('Intranet link saved. Services share the destination network — no host ports published.'));
        }
    }

    #[Renderless]
    public function removeConnection(string $from, string $to, ?string $edgeId = null): void
    {
        $this->authorize('update', $this->project);
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        $layout = EnvironmentCanvasLayout::query()
            ->where('environment_id', $this->environment->id)
            ->first();
        if ($layout) {
            $edgeId = filled($edgeId) ? (string) $edgeId : null;
            $connections = collect($layout->connectionsList())
                ->reject(function (array $row) use ($from, $to, $edgeId): bool {
                    if ($edgeId !== null && $row['id'] === $edgeId) {
                        return true;
                    }

                    return ($row['from'] === $from && $row['to'] === $to)
                        || ($row['from'] === $to && $row['to'] === $from);
                })
                ->values()
                ->all();
            $layout->connections = $connections;
            $layout->save();
        }

        $isAgentLink = str_starts_with($from, 'agent:') || str_starts_with($to, 'agent:');
        if ($isAgentLink) {
            $agentNode = str_starts_with($from, 'agent:') ? $from : $to;
            $target = $agentNode === $from ? $to : $from;
            $agent = $this->project->agent;
            if ($agent) {
                $settings = $agent->settings ?? [];
                $settings['connected_node_ids'] = array_values(array_filter(
                    $agent->connectedNodeIds(),
                    fn (string $id): bool => $id !== $target,
                ));
                $agent->settings = $settings;
                $agent->save();
            }
        }

        $this->refreshGraph(replacePositions: false, notify: true);
        if (filled($this->inspectorNodeId)) {
            $this->connectedNodes = $this->connectedNodesFor($this->inspectorNodeId);
        }
        $this->dispatch('success', __('Connection removed.'));
    }

    public function disconnectFromInspector(string $otherNodeId, ?string $edgeId = null): void
    {
        if (! filled($this->inspectorNodeId) || ! filled($otherNodeId)) {
            return;
        }
        $this->removeConnection($this->inspectorNodeId, $otherNodeId, $edgeId);
    }

    /**
     * Canvas edges between stacks join the shared destination Docker network
     * (Coolify “predefined network”) so containers talk by alias/intranet DNS.
     */
    private function ensureIntranetTunnel(string $fromNodeId, string $toNodeId): void
    {
        $fromUuid = $this->serviceUuidForNode($fromNodeId);
        $toUuid = $this->serviceUuidForNode($toNodeId);
        if ($fromUuid === null || $toUuid === null || $fromUuid === $toUuid) {
            return;
        }

        $services = Service::query()
            ->where('environment_id', $this->environment->id)
            ->whereIn('uuid', [$fromUuid, $toUuid])
            ->with(['destination', 'server', 'applications', 'databases'])
            ->get();

        foreach ($services as $service) {
            if (! $service->connect_to_docker_network) {
                $service->connect_to_docker_network = true;
                $service->save();
            }
            $this->attachServiceToDestinationNetwork($service);
        }
    }

    private function serviceUuidForNode(string $nodeId): ?string
    {
        $node = collect($this->graph['nodes'] ?? [])->firstWhere('id', $nodeId);
        $uuid = is_string($node['service_uuid'] ?? null) ? $node['service_uuid'] : null;

        return filled($uuid) ? $uuid : null;
    }

    private function attachServiceToDestinationNetwork(Service $service): void
    {
        $server = $service->server;
        $network = $service->destination?->network;
        if (! $server?->isFunctional() || blank($network)) {
            return;
        }

        $containers = collect()
            ->merge($service->applications->map(fn ($app): string => (string) $app->name.'-'.$service->uuid))
            ->merge($service->databases->map(fn ($db): string => (string) $db->name.'-'.$service->uuid))
            ->filter(fn (string $name): bool => $name !== '-'.$service->uuid)
            ->unique()
            ->values();

        foreach ($containers as $container) {
            try {
                instant_remote_process([
                    'docker network connect --alias '.escapeshellarg($container).' '.escapeshellarg((string) $network).' '.escapeshellarg($container).' >/dev/null 2>&1 || true',
                ], $server);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function closeInspector(): void
    {
        $this->inspector = null;
        $this->inspectorNodeId = null;
        $this->inspectorTab = 'details';
        $this->inspectorTitle = null;
        $this->inspectorKind = null;
        $this->inspectorStatus = null;
        $this->inspectorStatusTone = 'neutral';
        $this->monitorEmbedUrl = null;
        $this->logOutput = '';
        $this->stackRules = [];
        $this->githubServiceUuid = null;
        $this->sentinelUrl = null;
        $this->sentinelStatus = null;
        $this->metricsServerUuid = null;
        $this->metricsEnabled = false;
        $this->configurationUrl = null;
        $this->openHref = null;
        $this->connectedNodes = [];
        $this->inspectorDomains = [];
        $this->inspectorVolumes = [];
        $this->volumeMountPath = null;
        $this->volumeParentId = null;
        $this->volumeStorageId = null;
        $this->volumeIsOrphan = false;
        $this->volumeDeleteConfirm = '';
        $this->deleteServiceUrl = null;
        $this->domainFqdn = null;
        $this->domainsUrl = null;
        $this->syncAgentCloudVisibility();
    }

    /**
     * Persist auto-flow positions for nodes the user has not dragged yet,
     * so each new service lands in a lane instead of overlapping.
     */
    private function persistMissingFlowPositions(): void
    {
        $layout = EnvironmentCanvasLayout::query()->firstOrCreate(
            ['environment_id' => $this->environment->id],
            ['positions' => [], 'groups' => [], 'connections' => []],
        );
        $positions = $layout->positionsMap();
        $changed = false;
        foreach ($this->graph['nodes'] as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id === '' || isset($positions[$id])) {
                continue;
            }
            $positions[$id] = [
                'x' => (float) ($node['x'] ?? 0),
                'y' => (float) ($node['y'] ?? 0),
            ];
            $changed = true;
        }
        if (! $changed) {
            return;
        }
        $layout->positions = $positions;
        $layout->save();
    }

    public function deleteOrphanVolume(): void
    {
        try {
            $this->authorize('update', $this->project);
            if (! $this->volumeIsOrphan || ! filled($this->volumeStorageId)) {
                $this->dispatch('error', __('Only orphan volumes can be deleted here.'));

                return;
            }

            $volume = LocalPersistentVolume::query()
                ->where('id', $this->volumeStorageId)
                ->where('resource_type', $this->environment->getMorphClass())
                ->where('resource_id', $this->environment->id)
                ->first();
            if (! $volume instanceof LocalPersistentVolume) {
                $this->dispatch('error', __('Orphan volume not found.'));

                return;
            }

            $expected = trim((string) $volume->name);
            if ($expected === '' || trim($this->volumeDeleteConfirm) !== $expected) {
                $this->dispatch('error', __('Type the volume name exactly to confirm deletion.'));

                return;
            }

            $this->removeDockerVolumeBestEffort($volume->name);

            $volume->delete();
            $this->closeInspector();
            $this->refreshGraph(replacePositions: false, notify: true);
            $this->dispatch('success', __('Orphan volume deleted.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Try environment servers first, then any functional team server.
     * Orphan volumes may outlive every service on this environment.
     */
    private function removeDockerVolumeBestEffort(string $volumeName): void
    {
        $servers = $this->environment->services()
            ->with('server')
            ->get()
            ->pluck('server')
            ->filter()
            ->merge(Server::ownedByCurrentTeam()->get())
            ->unique(fn (Server $server): int => (int) $server->id)
            ->values();

        foreach ($servers as $server) {
            if (! $server->isFunctional()) {
                continue;
            }
            try {
                instant_remote_process([
                    'docker volume rm -f '.escapeshellarg($volumeName),
                ], $server);

                return;
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @return list<array{id: string, label: string, kind: string, edge_id: string, from: string, to: string, removable: bool}>
     */
    private function connectedNodesFor(string $nodeId): array
    {
        $nodesById = collect($this->graph['nodes'] ?? [])->keyBy('id');

        return collect($this->graph['edges'] ?? [])
            ->filter(fn (array $edge): bool => ($edge['from'] ?? null) === $nodeId || ($edge['to'] ?? null) === $nodeId)
            ->map(function (array $edge) use ($nodeId, $nodesById): ?array {
                $otherId = ($edge['from'] ?? null) === $nodeId ? ($edge['to'] ?? null) : ($edge['from'] ?? null);
                if (! is_string($otherId) || $otherId === '') {
                    return null;
                }
                $other = $nodesById->get($otherId);
                if (! is_array($other)) {
                    return null;
                }
                $label = (string) ($edge['label'] ?? 'link');
                $removable = in_array($label, ['intranet', 'context', 'link', 'tunnel'], true)
                    || str_starts_with($nodeId, 'agent:')
                    || str_starts_with($otherId, 'agent:');

                return [
                    'id' => $otherId,
                    'label' => (string) ($other['label'] ?? $otherId),
                    'kind' => (string) ($other['kind'] ?? 'service'),
                    'edge_id' => (string) ($edge['id'] ?? ''),
                    'from' => (string) ($edge['from'] ?? ''),
                    'to' => (string) ($edge['to'] ?? ''),
                    'removable' => $removable,
                ];
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    public function refreshLogs(): void
    {
        if ($this->inspectorTab !== 'logs' || ! filled($this->inspectorNodeId)) {
            return;
        }

        $this->authorize('view', $this->project);
        $node = collect($this->graph['nodes'])->firstWhere('id', $this->inspectorNodeId);
        if (! is_array($node)) {
            $this->logOutput = __('Node not found.');

            return;
        }

        $container = is_string($node['container'] ?? null) ? $node['container'] : '';
        $serviceUuid = is_string($node['service_uuid'] ?? null) ? $node['service_uuid'] : '';
        if ($container === '' || $serviceUuid === '' || ! ValidationPatterns::isValidContainerName($container)) {
            $this->logOutput = __('No container logs for this node.');

            return;
        }

        $service = $this->environment->services->firstWhere('uuid', $serviceUuid)
            ?? Service::ownedByCurrentTeam()->where('uuid', $serviceUuid)->first();
        if (! $service instanceof Service || (int) $service->environment_id !== (int) $this->environment->id) {
            $this->logOutput = __('Service not found.');

            return;
        }

        if ($service->supportsOdooJupyter() && ! isInstanceOwner() && ! OdooGit::clientSeesLog($container)) {
            $this->logOutput = __('Unauthorized.');

            return;
        }

        $server = $service->server;
        if (! $server || ! $server->isFunctional()) {
            $this->logOutput = __('Server is not ready.');

            return;
        }

        try {
            $command = $server->isSwarm()
                ? "docker service logs -n 200 -t {$container}"
                : "docker logs -n 200 -t {$container}";
            if ($server->isNonRoot()) {
                $command = parseCommandsByLineForSudo(collect($command), $server)[0];
            }
            $ssh = SshMultiplexingHelper::generateSshCommand($server, "({$command}) 2>&1 | head -c 200000");
            $output = Process::timeout(config('constants.ssh.command_timeout'))->run($ssh)->output();
            $this->logOutput = rtrim(removeAnsiColors($output), "\n");
            if ($this->logOutput === '') {
                $this->logOutput = __('No log lines yet.');
            }
        } catch (\Throwable $e) {
            report($e);
            $this->logOutput = __('Could not load logs.');
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolveSentinel(array $node): void
    {
        $this->sentinelUrl = null;
        $this->sentinelStatus = null;
        $this->metricsServerUuid = null;
        $this->metricsEnabled = false;
        $serviceUuid = is_string($node['service_uuid'] ?? null) ? $node['service_uuid'] : null;
        if ($serviceUuid === null && is_string($this->githubServiceUuid)) {
            $serviceUuid = $this->githubServiceUuid;
        }
        if ($serviceUuid === null) {
            return;
        }
        $service = $this->environment->services->firstWhere('uuid', $serviceUuid);
        $server = $service?->server;
        if (! $server) {
            return;
        }
        $this->metricsServerUuid = $server->uuid;
        $this->metricsEnabled = $server->isMetricsEnabled();
        $this->sentinelStatus = $server->isSentinelEnabled()
            ? $server->sentinelStatus()
            : 'disabled';
        if ($server->isSentinelEnabled() && auth()->user()?->can('viewSentinel', $server)) {
            $this->sentinelUrl = route('server.sentinel', ['server_uuid' => $server->uuid]);
        }
    }

    #[Renderless]
    public function savePosition(string $nodeId, float $x, float $y): void
    {
        $this->authorize('update', $this->project);

        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            return;
        }

        $layout = EnvironmentCanvasLayout::query()->firstOrCreate(
            ['environment_id' => $this->environment->id],
            ['positions' => [], 'groups' => []],
        );

        $positions = $layout->positionsMap();
        $positions[$nodeId] = [
            'x' => max(0, round($x, 1)),
            'y' => max(0, round($y, 1)),
        ];
        $layout->positions = $positions;
        $layout->save();
    }

    public function resetLayout(): void
    {
        $this->authorize('update', $this->project);
        EnvironmentCanvasLayout::query()
            ->where('environment_id', $this->environment->id)
            ->delete();
        $this->refreshGraph(replacePositions: true, notify: true);
        $this->dispatch('success', __('Canvas layout reset.'));
    }

    public function render(): View
    {
        $metricsServer = null;
        if (filled($this->metricsServerUuid)) {
            $metricsServer = $this->environment->services
                ->map(fn (Service $service) => $service->server)
                ->filter()
                ->first(fn ($server) => $server->uuid === $this->metricsServerUuid);
        }

        $primaryConfigurationUrl = $this->configurationUrl;
        if (! filled($primaryConfigurationUrl)) {
            $primaryService = $this->environment->services
                ->sortBy(fn (Service $service): int => $service->supportsOdooJupyter() ? 0 : 1)
                ->first();
            if ($primaryService instanceof Service) {
                $primaryConfigurationUrl = route('project.service.configuration', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $this->environment->uuid,
                    'service_uuid' => $primaryService->uuid,
                ]);
            }
        }

        $this->project->loadMissing('agent');
        $pendingDeployServices = $this->pendingDeployServices();
        $pendingDeployCount = $pendingDeployServices->count();
        $canDeployPending = $pendingDeployServices->contains(
            fn (Service $service): bool => auth()->user()?->can('deploy', $service) ?? false
        );

        $createCategories = [];
        $createGroups = [];
        if ($this->createAssistantOpen) {
            $createCategories = $this->createAssistantCategories();
            $createGroups = $this->createStep === 'service'
                ? $this->createAssistantGroups(includeAgent: false, categoryFilter: $this->createCategory)
                : ($this->createStep === 'configure'
                    ? []
                    : $this->createAssistantGroups());
        }

        return view('livewire.project.environment.canvas', [
            'canUpdate' => auth()->user()?->can('update', $this->project) ?? false,
            'metricsServer' => $metricsServer,
            'primaryConfigurationUrl' => $primaryConfigurationUrl,
            'createCategories' => $createCategories,
            'createGroups' => $createGroups,
            'pendingDeployCount' => $pendingDeployCount,
            'canDeployPending' => $canDeployPending,
            'canSaveStackTemplate' => $this->canSaveStackTemplate(),
            ...$this->catalogLaunchVersionViewData(),
            'serverChoices' => OdooGit::launchChoices(),
            'hasOtherServers' => OdooGit::allowedLaunchServers()->contains(fn (Server $server): bool => (int) $server->id !== 0),
            'needsServer' => ! auth()->user()?->canLaunchOnInstanceServer() && OdooGit::allowedLaunchServers()->isEmpty(),
            'canAddServer' => (bool) auth()->user()?->canAddServers(),
            'projectServerLocked' => $this->assignedProjectDestination($this->project, $this->environment) !== null,
            'projectServerName' => $this->assignedProjectDestination($this->project, $this->environment)?->server?->name,
        ]);
    }
}
