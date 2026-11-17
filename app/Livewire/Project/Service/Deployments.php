<?php

namespace App\Livewire\Project\Service;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceChangeDraft;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use App\Support\OdooRuntime;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class Deployments extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Environment $environment;

    public Service $service;

    public ?Application $addonsApplication = null;

    public bool $isOdooService = false;

    #[Url(as: 'hub')]
    public string $tab = 'overview';

    public string $draftSubdomain = '';

    public int $draftWorkers = 0;

    public string $currentSubdomain = '';

    public int $currentWorkers = 0;

    public string $odooBaseDomain = '';

    public bool $showDiff = false;

    public function mount(string $project_uuid, string $environment_uuid, string $service_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->service = $this->environment->services()->where('uuid', $service_uuid)->firstOrFail();
        $this->authorize('view', $this->service);

        $this->isOdooService = $this->service->supportsOdooJupyter()
            || OdooJupyter::isOdooCompose((string) $this->service->docker_compose_raw);

        if ($this->isOdooService) {
            $this->addonsApplication = OdooGit::ensureAddonsApplication($this->environment, $this->service);
        }

        if (! $this->addonsApplication instanceof Application) {
            $this->addonsApplication = $this->environment->applications()
                ->where('is_odoo_addons', true)
                ->first();
        }

        if (! in_array($this->tab, ['overview', 'deploys', 'runtime'], true)) {
            $this->tab = 'overview';
        }

        if (! $this->isOdooService) {
            $this->tab = 'deploys';
        }

        $this->loadRuntimeState();
    }

    public function setTab(string $tab): void
    {
        if (! $this->isOdooService) {
            $this->tab = 'deploys';

            return;
        }

        if (! in_array($tab, ['overview', 'deploys', 'runtime'], true)) {
            return;
        }

        $this->tab = $tab;
        $this->showDiff = false;
    }

    public function updatedDraftSubdomain(): void
    {
        $this->persistDraft();
    }

    public function updatedDraftWorkers(): void
    {
        $this->draftWorkers = max(0, min(32, (int) $this->draftWorkers));
        $this->persistDraft();
    }

    public function toggleDiff(): void
    {
        $this->showDiff = ! $this->showDiff;
    }

    public function applyDraft(): void
    {
        $this->authorize('update', $this->service);

        try {
            $draft = $this->currentDraft();
            if (! $draft instanceof ServiceChangeDraft) {
                $this->dispatch('error', __('There are no changes to apply.'));

                return;
            }

            $payload = $draft->runtimePayload();
            $result = OdooRuntime::save($this->service, $payload['subdomain'], $payload['workers']);
            if (! ($result['ok'] ?? false)) {
                $this->dispatch('error', $result['error'] ?? __('Could not apply the changes.'));

                return;
            }

            $draft->delete();
            $this->loadRuntimeState();
            $this->showDiff = false;
            $this->dispatch('success', ($result['address_changed'] ?? false)
                ? __('Changes applied. Odoo is restarting so the proxy takes the address.')
                : __('Changes applied. The next start uses the new workers and address.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function discardDraft(): void
    {
        $this->authorize('update', $this->service);

        try {
            $this->currentDraft()?->delete();
            $this->loadRuntimeState();
            $this->showDiff = false;
            $this->dispatch('success', __('Draft discarded.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function getDraftDiffProperty(): array
    {
        $changes = [];
        if ($this->draftSubdomain !== $this->currentSubdomain) {
            $changes[] = [
                'field' => __('Subdomain'),
                'from' => $this->currentSubdomain === '' ? __('(empty)') : $this->currentSubdomain,
                'to' => $this->draftSubdomain === '' ? __('(empty)') : $this->draftSubdomain,
            ];
        }
        if ((int) $this->draftWorkers !== (int) $this->currentWorkers) {
            $changes[] = [
                'field' => __('Workers'),
                'from' => (string) $this->currentWorkers,
                'to' => (string) $this->draftWorkers,
            ];
        }

        return $changes;
    }

    public function getHasDraftProperty(): bool
    {
        return count($this->draftDiff) > 0;
    }

    public function render(): View
    {
        return view('livewire.project.service.deployments', [
            'openUrl' => $this->isOdooService ? OdooGit::enterUrl($this->service) : '',
            'canOpen' => $this->isOdooService && OdooGit::canOpen($this->service),
            'draftDiff' => $this->draftDiff,
            'hasDraft' => $this->hasDraft,
            'canUpdate' => auth()->user()?->can('update', $this->service) ?? false,
        ]);
    }

    private function loadRuntimeState(): void
    {
        if (! $this->isOdooService) {
            return;
        }

        $this->service->loadMissing(['environment.project.odooProfile', 'environment.odooBranch']);
        $environment = $this->service->environment;
        $project = $environment?->project;
        $this->currentSubdomain = $project !== null && $environment !== null
            ? OdooGit::environmentSubdomain($project, $environment)
            : '';
        $branch = $environment?->odooBranch;
        $profile = $project?->odooProfile;
        $this->currentWorkers = (int) ($branch?->workers ?? $profile?->workers ?? 0);
        $this->odooBaseDomain = OdooGit::baseDomain();

        $draft = $this->currentDraft();
        if ($draft instanceof ServiceChangeDraft) {
            $payload = $draft->runtimePayload();
            $this->draftSubdomain = $payload['subdomain'];
            $this->draftWorkers = $payload['workers'];
        } else {
            $this->draftSubdomain = $this->currentSubdomain;
            $this->draftWorkers = $this->currentWorkers;
        }
    }

    private function persistDraft(): void
    {
        if (! $this->isOdooService || ! auth()->user()?->can('update', $this->service)) {
            return;
        }

        $payload = [
            'subdomain' => trim($this->draftSubdomain),
            'workers' => max(0, min(32, (int) $this->draftWorkers)),
        ];

        $unchanged = $payload['subdomain'] === $this->currentSubdomain
            && $payload['workers'] === $this->currentWorkers;

        $draft = $this->currentDraft();
        if ($unchanged) {
            $draft?->delete();

            return;
        }

        ServiceChangeDraft::query()->updateOrCreate(
            [
                'service_id' => $this->service->id,
                'user_id' => Auth::id(),
            ],
            ['payload' => $payload],
        );
    }

    private function currentDraft(): ?ServiceChangeDraft
    {
        $userId = Auth::id();
        if (! $userId) {
            return null;
        }

        return ServiceChangeDraft::query()
            ->where('service_id', $this->service->id)
            ->where('user_id', $userId)
            ->first();
    }
}
