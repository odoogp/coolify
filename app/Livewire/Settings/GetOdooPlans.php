<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooPricingArea;
use App\Services\GetOdoo\GetOdooAreaEntitlements;
use App\Support\GetOdooBackupFrequency;
use App\Support\GetOdooCountries;
use App\Support\ServiceTemplateCatalog;
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

    public bool $includesMigration = false;

    public string $backupFrequency = GetOdooBackupFrequency::DAILY;

    public string $backupRetentionDays = '7';

    public bool $planAvailableWorldwide = true;

    /** @var list<string> */
    public array $planCountryIds = [];

    /** @var array<string, string> country id => optional promo price */
    public array $planCountryPromos = [];

    public ?int $areaId = null;

    public string $areaIso = '';

    public string $areaExtraFixed = '0';

    public string $areaExtraPercent = '0';

    public bool $areaActive = true;

    public bool $areaAllowMultipleProjects = true;

    public bool $areaAllowAllServices = true;

    /** @var list<string> */
    public array $areaAllowedServiceKeys = [];

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
            'includesMigration',
            'planCountryIds',
            'planCountryPromos',
        ]);
        $this->price = '0';
        $this->active = true;
        $this->backupFrequency = GetOdooBackupFrequency::DAILY;
        $this->backupRetentionDays = '7';
        $this->planAvailableWorldwide = true;
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
        $this->backupFrequency = (string) ($plan->backup_frequency ?: GetOdooBackupFrequency::DAILY);
        $this->backupRetentionDays = (string) max(1, (int) ($plan->backup_retention_days ?: 7));
        $this->planAvailableWorldwide = $plan->isAvailableWorldwide();
        $this->planCountryIds = $plan->pricingAreas
            ->where('kind', GetOdooPricingArea::KIND_COUNTRY)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
        $this->planCountryPromos = [];
        foreach ($plan->pricingAreas as $area) {
            if ($area->pivot?->promo_price !== null) {
                $this->planCountryPromos[(string) $area->id] = number_format((float) $area->pivot->promo_price, 2, '.', '');
            }
        }
    }

    public function updatedPlanAvailableWorldwide(bool $value): void
    {
        if ($value) {
            $this->planCountryIds = [];
            $this->planCountryPromos = [];
        }
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
            'backupFrequency' => ['required', Rule::in(GetOdooBackupFrequency::keys())],
            'backupRetentionDays' => ['required', 'integer', 'min:1', 'max:365'],
            'planAvailableWorldwide' => ['boolean'],
            'planCountryIds' => ['array'],
            'planCountryIds.*' => [
                'integer',
                Rule::exists('get_odoo_pricing_areas', 'id')->where('kind', GetOdooPricingArea::KIND_COUNTRY),
            ],
            'planCountryPromos' => ['array'],
            'planCountryPromos.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        if (! $this->planAvailableWorldwide && $this->planCountryIds === []) {
            $this->addError('planCountryIds', __('Pick at least one country, or keep the plan available worldwide.'));

            return;
        }

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
            'includes_migration' => $this->includesMigration,
            'backup_frequency' => $this->backupFrequency,
            'backup_retention_days' => (int) $this->backupRetentionDays,
        ];

        if ($this->planId) {
            $plan = GetOdooPlan::query()->findOrFail($this->planId);
            $plan->update($values);
        } else {
            $plan = GetOdooPlan::query()->create($values);
            $this->planId = $plan->id;
        }

        if ($this->planAvailableWorldwide) {
            $plan->pricingAreas()->sync([]);
        } else {
            $sync = [];
            foreach (collect($this->planCountryIds)->map(fn ($id): int => (int) $id)->unique() as $countryId) {
                $promo = $this->planCountryPromos[(string) $countryId] ?? null;
                $promo = $promo === null || trim((string) $promo) === '' ? null : round((float) $promo, 2);
                $sync[$countryId] = ['promo_price' => $promo];
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

    public function newArea(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->reset([
            'areaId',
            'areaIso',
            'areaAllowedServiceKeys',
        ]);
        $this->areaExtraFixed = '0';
        $this->areaExtraPercent = '0';
        $this->areaActive = true;
        $this->areaAllowMultipleProjects = true;
        $this->areaAllowAllServices = true;
        $this->areaSort = '0';
    }

    public function editArea(int $areaId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $area = GetOdooPricingArea::query()->findOrFail($areaId);
        abort_unless($area->isCountry(), 404);

        $this->areaId = $area->id;
        $this->areaIso = strtoupper((string) ($area->iso_code ?? ''));
        $this->areaExtraFixed = number_format((float) $area->extra_fixed, 2, '.', '');
        $this->areaExtraPercent = number_format((float) $area->extra_percent, 2, '.', '');
        $this->areaActive = $area->is_active;
        $this->areaAllowMultipleProjects = $area->allow_multiple_projects;
        $this->areaAllowAllServices = $area->allow_all_services;
        $this->areaAllowedServiceKeys = GetOdooAreaEntitlements::normalizeServiceKeys($area->allowed_services);
        $this->areaSort = (string) $area->sort_order;
    }

    public function saveArea(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->validate([
            'areaIso' => ['required', 'string', 'size:2', Rule::in(array_keys(GetOdooCountries::names()))],
            'areaExtraFixed' => ['required', 'numeric', 'min:0', 'max:100000'],
            'areaExtraPercent' => ['required', 'numeric', 'min:0', 'max:500'],
            'areaActive' => ['boolean'],
            'areaAllowMultipleProjects' => ['boolean'],
            'areaAllowAllServices' => ['boolean'],
            'areaAllowedServiceKeys' => ['array'],
            'areaAllowedServiceKeys.*' => ['string', 'max:80'],
            'areaSort' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $iso = strtoupper(trim($this->areaIso));
        $name = GetOdooCountries::name($iso);
        if ($name === null) {
            $this->addError('areaIso', __('Pick a country from the list.'));

            return;
        }

        $code = GetOdooPricingArea::normalizeCode($iso);
        $duplicate = GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_COUNTRY)
            ->where(function ($query) use ($code, $iso) {
                $query->where('code', $code)->orWhere('iso_code', $iso);
            })
            ->when($this->areaId, fn ($query) => $query->whereKeyNot($this->areaId))
            ->exists();
        if ($duplicate) {
            $this->addError('areaIso', __('That country is already configured.'));

            return;
        }

        $catalogKeys = collect(ServiceTemplateCatalog::launchOptions())->pluck('value')->all();
        $allowedServices = GetOdooAreaEntitlements::normalizeServiceKeys($this->areaAllowedServiceKeys);
        if ($catalogKeys !== []) {
            $allowedServices = array_values(array_intersect($allowedServices, $catalogKeys));
        }
        if (! $this->areaAllowAllServices && $allowedServices === []) {
            $this->addError('areaAllowedServiceKeys', __('Pick at least one service from the catalog, or allow all services.'));

            return;
        }

        $values = [
            'code' => $code,
            'name' => $name,
            'kind' => GetOdooPricingArea::KIND_COUNTRY,
            'parent_id' => null,
            'iso_code' => $iso,
            'extra_fixed' => round((float) $this->areaExtraFixed, 2),
            'extra_percent' => round((float) $this->areaExtraPercent, 2),
            'is_active' => $this->areaActive,
            'allow_multiple_projects' => $this->areaAllowMultipleProjects,
            'allow_all_services' => $this->areaAllowAllServices,
            'allowed_services' => $this->areaAllowAllServices ? null : $allowedServices,
            'sort_order' => (int) $this->areaSort,
        ];

        if ($this->areaId) {
            $area = GetOdooPricingArea::query()->findOrFail($this->areaId);
            abort_unless($area->isCountry(), 404);
            $area->update($values);
        } else {
            $area = GetOdooPricingArea::query()->create($values);
            $this->areaId = $area->id;
        }

        $this->areaIso = (string) $area->iso_code;
        $this->areaExtraFixed = number_format((float) $area->extra_fixed, 2, '.', '');
        $this->areaExtraPercent = number_format((float) $area->extra_percent, 2, '.', '');
        $this->areaAllowedServiceKeys = GetOdooAreaEntitlements::normalizeServiceKeys($area->allowed_services);
        $this->dispatch('success', __('The country was saved.'));
    }

    public function deleteArea(int $areaId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $area = GetOdooPricingArea::query()->findOrFail($areaId);
        abort_unless($area->isCountry(), 404);

        $area->plans()->detach();
        $area->delete();

        if ($this->areaId === $areaId) {
            $this->newArea();
        }

        $this->planCountryIds = array_values(array_filter(
            $this->planCountryIds,
            fn (string $id): bool => (int) $id !== $areaId
        ));

        $this->dispatch('success', __('The country was deleted.'));
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
        $countries = GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_COUNTRY)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('livewire.settings.getodoo-plans', [
            'plans' => GetOdooPlan::query()->with(['pricingAreas'])->withCount('signups')->orderBy('price')->orderBy('name')->get(),
            'signups' => GetOdooPlanSignup::query()->with(['plan', 'pricingArea'])->latest('id')->limit(20)->get(),
            'countries' => $countries,
            'standardCountryChoices' => GetOdooCountries::choices(),
            'catalogServices' => ServiceTemplateCatalog::launchOptions(),
            'countryCheckChoices' => $countries
                ->map(fn (GetOdooPricingArea $country): array => [
                    'value' => (string) $country->id,
                    'label' => $country->name,
                ])
                ->values()
                ->all(),
            'backupFrequencyChoices' => GetOdooBackupFrequency::choices(),
        ]);
    }
}
