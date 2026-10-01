<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Whatsapp extends Component
{
    use AuthorizesRequests;

    public InstanceSettings $settings;

    public ?string $whatsapp_support_number = null;

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->settings = instanceSettings();
        $this->whatsapp_support_number = $this->settings->whatsapp_support_number;
    }

    public function submit(): void
    {
        if (! isInstanceOwner()) {
            abort(403);
        }

        try {
            $this->authorize('update', $this->settings);
            $digits = preg_replace('/\D+/', '', (string) $this->whatsapp_support_number) ?? '';
            if ($digits !== '' && preg_match('/^[1-9]\d{7,14}$/', $digits) !== 1) {
                $this->addError('whatsapp_support_number', __('Use the country code and the number, digits only.'));

                return;
            }

            $this->whatsapp_support_number = $digits !== '' ? $digits : null;
            $this->settings->whatsapp_support_number = $this->whatsapp_support_number;
            $this->settings->save();
            $this->dispatch('success', __('Settings updated!'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.settings.whatsapp');
    }
}
