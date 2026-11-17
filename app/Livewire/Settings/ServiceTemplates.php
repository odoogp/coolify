<?php

namespace App\Livewire\Settings;

use App\Support\ServiceTemplateCatalog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Component;

class ServiceTemplates extends Component
{
    use AuthorizesRequests;

    public string $search = '';

    public ?string $serviceName = null;

    public string $compose = '';

    public string $displayName = '';

    public string $description = '';

    public string $category = '';

    public bool $isVisible = true;

    public bool $includesJupyter = false;

    public bool $creating = false;

    public string $newName = '';

    public function mount(): void
    {
        $this->authorize('updateServiceTemplates');
    }

    public function startCreate(): void
    {
        $this->authorize('updateServiceTemplates');
        $this->resetErrorBag();
        $this->creating = true;
        $this->serviceName = null;
        $this->compose = "services:\n  app:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n";
        $this->displayName = '';
        $this->description = '';
        $this->category = 'Custom';
        $this->isVisible = true;
        $this->includesJupyter = false;
        $this->newName = '';
    }

    public function cancelCreate(): void
    {
        $this->creating = false;
        $this->newName = '';
        $this->compose = '';
        $this->displayName = '';
        $this->description = '';
        $this->category = '';
        $this->isVisible = true;
        $this->includesJupyter = false;
    }

    public function selectService(string $name): void
    {
        $this->authorize('updateServiceTemplates');
        $this->creating = false;

        try {
            $compose = ServiceTemplateCatalog::composeFor($name);
        } catch (InvalidArgumentException $exception) {
            $this->addError('compose', $exception->getMessage());

            return;
        }

        if ($compose === null) {
            $this->addError('compose', __('This template has no Compose file.'));

            return;
        }

        $summary = collect(ServiceTemplateCatalog::summaries())->firstWhere('name', $name);
        $this->resetErrorBag();
        $this->serviceName = $name;
        $this->compose = $compose;
        $this->displayName = (string) ($summary['label'] ?? $name);
        $this->description = '';
        $this->category = (string) ($summary['category'] ?? '');
        $this->isVisible = (bool) ($summary['is_visible'] ?? true);
        $this->includesJupyter = (bool) ($summary['includes_jupyter'] ?? ServiceTemplateCatalog::includesJupyter($name));

        $row = \App\Models\ServiceTemplateOverride::query()->where('name', $name)->first();
        if ($row !== null) {
            $this->displayName = (string) ($row->display_name ?: $this->displayName);
            $this->description = (string) ($row->description ?? '');
            $this->category = (string) ($row->category ?? '');
            $this->isVisible = (bool) $row->is_visible;
            $this->includesJupyter = (bool) $row->includes_jupyter;
        }
    }

    public function create(): void
    {
        $this->authorize('updateServiceTemplates');
        $this->validate([
            'newName' => ['required', 'string', 'max:80'],
            'compose' => ['required', 'string', 'max:512000'],
            'displayName' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:80'],
            'isVisible' => ['boolean'],
            'includesJupyter' => ['boolean'],
        ]);

        try {
            ServiceTemplateCatalog::create($this->newName, $this->compose, auth()->id(), [
                'display_name' => $this->displayName,
                'description' => $this->description,
                'category' => $this->category !== '' ? $this->category : 'Custom',
                'is_visible' => $this->isVisible,
                'includes_jupyter' => $this->includesJupyter,
            ]);
        } catch (\Exception $exception) {
            $this->addError('compose', $exception->getMessage());

            return;
        }

        $name = \Illuminate\Support\Str::slug($this->newName);
        $this->creating = false;
        $this->selectService($name);
        $this->dispatch('success', __('Service template created. It is available to launch in one click.'));
    }

    public function save(): void
    {
        $this->authorize('updateServiceTemplates');
        $this->validate([
            'serviceName' => ['required', 'string', 'max:255'],
            'compose' => ['required', 'string', 'max:512000'],
            'displayName' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:80'],
            'isVisible' => ['boolean'],
            'includesJupyter' => ['boolean'],
        ]);

        try {
            ServiceTemplateCatalog::save((string) $this->serviceName, $this->compose, auth()->id(), [
                'display_name' => $this->displayName,
                'description' => $this->description,
                'category' => $this->category,
                'is_visible' => $this->isVisible,
                'includes_jupyter' => $this->includesJupyter,
            ]);
        } catch (\Exception $exception) {
            $this->addError('compose', $exception->getMessage());

            return;
        }

        $this->compose = ServiceTemplateCatalog::composeFor((string) $this->serviceName) ?? $this->compose;
        $this->dispatch('success', __('Service template saved. New services use this Compose. Existing services keep their own copy.'));
    }

    public function restore(): void
    {
        $this->authorize('updateServiceTemplates');
        if ($this->serviceName === null) {
            return;
        }

        $wasCustom = ServiceTemplateCatalog::isCustom($this->serviceName);
        ServiceTemplateCatalog::restore($this->serviceName);
        if ($wasCustom) {
            $this->serviceName = null;
            $this->compose = '';
            $this->dispatch('success', __('Custom template removed.'));

            return;
        }

        $this->compose = ServiceTemplateCatalog::composeFor($this->serviceName) ?? '';
        $this->dispatch('success', __('Restored the catalog Compose. New services use it again.'));
    }

    public function render()
    {
        $search = strtolower(trim($this->search));
        $services = collect(ServiceTemplateCatalog::summaries())
            ->filter(function (array $service) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                return str_contains(strtolower($service['name']), $search)
                    || str_contains(strtolower($service['label']), $search);
            })
            ->values();

        return view('livewire.settings.service-templates', [
            'services' => $services,
            'overridden' => $this->serviceName !== null && ServiceTemplateCatalog::isOverridden($this->serviceName),
            'isCustom' => $this->serviceName !== null && ServiceTemplateCatalog::isCustom($this->serviceName),
        ]);
    }
}
