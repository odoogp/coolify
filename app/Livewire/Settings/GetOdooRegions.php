<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooPricingArea;
use App\Support\GetOdooCountries;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class GetOdooRegions extends Component
{
    public bool $showRegionEditor = false;

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

    public function newRegion(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->resetErrorBag();
        $this->regionId = null;
        $this->regionName = '';
        $this->regionCountries = [];
        $this->pendingRegionCountry = '';
        $this->showRegionEditor = true;
    }

    public function editRegion(int $regionId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $region = GetOdooPricingArea::query()
            ->whereKey($regionId)
            ->where('kind', GetOdooPricingArea::KIND_REGION)
            ->with('children')
            ->firstOrFail();

        $this->resetErrorBag();
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
        $this->showRegionEditor = true;
    }

    public function cancelRegionEditor(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->showRegionEditor = false;
        $this->regionId = null;
        $this->regionName = '';
        $this->regionCountries = [];
        $this->pendingRegionCountry = '';
        $this->resetErrorBag();
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
        foreach (array_values(array_unique($this->regionCountries)) as $countryName) {
            $iso = GetOdooCountries::isoFromName($countryName);
            if ($iso === null) {
                continue;
            }
            $country = GetOdooPricingArea::ensureCountry($iso);
            $country->forceFill(['parent_id' => $region->id, 'is_active' => true])->save();
            $keepIds[] = $country->id;
        }

        GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_COUNTRY)
            ->where('parent_id', $region->id)
            ->whereNotIn('id', $keepIds)
            ->update(['parent_id' => null]);

        $this->showRegionEditor = false;
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
            $this->cancelRegionEditor();
        }

        $this->dispatch('success', __('The region was deleted.'));
    }

    public function render(): View
    {
        $regions = GetOdooPricingArea::query()
            ->where('kind', GetOdooPricingArea::KIND_REGION)
            ->with(['children' => fn ($q) => $q->where('kind', GetOdooPricingArea::KIND_COUNTRY)->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

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

        return view('livewire.settings.getodoo-regions', [
            'regions' => $regions,
            'regionCountryRows' => $regionCountryRows,
            'addRegionCountryChoices' => $addRegionCountryChoices,
        ]);
    }
}
