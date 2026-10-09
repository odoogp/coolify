<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooPricingArea;
use App\Support\GetOdooBackupFrequency;
use App\Support\GetOdooCountries;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
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

    public ?int $regionId = null;

    public string $regionName = '';

    /** @var list<string> */
    public array $regionCountries = [];

    public string $pendingRegionCountry = '';

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
    }

    public function editPlan(int $planId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $plan = GetOdooPlan::query()->with('pricingAreas')->findOrFail($planId);
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

    public function newRegion(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->regionId = null;
        $this->regionName = '';
        $this->regionCountries = [];
        $this->pendingRegionCountry = '';
    }

    public function editRegion(int $regionId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $region = GetOdooPricingArea::query()
            ->whereKey($regionId)
            ->where('kind', GetOdooPricingArea::KIND_REGION)
            ->with('children')
            ->firstOrFail();

        $this->regionId = $region->id;
        $this->regionName = $region->name;
        $this->regionCountries = $region->children
            ->where('kind', GetOdooPricingArea::KIND_COUNTRY)
            ->map(function (GetOdooPricingArea $child): ?string {
                $iso = strtoupper((string) ($child->iso_code ?: $child->code));

                return GetOdooCountries::name($iso);
            })
            ->filter()
            ->values()
            ->all();
        $this->pendingRegionCountry = '';
    }

    public function addRegionCountry(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $name = trim($this->pendingRegionCountry);
        if (GetOdooCountries::isoFromName($name) === null) {
            $this->addError('pendingRegionCountry', __('Pick a country from the list.'));

            return;
        }
        if (! in_array($name, $this->regionCountries, true)) {
            $this->regionCountries[] = $name;
        }
        $this->pendingRegionCountry = '';
        $this->resetErrorBag('pendingRegionCountry');
    }

    public function removeRegionCountry(string $name): void
    {
        abort_unless(isInstanceOwner(), 403);

        $name = trim($name);
        $this->regionCountries = array_values(array_filter(
            $this->regionCountries,
            fn (string $row): bool => $row !== $name
        ));
    }

    public function saveRegion(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->validate([
            'regionName' => ['required', 'string', 'max:80'],
            'regionCountries' => ['array'],
            'regionCountries.*' => ['string', 'max:120', Rule::in(array_values(GetOdooCountries::names()))],
        ]);

        if ($this->regionCountries === []) {
            $this->addError('regionCountries', __('Add at least one country to this region.'));

            return;
        }

        $name = trim($this->regionName);
        $code = GetOdooPricingArea::normalizeCode($name);

        if ($this->regionId) {
            $region = GetOdooPricingArea::query()
                ->whereKey($this->regionId)
                ->where('kind', GetOdooPricingArea::KIND_REGION)
                ->firstOrFail();
            $region->update([
                'name' => $name,
                'code' => $code.'-'.$region->id,
                'is_active' => true,
            ]);
        } else {
            $region = GetOdooPricingArea::query()->create([
                'code' => $code.'-'.substr(uniqid(), -6),
                'name' => $name,
                'kind' => GetOdooPricingArea::KIND_REGION,
                'parent_id' => null,
                'iso_code' => null,
                'extra_fixed' => 0,
                'extra_percent' => 0,
                'is_active' => true,
                'allow_multiple_projects' => true,
                'allow_all_services' => true,
                'allowed_services' => null,
                'sort_order' => 0,
            ]);
            $region->update(['code' => $code.'-'.$region->id]);
            $this->regionId = $region->id;
        }

        $keepIds = [];
        foreach (array_values(array_unique($this->regionCountries)) as $name) {
            $iso = GetOdooCountries::isoFromName($name);
            if ($iso === null) {
                continue;
            }
            $country = $this->ensureCountryArea($iso);
            $country->forceFill(['parent_id' => $region->id, 'is_active' => true])->save();
            $keepIds[] = $country->id;
        }

        GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_COUNTRY)
            ->where('parent_id', $region->id)
            ->whereNotIn('id', $keepIds)
            ->update(['parent_id' => null]);

        $this->dispatch('success', __('The region was saved.'));
    }

    public function deleteRegion(int $regionId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $region = GetOdooPricingArea::query()
            ->whereKey($regionId)
            ->where('kind', GetOdooPricingArea::KIND_REGION)
            ->firstOrFail();

        if ($region->plans()->exists()) {
            $this->dispatch('error', __('This region is used by a plan and cannot be deleted.'));

            return;
        }

        GetOdooPricingArea::query()
            ->where('parent_id', $region->id)
            ->update(['parent_id' => null]);

        $region->delete();

        if ($this->regionId === $regionId) {
            $this->newRegion();
        }

        $this->planRegionIds = array_values(array_filter(
            $this->planRegionIds,
            fn (int $id): bool => $id !== $regionId
        ));
        unset($this->planRegionPromos[(string) $regionId]);

        $this->dispatch('success', __('The region was deleted.'));
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
                $area = $this->ensureCountryArea($iso);
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
            $this->newPlan();
        }

        $this->dispatch('success', __('The plan was deleted.'));
    }

    private function ensureCountryArea(string $iso): GetOdooPricingArea
    {
        $iso = strtoupper($iso);
        $name = GetOdooCountries::name($iso) ?? $iso;
        $code = GetOdooPricingArea::normalizeCode($iso);

        $area = GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_COUNTRY)
            ->where(function ($query) use ($code, $iso) {
                $query->where('iso_code', $iso)->orWhere('code', $code);
            })
            ->first();

        if ($area instanceof GetOdooPricingArea) {
            $area->update([
                'name' => $name,
                'iso_code' => $iso,
                'code' => $code,
                'is_active' => true,
            ]);

            return $area->fresh();
        }

        return GetOdooPricingArea::query()->create([
            'code' => $code,
            'name' => $name,
            'kind' => GetOdooPricingArea::KIND_COUNTRY,
            'parent_id' => null,
            'iso_code' => $iso,
            'extra_fixed' => 0,
            'extra_percent' => 0,
            'is_active' => true,
            'allow_multiple_projects' => true,
            'allow_all_services' => true,
            'allowed_services' => null,
            'sort_order' => 0,
        ]);
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
            ->with(['children' => fn ($q) => $q->where('kind', GetOdooPricingArea::KIND_COUNTRY)->orderBy('name')])
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

        $usedRegionCountries = array_flip($this->regionCountries);
        $addRegionCountryChoices = array_values(array_filter(
            GetOdooCountries::choices(),
            fn (array $row): bool => ! isset($usedRegionCountries[$row['value']])
        ));

        $regionCountryRows = collect($this->regionCountries)
            ->map(fn (string $name): array => [
                'name' => $name,
                'label' => $name,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return view('livewire.settings.getodoo-plans', [
            'plans' => GetOdooPlan::query()->with(['pricingAreas'])->withCount('signups')->orderBy('price')->orderBy('name')->get(),
            'signups' => GetOdooPlanSignup::query()->with(['plan', 'pricingArea'])->latest('id')->limit(20)->get(),
            'planCountryRows' => $planCountryRows,
            'planRegionRows' => $planRegionRows,
            'addCountryChoices' => $addCountryChoices,
            'addRegionChoices' => $addRegionChoices,
            'regions' => $regions,
            'regionCountryRows' => $regionCountryRows,
            'addRegionCountryChoices' => $addRegionCountryChoices,
            'backupFrequencyChoices' => GetOdooBackupFrequency::choices(includeNone: false),
        ]);
    }
}
