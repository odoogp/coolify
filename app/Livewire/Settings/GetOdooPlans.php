<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPricingArea;
use App\Support\GetOdooBackupFrequency;
use App\Support\GetOdooCountries;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class GetOdooPlans extends Component
{
    public bool $showPlanEditor = false;

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

    public bool $includesMigration = false;

    public bool $includesBackups = false;

    public string $backupFrequency = GetOdooBackupFrequency::DAILY;

    public string $backupRetentionDays = '7';

    public bool $planIsRestOfWorld = true;

    /** @var list<string> Country display names on this plan */
    public array $planCountries = [];

    /** @var array<string, string> country name slug => optional promo price */
    public array $planCountryPromos = [];

    public string $pendingCountry = '';

    /** @var list<int> */
    public array $planRegionIds = [];

    /** @var array<string, string> region id => promo */
    public array $planRegionPromos = [];

    public string $pendingRegionId = '';

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');
        }
    }

    public function newPlan(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->resetErrorBag();
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
            'includesMigration',
            'includesBackups',
            'planCountries',
            'planCountryPromos',
            'pendingCountry',
            'planRegionIds',
            'planRegionPromos',
            'pendingRegionId',
        ]);
        $this->price = '0';
        $this->active = true;
        $this->includesBackups = false;
        $this->backupFrequency = GetOdooBackupFrequency::DAILY;
        $this->backupRetentionDays = '7';
        $this->planIsRestOfWorld = true;
        $this->showPlanEditor = true;
    }

    public function editPlan(int $planId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $plan = GetOdooPlan::query()->with('pricingAreas')->findOrFail($planId);
        $this->resetErrorBag();
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
        $this->includesMigration = (bool) $plan->includes_migration;
        $storedFrequency = (string) ($plan->backup_frequency ?: GetOdooBackupFrequency::NONE);
        $this->includesBackups = $storedFrequency !== GetOdooBackupFrequency::NONE;
        $this->backupFrequency = $this->includesBackups ? $storedFrequency : GetOdooBackupFrequency::DAILY;
        $this->backupRetentionDays = (string) max(1, (int) ($plan->backup_retention_days ?: 7));
        $this->planIsRestOfWorld = $plan->isRestOfWorld();
        $this->planCountries = [];
        $this->planCountryPromos = [];
        $this->pendingCountry = '';
        $this->planRegionIds = [];
        $this->planRegionPromos = [];
        $this->pendingRegionId = '';

        foreach ($plan->pricingAreas->where('kind', GetOdooPricingArea::KIND_COUNTRY) as $area) {
            $iso = strtoupper((string) ($area->iso_code ?: $area->code));
            $label = GetOdooCountries::name($iso);
            if ($label === null) {
                continue;
            }
            $this->planCountries[] = $label;
            if ($area->pivot?->promo_price !== null) {
                $this->planCountryPromos[Str::slug($label)] = number_format((float) $area->pivot->promo_price, 2, '.', '');
            }
        }
        $this->planCountries = array_values(array_unique($this->planCountries));

        foreach ($plan->pricingAreas->where('kind', GetOdooPricingArea::KIND_REGION) as $area) {
            $this->planRegionIds[] = (int) $area->id;
            if ($area->pivot?->promo_price !== null) {
                $this->planRegionPromos[(string) $area->id] = number_format((float) $area->pivot->promo_price, 2, '.', '');
            }
        }
        $this->planRegionIds = array_values(array_unique($this->planRegionIds));
        $this->showPlanEditor = true;
    }

    public function cancelPlanEditor(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->showPlanEditor = false;
        $this->planId = null;
        $this->resetErrorBag();
    }

    public function updatedPlanIsRestOfWorld(bool $value): void
    {
        if ($value) {
            $this->planCountries = [];
            $this->planCountryPromos = [];
            $this->pendingCountry = '';
            $this->planRegionIds = [];
            $this->planRegionPromos = [];
            $this->pendingRegionId = '';
        }
    }

    public function updatedIncludesBackups(bool $value): void
    {
        if ($value && ($this->backupFrequency === '' || $this->backupFrequency === GetOdooBackupFrequency::NONE)) {
            $this->backupFrequency = GetOdooBackupFrequency::DAILY;
        }
    }

    public function addPlanCountry(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->planIsRestOfWorld = false;
        $name = trim($this->pendingCountry);
        if (GetOdooCountries::isoFromName($name) === null) {
            $this->addError('pendingCountry', __('Pick a country from the list.'));

            return;
        }
        if (! in_array($name, $this->planCountries, true)) {
            $this->planCountries[] = $name;
        }
        $this->pendingCountry = '';
        $this->resetErrorBag('pendingCountry');
    }

    public function removePlanCountry(string $name): void
    {
        abort_unless(isInstanceOwner(), 403);

        $name = trim($name);
        $this->planCountries = array_values(array_filter(
            $this->planCountries,
            fn (string $row): bool => $row !== $name
        ));
        unset($this->planCountryPromos[Str::slug($name)]);
    }

    public function addPlanRegion(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->planIsRestOfWorld = false;
        $id = (int) $this->pendingRegionId;
        $region = GetOdooPricingArea::query()
            ->whereKey($id)
            ->where('kind', GetOdooPricingArea::KIND_REGION)
            ->where('is_active', true)
            ->first();
        if (! $region instanceof GetOdooPricingArea) {
            $this->addError('pendingRegionId', __('Pick a region from the list.'));

            return;
        }
        if (! in_array($id, $this->planRegionIds, true)) {
            $this->planRegionIds[] = $id;
        }
        $this->pendingRegionId = '';
        $this->resetErrorBag('pendingRegionId');
    }

    public function removePlanRegion(int $regionId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->planRegionIds = array_values(array_filter(
            $this->planRegionIds,
            fn (int $row): bool => $row !== $regionId
        ));
        unset($this->planRegionPromos[(string) $regionId]);
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
            'includesMigration' => ['boolean'],
            'includesBackups' => ['boolean'],
            'backupFrequency' => [
                Rule::requiredIf(fn (): bool => $this->includesBackups),
                Rule::in(GetOdooBackupFrequency::scheduleKeys()),
            ],
            'backupRetentionDays' => [
                Rule::requiredIf(fn (): bool => $this->includesBackups),
                'nullable',
                'integer',
                'min:1',
                'max:365',
            ],
            'planIsRestOfWorld' => ['boolean'],
            'planCountries' => ['array'],
            'planCountries.*' => ['string', 'max:120', Rule::in(array_values(GetOdooCountries::names()))],
            'planCountryPromos' => ['array'],
            'planCountryPromos.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'planRegionIds' => ['array'],
            'planRegionIds.*' => ['integer'],
            'planRegionPromos' => ['array'],
            'planRegionPromos.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        if (! $this->planIsRestOfWorld && $this->planCountries === [] && $this->planRegionIds === []) {
            $this->addError('planCountries', __('Add at least one country or region, or mark the plan as Rest of the world.'));

            return;
        }

        $validRegionIds = GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_REGION)
            ->whereIn('id', $this->planRegionIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->planRegionIds = array_values(array_intersect($this->planRegionIds, $validRegionIds));

        $price = round((float) $this->price, 2);
        $values = [
            'name' => trim($this->name),
            'summary' => trim($this->summary) ?: null,
            'description' => trim($this->description) ?: null,
            'price' => $price,
            'currency' => 'USD',
            'payment_gateway' => $price > 0 ? 'wompi' : null,
            'is_active' => $this->active,
            'is_rest_of_world' => $this->planIsRestOfWorld,
            'max_projects' => $this->maxProjects,
            'max_environments' => $this->maxEnvironments,
            'max_members' => $this->maxMembers,
            'max_production_branches' => $this->maxProductionBranches,
            'max_staging_branches' => $this->maxStagingBranches,
            'max_services' => $this->maxServices,
            'can_add_servers' => $this->canAddServers,
            'can_launch_on_instance_server' => $this->canLaunchOnInstanceServer,
            'includes_migration' => $this->includesMigration,
            'backup_frequency' => $this->includesBackups
                ? $this->backupFrequency
                : GetOdooBackupFrequency::NONE,
            'backup_retention_days' => $this->includesBackups
                ? (int) $this->backupRetentionDays
                : max(1, (int) ($this->backupRetentionDays ?: 7)),
        ];

        if ($this->planId) {
            $plan = GetOdooPlan::query()->findOrFail($this->planId);
            $plan->update($values);
        } else {
            $plan = GetOdooPlan::query()->create($values);
            $this->planId = $plan->id;
        }

        if ($this->planIsRestOfWorld) {
            $plan->pricingAreas()->sync([]);
        } else {
            $sync = [];
            foreach (array_values(array_unique($this->planCountries)) as $name) {
                $iso = GetOdooCountries::isoFromName($name);
                if ($iso === null) {
                    continue;
                }
                $area = GetOdooPricingArea::ensureCountry($iso);
                $promo = $this->planCountryPromos[Str::slug($name)] ?? null;
                $promo = $promo === null || trim((string) $promo) === '' ? null : round((float) $promo, 2);
                $sync[$area->id] = ['promo_price' => $promo];
            }
            foreach (array_values(array_unique($this->planRegionIds)) as $regionId) {
                $promo = $this->planRegionPromos[(string) $regionId] ?? null;
                $promo = $promo === null || trim((string) $promo) === '' ? null : round((float) $promo, 2);
                $sync[$regionId] = ['promo_price' => $promo];
            }
            $plan->pricingAreas()->sync($sync);
        }

        $this->price = number_format((float) $plan->price, 2, '.', '');
        $this->showPlanEditor = false;
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

        $plan->pricingAreas()->detach();
        $plan->delete();

        if ($this->planId === $planId) {
            $this->cancelPlanEditor();
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
        $planCountryRows = collect($this->planCountries)
            ->map(fn (string $name): array => [
                'name' => $name,
                'key' => Str::slug($name),
                'label' => $name,
                'promo' => $this->planCountryPromos[Str::slug($name)] ?? '',
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $regions = GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_REGION)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $planRegionRows = collect($this->planRegionIds)
            ->map(function (int $id) use ($regions): ?array {
                $region = $regions->firstWhere('id', $id);
                if (! $region instanceof GetOdooPricingArea) {
                    return null;
                }

                return [
                    'id' => $id,
                    'label' => $region->name,
                    'promo' => $this->planRegionPromos[(string) $id] ?? '',
                ];
            })
            ->filter()
            ->values()
            ->all();

        $usedCountries = array_flip($this->planCountries);
        $addCountryChoices = array_values(array_filter(
            GetOdooCountries::choices(),
            fn (array $row): bool => ! isset($usedCountries[$row['value']])
        ));

        $usedRegions = array_flip($this->planRegionIds);
        $addRegionChoices = $regions
            ->filter(fn (GetOdooPricingArea $region): bool => ! isset($usedRegions[$region->id]))
            ->map(fn (GetOdooPricingArea $region): array => [
                'value' => (string) $region->id,
                'label' => $region->name,
            ])
            ->values()
            ->all();

        return view('livewire.settings.getodoo-plans', [
            'plans' => GetOdooPlan::query()->with(['pricingAreas'])->withCount('signups')->orderBy('price')->orderBy('name')->get(),
            'planCountryRows' => $planCountryRows,
            'planRegionRows' => $planRegionRows,
            'addCountryChoices' => $addCountryChoices,
            'addRegionChoices' => $addRegionChoices,
            'backupFrequencyChoices' => GetOdooBackupFrequency::choices(includeNone: false),
        ]);
    }
}
