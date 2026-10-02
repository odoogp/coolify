<?php

namespace App\Livewire\Settings;

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Github extends Component
{
    use AuthorizesRequests;

    public InstanceSettings $settings;

    #[Validate(['required', 'string', 'max:34', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,33}$/'])]
    public string $github_app_name = 'gpsh1';

    #[Validate('nullable|string|max:2048|url')]
    public ?string $github_app_icon = null;

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->settings = instanceSettings();
        $this->github_app_name = is_string($this->settings->github_app_name) && $this->settings->github_app_name !== ''
            ? $this->settings->github_app_name
            : 'gpsh1';
        $this->github_app_icon = $this->settings->github_app_icon;
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->settings);
            if ($this->github_app_icon === '') {
                $this->github_app_icon = null;
            }
            $this->validate();
            $this->settings->github_app_name = $this->github_app_name;
            $this->settings->github_app_icon = $this->github_app_icon;
            $this->settings->save();
            $this->dispatch('success', __('Settings updated!'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.settings.github', [
            'apps' => GithubApp::query()->with('team')->orderBy('name')->get(),
        ]);
    }
}
