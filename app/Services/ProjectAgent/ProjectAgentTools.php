<?php

namespace App\Services\ProjectAgent;

use App\Actions\Service\StartService;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ProjectAgent;
use App\Support\OdooAddons;

class ProjectAgentTools
{
    public function __construct(
        public ProjectAgent $agent,
        public ProjectAgentToolGate $gate,
    ) {
        // Always scope the gate through this agent so tools honor canvas edges.
        $this->gate = new ProjectAgentToolGate($gate->project, $agent);
    }

    /**
     * OpenAI-compatible tool definitions.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            $this->fn('list_connected_context', 'List canvas nodes connected to this agent and the services those edges unlock. Empty until the user connects nodes.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('list_project_services', 'List services reachable through the agent canvas connections only.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('get_environment_summary', 'Summarize one environment in this project (no secrets). Only reports services the agent is connected to.', [
                'type' => 'object',
                'properties' => [
                    'environment_uuid' => ['type' => 'string'],
                ],
                'required' => ['environment_uuid'],
            ]),
            $this->fn('list_deployments', 'List recent deployment history for a connected service.', [
                'type' => 'object',
                'properties' => [
                    'service_uuid' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                ],
                'required' => ['service_uuid'],
            ]),
            $this->fn('start_service', 'Queue Start for a connected service. Requires confirm=true.', [
                'type' => 'object',
                'properties' => [
                    'service_uuid' => ['type' => 'string'],
                    'confirm' => ['type' => 'boolean'],
                    'pull_latest_images' => ['type' => 'boolean'],
                ],
                'required' => ['service_uuid', 'confirm'],
            ]),
            $this->fn('restart_service', 'Queue Restart for a connected service. Requires confirm=true.', [
                'type' => 'object',
                'properties' => [
                    'service_uuid' => ['type' => 'string'],
                    'confirm' => ['type' => 'boolean'],
                ],
                'required' => ['service_uuid', 'confirm'],
            ]),
            $this->fn('rollback_addons', 'Queue an addons rollback on a connected service. Requires confirm=true and commit.', [
                'type' => 'object',
                'properties' => [
                    'service_uuid' => ['type' => 'string'],
                    'commit' => ['type' => 'string', 'description' => 'Git commit SHA from a previous finished deployment'],
                    'confirm' => ['type' => 'boolean'],
                ],
                'required' => ['service_uuid', 'commit', 'confirm'],
            ]),
            $this->fn('draft_odoo_module', 'Draft a minimal Odoo module scaffold. Requires a canvas connection to an Odoo stack node.', [
                'type' => 'object',
                'properties' => [
                    'service_uuid' => ['type' => 'string', 'description' => 'Connected Odoo service uuid'],
                    'technical_name' => ['type' => 'string'],
                    'display_name' => ['type' => 'string'],
                    'model_name' => ['type' => 'string'],
                    'odoo_version' => ['type' => 'string'],
                    'save' => ['type' => 'boolean', 'description' => 'Persist draft on the project agent for later apply'],
                ],
                'required' => ['service_uuid', 'technical_name', 'display_name'],
            ]),
            $this->fn('apply_odoo_module_draft', 'Write a saved module draft into the connected Odoo extra-addons volume. Requires confirm=true.', [
                'type' => 'object',
                'properties' => [
                    'service_uuid' => ['type' => 'string'],
                    'technical_name' => ['type' => 'string'],
                    'confirm' => ['type' => 'boolean'],
                ],
                'required' => ['service_uuid', 'technical_name', 'confirm'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(string $name, array $arguments): array
    {
        try {
            return match ($name) {
                'list_connected_context' => [
                    'connected_nodes' => $this->gate->describeConnectedNodes($this->gate->connectedNodeIds()),
                    'services' => $this->gate->listConnectedServices()->all(),
                    'hint' => $this->gate->hasConnections()
                        ? null
                        : 'Connect this agent to stack nodes on the canvas (apps, databases, volumes, GitHub) to grant access.',
                ],
                'list_project_services' => [
                    'services' => $this->gate->listServices()->all(),
                    'scoped_to_connections' => true,
                ],
                'get_environment_summary' => $this->environmentSummary((string) ($arguments['environment_uuid'] ?? '')),
                'list_deployments' => $this->listDeployments(
                    (string) ($arguments['service_uuid'] ?? ''),
                    (int) ($arguments['limit'] ?? 10),
                ),
                'start_service' => $this->startService(
                    (string) ($arguments['service_uuid'] ?? ''),
                    (bool) ($arguments['confirm'] ?? false),
                    (bool) ($arguments['pull_latest_images'] ?? true),
                ),
                'restart_service' => $this->restartService(
                    (string) ($arguments['service_uuid'] ?? ''),
                    (bool) ($arguments['confirm'] ?? false),
                ),
                'rollback_addons' => $this->rollbackAddons(
                    (string) ($arguments['service_uuid'] ?? ''),
                    (string) ($arguments['commit'] ?? ''),
                    (bool) ($arguments['confirm'] ?? false),
                ),
                'draft_odoo_module' => $this->draftOdooModule($arguments),
                'apply_odoo_module_draft' => $this->applyOdooModuleDraft(
                    (string) ($arguments['service_uuid'] ?? ''),
                    (string) ($arguments['technical_name'] ?? ''),
                    (bool) ($arguments['confirm'] ?? false),
                ),
                default => ['error' => 'Unknown tool: '.$name],
            };
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentSummary(string $environmentUuid): array
    {
        $environment = $this->gate->environment($environmentUuid);
        $environment->loadMissing(['odooBranch']);
        $connected = $this->gate->listConnectedServices()
            ->filter(fn (array $row): bool => ($row['environment_uuid'] ?? '') === $environment->uuid)
            ->values();

        return [
            'uuid' => $environment->uuid,
            'name' => $environment->name,
            'role' => $environment->role,
            'git_branch' => $environment->odooBranch?->git_branch,
            'odoo_version' => $environment->odooBranch?->odoo_version,
            'subdomain' => $environment->odooBranch?->subdomain,
            'services' => $connected->map(fn (array $row): array => [
                'uuid' => $row['uuid'],
                'name' => $row['name'],
                'status' => $row['status'],
                'via_nodes' => $row['via_nodes'],
            ])->all(),
            'scoped_to_connections' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listDeployments(string $serviceUuid, int $limit): array
    {
        $service = $this->gate->assertServiceConnected($serviceUuid);
        $application = $this->gate->addonsApplicationForService($service);
        if (! $application instanceof Application) {
            return [
                'service_uuid' => $service->uuid,
                'deployments' => [],
                'message' => 'No Odoo addons application is linked in this environment yet.',
            ];
        }

        $limit = max(1, min(20, $limit));
        $rows = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['deployment_uuid', 'commit', 'commit_message', 'status', 'rollback', 'created_at', 'finished_at']);

        return [
            'service_uuid' => $service->uuid,
            'application_uuid' => $application->uuid,
            'deployments' => $rows->map(fn (ApplicationDeploymentQueue $row): array => [
                'deployment_uuid' => $row->deployment_uuid,
                'commit' => $row->commit,
                'commit_message' => $row->commit_message,
                'status' => $row->status,
                'rollback' => (bool) $row->rollback,
                'created_at' => optional($row->created_at)?->toIso8601String(),
                'finished_at' => optional($row->finished_at)?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function startService(string $serviceUuid, bool $confirm, bool $pullLatestImages): array
    {
        if (! $confirm) {
            return ['error' => 'Refused: set confirm=true to start a service.'];
        }
        $service = $this->gate->assertServiceConnected($serviceUuid);
        StartService::dispatch($service, $pullLatestImages);

        return [
            'queued' => true,
            'action' => 'start',
            'service_uuid' => $service->uuid,
            'service_name' => $service->name,
            'pull_latest_images' => $pullLatestImages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restartService(string $serviceUuid, bool $confirm): array
    {
        if (! $confirm) {
            return ['error' => 'Refused: set confirm=true to restart a service.'];
        }
        $service = $this->gate->assertServiceConnected($serviceUuid);
        StartService::dispatch($service, false, true);

        return [
            'queued' => true,
            'action' => 'restart',
            'service_uuid' => $service->uuid,
            'service_name' => $service->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackAddons(string $serviceUuid, string $commit, bool $confirm): array
    {
        if (! $confirm) {
            return ['error' => 'Refused: set confirm=true to roll back addons.'];
        }
        $commit = trim($commit);
        if ($commit === '') {
            return ['error' => 'A previous finished commit SHA is required.'];
        }

        $service = $this->gate->assertServiceConnected($serviceUuid);
        $application = $this->gate->addonsApplicationForService($service);
        if (! $application instanceof Application) {
            return ['error' => 'No Odoo addons application is linked in this environment.'];
        }

        $finished = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('commit', $commit)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('pull_request_id', 0)
            ->exists();

        if (! $finished) {
            return ['error' => 'Commit is not a finished deployment for this project service.'];
        }

        $deploymentUuid = new_public_id();
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            commit: $commit,
            force_rebuild: true,
            is_webhook: false,
            rollback: true,
        );

        return [
            'queued' => ($result['status'] ?? null) === 'queued',
            'action' => 'rollback_addons',
            'service_uuid' => $service->uuid,
            'commit' => $commit,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function draftOdooModule(array $arguments): array
    {
        $service = $this->gate->assertServiceConnected((string) ($arguments['service_uuid'] ?? ''));
        if (! $service->supportsOdooJupyter()) {
            return ['error' => 'Connected service is not an Odoo stack. Connect the agent to an Odoo node first.'];
        }

        $draft = ProjectAgentOdooModuleDraft::scaffold(
            technicalName: (string) ($arguments['technical_name'] ?? ''),
            displayName: (string) ($arguments['display_name'] ?? ''),
            modelName: (string) ($arguments['model_name'] ?? 'item'),
            odooVersion: (string) ($arguments['odoo_version'] ?? '18.0'),
        );

        if ((bool) ($arguments['save'] ?? false)) {
            $settings = $this->agent->settings ?? [];
            $drafts = is_array($settings['drafts'] ?? null) ? $settings['drafts'] : [];
            $drafts[$draft['technical_name']] = $draft;
            $settings['drafts'] = $drafts;
            $this->agent->settings = $settings;
            $this->agent->save();
        }

        return [
            'technical_name' => $draft['technical_name'],
            'display_name' => $draft['display_name'],
            'saved' => (bool) ($arguments['save'] ?? false),
            'markdown' => ProjectAgentOdooModuleDraft::toMarkdown($draft),
            'files' => array_keys($draft['files']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyOdooModuleDraft(string $serviceUuid, string $technicalName, bool $confirm): array
    {
        if (! $confirm) {
            return ['error' => 'Refused: set confirm=true to write the module into the addons volume.'];
        }

        $technical = ProjectAgentOdooModuleDraft::normalizeTechnicalName($technicalName);
        $draft = data_get($this->agent->settings, 'drafts.'.$technical);
        if (! is_array($draft) || ! is_array($draft['files'] ?? null)) {
            return ['error' => 'No saved draft found for '.$technical.'. Call draft_odoo_module with save=true first.'];
        }

        $service = $this->gate->assertServiceConnected($serviceUuid);
        if (! $service->supportsOdooJupyter()) {
            return ['error' => 'Connected service is not an Odoo stack.'];
        }
        if (! $service->server) {
            return ['error' => 'Service has no server to write the module volume.'];
        }

        $commands = ProjectAgentOdooModuleDraft::volumeWriteCommands($service, $technical, $draft['files']);
        instant_remote_process($commands, $service->server);

        return [
            'applied' => true,
            'technical_name' => $technical,
            'service_uuid' => $service->uuid,
            'volume' => OdooAddons::extraAddonsVolume($service),
            'message' => 'Module files written to the extra-addons volume. Restart Odoo and update Apps to install.',
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function fn(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }
}
