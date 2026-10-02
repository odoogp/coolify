<?php

namespace App\Livewire\Notifications;

use App\Models\GpshNoticeSetting;
use App\Models\Team;
use App\Support\GpshNotices;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Center extends Component
{
    public bool $showMounted = true;

    public bool $showAccessible = true;

    public bool $showExpiration = true;

    public bool $showDeletion = true;

    public bool $showCustom = true;

    #[Validate(['required', 'string', 'max:160'])]
    public string $title = '';

    #[Validate(['required', 'string', 'max:2000'])]
    public string $body = '';

    #[Validate(['required', 'in:owner,clients'])]
    public string $audience = 'clients';

    #[Validate(['required', 'in:custom,expiration,deletion'])]
    public string $kind = 'custom';

    public string $teamId = '';

    public function mount(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $settings = GpshNoticeSetting::current();
        $this->showMounted = $settings->mounted;
        $this->showAccessible = $settings->accessible;
        $this->showExpiration = $settings->expiration;
        $this->showDeletion = $settings->deletion;
        $this->showCustom = $settings->custom;
    }

    public function saveSettings(): void
    {
        abort_unless(isInstanceOwner(), 403);

        GpshNoticeSetting::current()->fill([
            'mounted' => $this->showMounted,
            'accessible' => $this->showAccessible,
            'expiration' => $this->showExpiration,
            'deletion' => $this->showDeletion,
            'custom' => $this->showCustom,
        ])->save();

        $this->dispatch('success', __('Notice settings saved.'));
    }

    public function send(): void
    {
        abort_unless(isInstanceOwner(), 403);
        $this->validate();

        $teamId = $this->teamId === '' ? null : (int) $this->teamId;
        if ($teamId !== null && ! Team::query()->whereKey($teamId)->where('id', '>', 0)->exists()) {
            $this->addError('teamId', __('Choose a client.'));

            return;
        }

        $notice = GpshNotices::publish(
            null,
            $this->kind,
            $this->title,
            $this->body,
            $this->audience,
            $this->audience === 'clients' ? $teamId : null,
            Auth::id(),
        );
        if ($notice === null) {
            $this->dispatch('error', __('That kind of notice is turned off.'));

            return;
        }

        $this->reset('title', 'body');
        $this->kind = 'custom';
        $this->audience = 'clients';
        $this->teamId = '';
        $this->dispatch('success', __('Notice sent.'));
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.notifications.center', [
            'notices' => $user === null ? collect() : GpshNotices::forUser($user)->with('service.environment.project')->limit(50)->get(),
            'teams' => Team::query()->where('id', '>', 0)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
