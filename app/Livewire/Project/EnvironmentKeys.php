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

    public string $unlockPassword = '';

    public bool $unlocked = false;

    public bool $passwordsHidden = false;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::query()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->passwordsHidden = auth()->user()?->isMember() ?? false;
        $this->unlocked = $this->sessionUnlocked() || shouldSkipPasswordConfirmation();
        $this->loadOdooPasswordForm();
    }

    public function unlockKeys(): void
    {
        $this->authorize('view', $this->project);

        if ($this->passwordsHidden) {
            $this->dispatch('error', __('Members cannot view environment keys.'));

            return;
        }

        if (shouldSkipPasswordConfirmation()) {
            $this->markUnlocked();
            $this->alignOdooAdminPasswordAfterUnlock();

            return;
        }

        if (! verifyPasswordConfirmation($this->unlockPassword, $this)) {
            $this->dispatch('error', __('The provided password is incorrect.'));
            $this->unlockPassword = '';

            return;
        }

        $this->unlockPassword = '';
        $this->markUnlocked();
        $this->alignOdooAdminPasswordAfterUnlock();
    }

    public function lockKeys(): void
    {
        $this->unlocked = false;
        session()->forget($this->unlockSessionKey());
        $this->loadOdooPasswordForm();
    }

    public function saveOdooAdminPassword(): void
    {
        if (! $this->ensureUnlocked()) {
            return;
        }

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
            $this->dispatch('success', __('Administrator password updated in :product and applied in Odoo.', [
                'product' => product_name(),
            ]));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Force the password shown in Keys into Odoo admin (no current Odoo password needed).
     * Connect-as never sets a usable password, so Settings → Users → Change password
     * asks for a current tip that is empty / unknown — this breaks that circle.
     */
    public function applyStoredOdooAdminPassword(): void
    {
        if (! $this->ensureUnlocked()) {
            return;
        }

        $service = $this->odooService();
        if ($service === null) {
            $this->dispatch('error', __('No Odoo service in this environment.'));

            return;
        }

        $this->authorize('update', $service);

        try {
            if (! $this->pushStoredPasswordIntoOdoo($service)) {
                $this->dispatch('error', __('No administrator password is stored yet.'));

                return;
            }
            $this->dispatch('success', __('Odoo admin now uses the password shown in Keys. In Settings → Users → Change password, use that tip as the current password.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        $this->environment->loadMissing('services');
        $cards = [];
        if ($this->unlocked && ! $this->passwordsHidden) {
            foreach ($this->environment->services as $row) {
                if ($row instanceof Service && $row->supportsOdooJupyter()) {
                    try {
                        OdooGit::syncAdminPasswordFromVolume($row);
                        OdooGit::prepareInstance($row);
                    } catch (\Throwable) {
                        // Keep rendering whatever is already stored.
                    }
                }
            }
            $cards = EnvironmentKeysCatalog::forEnvironment($this->environment->fresh(['services']));
        }

        $service = $this->odooService()
            ?? $this->environment->services->first();

        return view('livewire.project.environment-keys', [
            'cards' => $cards,
            'service' => $service,
            'canEditOdooPassword' => $this->unlocked
                && $service instanceof Service
                && $service->supportsOdooJupyter()
                && auth()->user()?->can('update', $service)
                && ! $this->passwordsHidden,
        ]);
    }

    private function ensureUnlocked(): bool
    {
        if ($this->unlocked || shouldSkipPasswordConfirmation()) {
            return true;
        }
        $this->dispatch('error', __('Confirm your :product password to open Keys.', [
            'product' => product_name(),
        ]));

        return false;
    }

    private function markUnlocked(): void
    {
        $this->unlocked = true;
        session([
            $this->unlockSessionKey() => now()->addMinutes(15)->getTimestamp(),
        ]);
    }

    /**
     * After unlock: make sure admin has the Claves tip in Odoo so Users → Change password works.
     * Best-effort — never blocks unlock if Odoo is down.
     */
    private function alignOdooAdminPasswordAfterUnlock(): void
    {
        $service = $this->odooService();
        if ($service === null || ! auth()->user()?->can('update', $service)) {
            $this->dispatch('success', __('Keys unlocked for this session.'));

            return;
        }

        try {
            OdooGit::prepareInstance($service);
            if ($this->pushStoredPasswordIntoOdoo($service->fresh() ?? $service)) {
                $this->dispatch('success', __('Keys unlocked. The admin password from Keys was written into Odoo so Settings → Users → Change password can use it as the current password.'));

                return;
            }
        } catch (\Throwable) {
            // Unlock still succeeds; user can retry with Apply.
        }

        $this->dispatch('success', __('Keys unlocked for this session.'));
    }

    private function pushStoredPasswordIntoOdoo(Service $service): bool
    {
        $password = OdooGit::runtimeValue($service, 'ODOO_ADMIN_PASSWORD');
        if ($password === '') {
            return false;
        }
        OdooGit::setAdminPassword($service, $password);

        return true;
    }

    private function sessionUnlocked(): bool
    {
        $until = (int) session($this->unlockSessionKey(), 0);

        return $until > now()->getTimestamp();
    }

    private function unlockSessionKey(): string
    {
        return 'environment-keys-unlocked-'.(int) auth()->id().'-'.$this->environment->uuid;
    }

    private function loadOdooPasswordForm(): void
    {
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
