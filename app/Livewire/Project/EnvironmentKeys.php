<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Support\EnvironmentKeys as EnvironmentKeysCatalog;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class EnvironmentKeys extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Environment $environment;

    public string $odooAdminPassword = '';

    public string $odooAdminPasswordConfirmation = '';

    public bool $passwordsHidden = false;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::query()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->passwordsHidden = auth()->user()?->isMember() ?? false;
        $this->loadOdooPasswordForm();
    }

    public function saveOdooAdminPassword(): void
    {
        $service = $this->odooService();
        if ($service === null) {
            $this->dispatch('error', __('No Odoo service in this environment.'));

            return;
        }

        $this->authorize('update', $service);

        $validator = Validator::make([
            'password' => $this->odooAdminPassword,
            'password_confirmation' => $this->odooAdminPasswordConfirmation,
        ], [
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ], [
            'password.confirmed' => __('The password confirmation does not match.'),
        ]);

        if ($validator->fails()) {
            $this->dispatch('error', $validator->errors()->first());

            return;
        }

        try {
            OdooGit::setAdminPassword($service, $this->odooAdminPassword);
            $this->odooAdminPassword = '';
            $this->odooAdminPasswordConfirmation = '';
            $this->loadOdooPasswordForm();
            $this->dispatch('success', __('Administrator password updated in Coolify and applied in Odoo.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        $this->environment->loadMissing('services');
        foreach ($this->environment->services as $row) {
            if ($row instanceof Service && $row->supportsOdooJupyter()) {
                // Claves-only: never runs on create/start. Autologin/token flow untouched.
                try {
                    OdooGit::syncAdminPasswordFromVolume($row);
                    OdooGit::prepareInstance($row);
                } catch (\Throwable) {
                    // Keep rendering whatever is already stored.
                }
            }
        }
        $cards = EnvironmentKeysCatalog::forEnvironment($this->environment->fresh(['services']));
        if ($this->passwordsHidden) {
            $cards = array_map(function (array $card): array {
                $card['credentials'] = array_map(function (array $credential): array {
                    if (in_array($credential['kind'], ['password', 'token'], true)) {
                        $credential['value'] = '';
                    }

                    return $credential;
                }, $card['credentials']);

                return $card;
            }, $cards);
        }

        $service = $this->odooService()
            ?? $this->environment->services->first();

        return view('livewire.project.environment-keys', [
            'cards' => $cards,
            'service' => $service,
            'canEditOdooPassword' => $service instanceof Service
                && $service->supportsOdooJupyter()
                && auth()->user()?->can('update', $service)
                && ! $this->passwordsHidden,
        ]);
    }

    private function loadOdooPasswordForm(): void
    {
        // Keep the change form empty; reveal happens from the stored credential row.
        $this->odooAdminPassword = '';
        $this->odooAdminPasswordConfirmation = '';
    }

    private function odooService(): ?Service
    {
        $this->environment->loadMissing('services');

        $service = $this->environment->services->first(
            fn ($row): bool => $row instanceof Service && $row->supportsOdooJupyter()
        );

        return $service instanceof Service ? $service : null;
    }
}
