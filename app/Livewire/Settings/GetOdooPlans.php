<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooPricingArea;
use App\Support\GetOdooBackupFrequency;
use App\Support\GetOdooCountries;
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

    public bool $includesBackups = false;

    public string $backupFrequency = GetOdooBackupFrequency::DAILY;

    public string $backupRetentionDays = '7';

    public bool $planAvailableWorldwide = true;

    /** @var list<string> ISO codes on this plan */
    public array $planCountryIsos = [];

    /** @var array<string, string> ISO => optional promo price */
    public array $planCountryPromos = [];

    public string $pendingCountryIso = '';

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
            'planCountryIsos',
            'planCountryPromos',
            'pendingCountryIso',
        ]);
        $this->price = '0';
        $this->active = true;
        $this->includesBackups = false;
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
        $storedFrequency = (string) ($plan->backup_frequency ?: GetOdooBackupFrequency::NONE);
        $this->includesBackups = $storedFrequency !== GetOdooBackupFrequency::NONE;
        $this->backupFrequency = $this->includesBackups ? $storedFrequency : GetOdooBackupFrequency::DAILY;
        $this->backupRetentionDays = (string) max(1, (int) ($plan->backup_retention_days ?: 7));
        $this->planAvailableWorldwide = $plan->isAvailableWorldwide();
        $this->planCountryIsos = [];
        $this->planCountryPromos = [];
        $this->pendingCountryIso = '';

        foreach ($plan->pricingAreas->where('kind', GetOdooPricingArea::KIND_COUNTRY) as $area) {
            $iso = strtoupper((string) ($area->iso_code ?: $area->code));
            if ($iso === '' || GetOdooCountries::name($iso) === null) {
                continue;
            }
            $this->planCountryIsos[] = $iso;
            if ($area->pivot?->promo_price !== null) {
                $this->planCountryPromos[$iso] = number_format((float) $area->pivot->promo_price, 2, '.', '');
            }
        }
        $this->planCountryIsos = array_values(array_unique($this->planCountryIsos));
    }

    public function updatedPlanAvailableWorldwide(bool $value): void
    {
        if ($value) {
            $this->planCountryIsos = [];
            $this->planCountryPromos = [];
            $this->pendingCountryIso = '';
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

        $this->planAvailableWorldwide = false;
        $iso = strtoupper(trim($this->pendingCountryIso));
        if (GetOdooCountries::name($iso) === null) {
            $this->addError('pendingCountryIso', __('Pick a country from the list.'));

            return;
        }
        if (! in_array($iso, $this->planCountryIsos, true)) {
            $this->planCountryIsos[] = $iso;
        }
        $this->pendingCountryIso = '';
        $this->resetErrorBag('pendingCountryIso');
    }

    public function removePlanCountry(string $iso): void
    {
        abort_unless(isInstanceOwner(), 403);

        $iso = strtoupper(trim($iso));
        $this->planCountryIsos = array_values(array_filter(
            $this->planCountryIsos,
            fn (string $row): bool => $row !== $iso
        ));
        unset($this->planCountryPromos[$iso]);
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
            'planAvailableWorldwide' => ['boolean'],
            'planCountryIsos' => ['array'],
            'planCountryIsos.*' => ['string', 'size:2', Rule::in(array_keys(GetOdooCountries::names()))],
            'planCountryPromos' => ['array'],
            'planCountryPromos.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        if (! $this->planAvailableWorldwide && $this->planCountryIsos === []) {
            $this->addError('planCountryIsos', __('Add at least one country to this plan, or mark it as available worldwide.'));

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

        if ($this->planAvailableWorldwide) {
            $plan->pricingAreas()->sync([]);
        } else {
            $sync = [];
            foreach (array_values(array_unique($this->planCountryIsos)) as $iso) {
                $area = $this->ensureCountryArea($iso);
                $promo = $this->planCountryPromos[$iso] ?? null;
                $promo = $promo === null || trim((string) $promo) === '' ? null : round((float) $promo, 2);
                $sync[$area->id] = ['promo_price' => $promo];
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
                'parent_id' => null,
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
        $planCountryRows = collect($this->planCountryIsos)
            ->map(fn (string $iso): array => [
                'iso' => $iso,
                'label' => GetOdooCountries::name($iso) ?? $iso,
                'promo' => $this->planCountryPromos[$iso] ?? '',
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $used = array_flip($this->planCountryIsos);
        $addCountryChoices = array_values(array_filter(
            GetOdooCountries::choices(),
            fn (array $row): bool => ! isset($used[$row['value']])
        ));

        return view('livewire.settings.getodoo-plans', [
            'plans' => GetOdooPlan::query()->with(['pricingAreas'])->withCount('signups')->orderBy('price')->orderBy('name')->get(),
            'signups' => GetOdooPlanSignup::query()->with(['plan', 'pricingArea'])->latest('id')->limit(20)->get(),
            'planCountryRows' => $planCountryRows,
            'addCountryChoices' => $addCountryChoices,
            'backupFrequencyChoices' => GetOdooBackupFrequency::choices(includeNone: false),
        ]);
    }
}
