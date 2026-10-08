<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooPricingArea;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
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

    public ?int $areaId = null;

    public string $areaKind = GetOdooPricingArea::KIND_COUNTRY;

    public string $areaName = '';

    public string $areaCode = '';

    public string $areaIso = '';

    public ?string $areaParentId = null;

    public string $areaExtraFixed = '0';

    public string $areaExtraPercent = '0';

    public bool $areaActive = true;

    public string $areaSort = '0';

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

    public function updatedAreaKind(): void
    {
        if ($this->areaKind === GetOdooPricingArea::KIND_REGION) {
            $this->areaParentId = null;
            $this->areaIso = '';
        }
    }

    public function newArea(?string $kind = null): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->reset([
            'areaId',
            'areaName',
            'areaCode',
            'areaIso',
            'areaParentId',
        ]);
        $this->areaKind = $kind === GetOdooPricingArea::KIND_REGION
            ? GetOdooPricingArea::KIND_REGION
            : GetOdooPricingArea::KIND_COUNTRY;
        $this->areaExtraFixed = '0';
        $this->areaExtraPercent = '0';
        $this->areaActive = true;
        $this->areaSort = '0';
    }

    public function editArea(int $areaId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $area = GetOdooPricingArea::query()->findOrFail($areaId);
        $this->areaId = $area->id;
        $this->areaKind = $area->kind;
        $this->areaName = $area->name;
        $this->areaCode = $area->code;
        $this->areaIso = (string) ($area->iso_code ?? '');
        $this->areaParentId = $area->parent_id !== null ? (string) $area->parent_id : null;
        $this->areaExtraFixed = number_format((float) $area->extra_fixed, 2, '.', '');
        $this->areaExtraPercent = number_format((float) $area->extra_percent, 2, '.', '');
        $this->areaActive = $area->is_active;
        $this->areaSort = (string) $area->sort_order;
    }

    public function saveArea(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->areaKind = $this->areaKind === GetOdooPricingArea::KIND_REGION
            ? GetOdooPricingArea::KIND_REGION
            : GetOdooPricingArea::KIND_COUNTRY;

        if ($this->areaKind === GetOdooPricingArea::KIND_REGION) {
            $this->areaParentId = null;
            $this->areaIso = '';
        }

        if ($this->areaParentId === '' || $this->areaParentId === null) {
            $this->areaParentId = null;
        }

        $code = GetOdooPricingArea::normalizeCode($this->areaCode !== '' ? $this->areaCode : $this->areaName);

        $this->validate([
            'areaKind' => ['required', Rule::in([GetOdooPricingArea::KIND_REGION, GetOdooPricingArea::KIND_COUNTRY])],
            'areaName' => ['required', 'string', 'max:120'],
            'areaCode' => ['nullable', 'string', 'max:40'],
            'areaIso' => ['nullable', 'string', 'size:2'],
            'areaParentId' => [
                'nullable',
                'integer',
                Rule::exists('get_odoo_pricing_areas', 'id')->where('kind', GetOdooPricingArea::KIND_REGION),
            ],
            'areaExtraFixed' => ['required', 'numeric', 'min:0', 'max:100000'],
            'areaExtraPercent' => ['required', 'numeric', 'min:0', 'max:500'],
            'areaActive' => ['boolean'],
            'areaSort' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        if ($code === '') {
            $this->addError('areaCode', __('Use a simple lowercase code (letters, numbers, dashes).'));

            return;
        }

        $duplicate = GetOdooPricingArea::query()
            ->where('code', $code)
            ->when($this->areaId, fn ($query) => $query->whereKeyNot($this->areaId))
            ->exists();
        if ($duplicate) {
            $this->addError('areaCode', __('That code already exists.'));

            return;
        }

        $values = [
            'code' => $code,
            'name' => trim($this->areaName),
            'kind' => $this->areaKind,
            'parent_id' => $this->areaKind === GetOdooPricingArea::KIND_COUNTRY && filled($this->areaParentId)
                ? (int) $this->areaParentId
                : null,
            'iso_code' => $this->areaKind === GetOdooPricingArea::KIND_COUNTRY && filled($this->areaIso)
                ? strtoupper(trim($this->areaIso))
                : null,
            'extra_fixed' => round((float) $this->areaExtraFixed, 2),
            'extra_percent' => round((float) $this->areaExtraPercent, 2),
            'is_active' => $this->areaActive,
            'sort_order' => (int) $this->areaSort,
        ];

        if ($this->areaId) {
            $area = GetOdooPricingArea::query()->findOrFail($this->areaId);
            if ($area->isRegion() && $values['kind'] === GetOdooPricingArea::KIND_COUNTRY) {
                $this->addError('areaKind', __('A region with countries cannot become a country.'));

                return;
            }
            $area->update($values);
        } else {
            $area = GetOdooPricingArea::query()->create($values);
            $this->areaId = $area->id;
        }

        $this->areaCode = $area->code;
        $this->areaExtraFixed = number_format((float) $area->extra_fixed, 2, '.', '');
        $this->areaExtraPercent = number_format((float) $area->extra_percent, 2, '.', '');
        $this->dispatch('success', __('The pricing area was saved.'));
    }

    public function deleteArea(int $areaId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $area = GetOdooPricingArea::query()->withCount('children')->findOrFail($areaId);
        if ($area->children_count > 0) {
            $this->dispatch('error', __('Remove or reassign the countries in this region first.'));

            return;
        }

        $area->delete();

        if ($this->areaId === $areaId) {
            $this->newArea();
        }

        $this->dispatch('success', __('The pricing area was deleted.'));
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
        $regions = GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_REGION)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $countries = GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_COUNTRY)
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('livewire.settings.getodoo-plans', [
            'plans' => GetOdooPlan::query()->withCount('signups')->orderBy('price')->orderBy('name')->get(),
            'signups' => GetOdooPlanSignup::query()->with(['plan', 'pricingArea'])->latest('id')->limit(20)->get(),
            'regions' => $regions,
            'countries' => $countries,
            'regionChoices' => $regions
                ->map(fn (GetOdooPricingArea $region): array => [
                    'value' => (string) $region->id,
                    'label' => $region->name,
                ])
                ->values()
                ->all(),
            'areaKindChoices' => [
                ['value' => GetOdooPricingArea::KIND_REGION, 'label' => __('Region')],
                ['value' => GetOdooPricingArea::KIND_COUNTRY, 'label' => __('Country')],
            ],
        ]);
    }
}
