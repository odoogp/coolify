<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use App\Support\OdooMonitor;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class ExecuteContainerCommand extends Component
{
    use AuthorizesRequests;

    public $selected_container = 'default';

    public Collection $containers;

    public $parameters;

    public $resource;

    public string $type;

    public Collection $servers;

    public bool $isConnecting = false;

    public bool $containersLoaded = false;

    public ?string $shell = null;

    protected $rules = [
        'server' => 'required',
        'container' => 'required',
        'command' => 'required',
    ];

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $this->shell = request()->query('shell');
        $this->containers = collect();
        $this->servers = collect();
        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::ownedByCurrentTeam()->where('uuid', $this->parameters['application_uuid'])->firstOrFail();
            $this->authorize('view', $this->resource);
            if (! auth()->user()?->canOpenTerminal($this->resource)) {
                abort(403);
            }
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            foreach ($this->resource->additional_servers as $server) {
                if ($server->isFunctional()) {
                    $this->servers = $this->servers->push($server);
                }
            }
        } elseif (data_get($this->parameters, 'database_uuid')) {
            $this->type = 'database';
            $resource = getResourceByUuid($this->parameters['database_uuid'], data_get(auth()->user()->currentTeam(), 'id'));
            if (is_null($resource)) {
                abort(404);
            }
            $this->resource = $resource;
            $this->authorize('view', $this->resource);
            if (! auth()->user()?->canOpenTerminal($this->resource)) {
                abort(403);
            }
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
        } elseif (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::ownedByCurrentTeam()->where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            $this->authorize('view', $this->resource);
            if (! auth()->user()?->canOpenTerminal($this->resource)) {
                abort(403);
            }
            if (! $this->resource->isRunning()) {
                $this->containersLoaded = true;
            }
            if ($this->resource->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->server);
            }
            if ($this->resource->supportsOdooJupyter() && ($this->shell === null || $this->shell === '')) {
                $this->shell = 'odoo';
            }
        } elseif (data_get($this->parameters, 'server_uuid')) {
            $this->type = 'server';
            $this->resource = Server::ownedByCurrentTeam()->where('uuid', $this->parameters['server_uuid'])->firstOrFail();
            $this->authorize('view', $this->resource);
            if (! auth()->user()?->canOpenTerminal($this->resource)) {
                abort(403);
            }
            $this->servers = $this->servers->push($this->resource);
            $this->containersLoaded = true;
        }
        $this->servers = $this->servers->sortByDesc(fn ($server) => $server->isTerminalEnabled());
    }

    public function loadContainers(): void
    {
        if ($this->containersLoaded) {
            return;
        }

        foreach ($this->servers as $server) {
            if (data_get($this->parameters, 'application_uuid')) {
                if ($server->isSwarm()) {
                    $containers = collect([
                        [
                            'Names' => $this->resource->uuid.'_'.$this->resource->uuid,
                        ],
                    ]);
                } else {
                    $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
                }
                foreach ($containers as $container) {
                    // if container state is running
                    if (data_get($container, 'State') === 'running' && $server->isTerminalEnabled()) {
                        $payload = [
                            'server' => $server,
                            'container' => $container,
                        ];
                        $this->containers = $this->containers->push($payload);
                    }
                }
            } elseif (data_get($this->parameters, 'database_uuid')) {
                if ($this->resource->isRunning() && $server->isTerminalEnabled()) {
                    $this->containers = $this->containers->push([
                        'server' => $server,
                        'container' => [
                            'Names' => $this->resource->uuid,
                        ],
                    ]);
                }
            } elseif (data_get($this->parameters, 'service_uuid')) {
                $clientContainers = $this->resource instanceof Service
                    && $this->resource->supportsOdooJupyter()
                    && ! isInstanceAdmin();
                $this->resource->applications()->get()->each(function ($application) use ($clientContainers) {
                    if (OdooMonitor::hidesTerminal((string) $application->name) || OdooJupyter::hidesTerminal((string) $application->name)) {
                        return;
                    }
                    if ($clientContainers && ! OdooGit::clientSeesLog((string) $application->name)) {
                        return;
                    }
                    if ($application->isRunning() && $this->resource->server->isTerminalEnabled()) {
                        $this->containers->push([
                            'server' => $this->resource->server,
                            'container' => [
                                'Names' => data_get($application, 'name').'-'.data_get($this->resource, 'uuid'),
                                'Label' => $this->containerLabel((string) data_get($application, 'name')),
                            ],
                        ]);
                    }
                });
                $this->resource->databases()->get()->each(function ($database) use ($clientContainers) {
                    if ($clientContainers && ! OdooGit::clientSeesLog((string) $database->name)) {
                        return;
                    }
                    if ($database->isRunning()) {
                        $this->containers->push([
                            'server' => $this->resource->server,
                            'container' => [
                                'Names' => data_get($database, 'name').'-'.data_get($this->resource, 'uuid'),
                                'Label' => $this->containerLabel((string) data_get($database, 'name')),
                            ],
                        ]);
                    }
                });
            }
        }

        // Sort containers alphabetically by name
        $this->containers = $this->containers->sortBy(function ($container) {
            return data_get($container, 'container.Names');
        });

        if ($this->containers->count() === 1 && $this->shell !== 'odoo') {
            $this->selected_container = data_get($this->containers->first(), 'container.Names');
            $this->connectToContainer();
        }
        if ($this->shell === 'odoo') {
            $odoo = $this->containers->first(function (mixed $row): bool {
                return OdooGit::terminalShell((string) data_get($row, 'container.Names')) !== null;
            });
            if ($odoo !== null) {
                $this->selected_container = (string) data_get($odoo, 'container.Names');
                $this->connectToContainer();
            }
        }

        $this->containersLoaded = true;
    }

    public function updatedSelectedContainer()
    {
        if ($this->selected_container !== 'default') {
            $this->connectToContainer();
        }
    }

    #[On('connectToServer')]
    public function connectToServer()
    {
        try {
            $this->authorize('canAccessTerminal');
            $server = $this->servers->first();
            $this->authorize('view', $server);
            if ($server->isForceDisabled()) {
                throw new \RuntimeException('Server is disabled.');
            }
            $this->dispatch(
                'send-terminal-command',
                false,
                data_get($server, 'name'),
                data_get($server, 'uuid')
            );

            // Dispatch a frontend event to ensure terminal gets focus after connection
            $this->dispatch('terminal-should-focus');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->isConnecting = false;
        }
    }

    #[On('connectToContainer')]
    public function connectToContainer()
    {
        if ($this->selected_container === 'default') {
            $this->dispatch('terminal-start-failed', message: __('Please select a container.'));

            return;
        }
        try {
            $this->authorize('canAccessTerminal');
            // Validate container name format
            if (! ValidationPatterns::isValidContainerName($this->selected_container)) {
                throw new \InvalidArgumentException('Invalid container name format');
            }

            // Verify container exists in our allowed list
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                throw new \RuntimeException('Container not found.');
            }

            // Verify server ownership and status
            $server = data_get($container, 'server');
            if (! $server || ! $server instanceof Server) {
                throw new \RuntimeException('Invalid server configuration.');
            }

            $this->authorize('view', $server);

            if ($server->isForceDisabled()) {
                throw new \RuntimeException('Server is disabled.');
            }

            // Additional ownership verification based on resource type
            $resourceServer = match ($this->type) {
                'application' => $this->resource->destination->server,
                'database' => $this->resource->destination->server,
                'service' => $this->resource->server,
                default => throw new \RuntimeException('Invalid resource type.')
            };

            if ($server->id !== $resourceServer->id && ! $this->resource->additional_servers->contains('id', $server->id)) {
                throw new \RuntimeException('Server ownership verification failed.');
            }

            $this->dispatch(
                'send-terminal-command',
                true,
                data_get($container, 'container.Names'),
                data_get($container, 'server.uuid')
            );

            // Dispatch a frontend event to ensure terminal gets focus after connection
            $this->dispatch('terminal-should-focus');
        } catch (\Throwable $e) {
            $this->dispatch('terminal-start-failed', message: $e->getMessage());

            return handleError($e, $this);
        } finally {
            $this->isConnecting = false;
        }
    }

    private function containerLabel(string $name): string
    {
        $lower = strtolower($name);

        return match (true) {
            str_contains($lower, 'jupyter') => 'Jupyter',
            str_contains($lower, 'postgres') => 'PostgreSQL',
            str_contains($lower, 'odoo') => 'Odoo',
            default => $name,
        };
    }

    public function render()
    {
        return view('livewire.project.shared.execute-container-command');
    }
}
