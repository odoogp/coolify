<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class GetOdooPlans extends Component
{
    public ?int $planId = null;

    public string $name = '';

    public string $summary = '';

    public string $description = '';

    public string $price = '0';

    public bool $active = true;

    public mixed $maxProjects = null;

    public mixed $maxEnvironments = null;

    public mixed $maxMembers = null;

    public mixed $maxProductionBranches = null;

    public mixed $maxStagingBranches = null;

    public mixed $maxServices = null;

    public bool $canAddServers = false;

    public bool $canLaunchOnInstanceServer = false;

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');
        }
    }

    public function newPlan(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->reset([
            'planId',
            'name',
            'summary',
            'description',
            'maxProjects',
            'maxEnvironments',
            'maxMembers',
            'maxProductionBranches',
            'maxStagingBranches',
            'maxServices',
            'canAddServers',
            'canLaunchOnInstanceServer',
        ]);
        $this->price = '0';
        $this->active = true;
    }

    public function editPlan(int $planId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $plan = GetOdooPlan::query()->findOrFail($planId);
        $this->planId = $plan->id;
        $this->name = $plan->name;
        $this->summary = (string) $plan->summary;
        $this->description = (string) $plan->description;
        $this->price = number_format((float) $plan->price, 2, '.', '');
        $this->active = $plan->is_active;
        $this->maxProjects = $plan->max_projects;
        $this->maxEnvironments = $plan->max_environments;
        $this->maxMembers = $plan->max_members;
        $this->maxProductionBranches = $plan->max_production_branches;
        $this->maxStagingBranches = $plan->max_staging_branches;
        $this->maxServices = $plan->max_services;
        $this->canAddServers = $plan->can_add_servers;
        $this->canLaunchOnInstanceServer = $plan->can_launch_on_instance_server;
    }

    public function savePlan(): void
    {
        abort_unless(isInstanceOwner(), 403);

        foreach ([
            'maxProjects',
            'maxEnvironments',
            'maxMembers',
            'maxProductionBranches',
            'maxStagingBranches',
            'maxServices',
        ] as $field) {
            $this->{$field} = $this->blankToNull($this->{$field});
        }

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'summary' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'active' => ['boolean'],
            'maxProjects' => ['nullable', 'integer', 'min:0'],
            'maxEnvironments' => ['nullable', 'integer', 'min:0'],
            'maxMembers' => ['nullable', 'integer', 'min:0'],
            'maxProductionBranches' => ['nullable', 'integer', 'min:0'],
            'maxStagingBranches' => ['nullable', 'integer', 'min:0'],
            'maxServices' => ['nullable', 'integer', 'min:0'],
            'canAddServers' => ['boolean'],
            'canLaunchOnInstanceServer' => ['boolean'],
        ]);

        $price = round((float) $this->price, 2);
        $values = [
            'name' => trim($this->name),
            'summary' => trim($this->summary) ?: null,
            'description' => trim($this->description) ?: null,
            'price' => $price,
            'currency' => 'USD',
            'payment_gateway' => $price > 0 ? 'wompi' : null,
            'is_active' => $this->active,
            'max_projects' => $this->maxProjects,
            'max_environments' => $this->maxEnvironments,
            'max_members' => $this->maxMembers,
            'max_production_branches' => $this->maxProductionBranches,
            'max_staging_branches' => $this->maxStagingBranches,
            'max_services' => $this->maxServices,
            'can_add_servers' => $this->canAddServers,
            'can_launch_on_instance_server' => $this->canLaunchOnInstanceServer,
        ];

        if ($this->planId) {
            $plan = GetOdooPlan::query()->findOrFail($this->planId);
            $plan->update($values);
        } else {
            $plan = GetOdooPlan::query()->create($values);
            $this->planId = $plan->id;
        }

        $this->price = number_format((float) $plan->price, 2, '.', '');
        $this->dispatch('success', __('The plan was saved.'));
    }

    public function deletePlan(int $planId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $plan = GetOdooPlan::query()->withCount('signups')->findOrFail($planId);

        if ($plan->signups_count > 0) {
            $this->dispatch('error', __('This plan has signups and cannot be deleted.'));

            return;
        }

        $plan->delete();

        if ($this->planId === $planId) {
            $this->newPlan();
        }

        $this->dispatch('success', __('The plan was deleted.'));
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function render(): View
    {
        return view('livewire.settings.getodoo-plans', [
            'plans' => GetOdooPlan::query()->withCount('signups')->orderBy('price')->orderBy('name')->get(),
            'signups' => GetOdooPlanSignup::query()->with('plan')->latest('id')->limit(20)->get(),
        ]);
    }
}
