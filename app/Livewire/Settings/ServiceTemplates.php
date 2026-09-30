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

    public function mount(): void
    {
        $this->authorize('updateServiceTemplates');
    }

    public function selectService(string $name): void
    {
        $this->authorize('updateServiceTemplates');

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

        $this->resetErrorBag();
        $this->serviceName = $name;
        $this->compose = $compose;
    }

    public function save(): void
    {
        $this->authorize('updateServiceTemplates');
        $this->validate([
            'serviceName' => ['required', 'string', 'max:255'],
            'compose' => ['required', 'string', 'max:512000'],
        ]);

        try {
            ServiceTemplateCatalog::save((string) $this->serviceName, $this->compose, auth()->id());
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

        ServiceTemplateCatalog::restore($this->serviceName);
        $this->compose = ServiceTemplateCatalog::composeFor($this->serviceName) ?? '';
        $this->dispatch('success', __('Restored the catalog Compose. New services use it again.'));
    }

    public function render()
    {
        $search = strtolower(trim($this->search));
        $services = collect(ServiceTemplateCatalog::summaries())
            ->filter(fn (array $service): bool => $search === '' || str_contains($service['name'], $search))
            ->values();

        return view('livewire.settings.service-templates', [
            'services' => $services,
            'overridden' => $this->serviceName !== null && ServiceTemplateCatalog::isOverridden($this->serviceName),
        ]);
    }
}
