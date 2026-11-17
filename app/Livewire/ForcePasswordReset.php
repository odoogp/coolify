<?php

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class ForcePasswordReset extends Component
{
    use WithRateLimiting;

    public string $email;

    public string $password;

    public string $password_confirmation;

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ];
    }

    public function mount()
    {
        if (auth()->user()->force_password_reset === false) {
            return redirect()->route('dashboard');
        }
        $this->email = auth()->user()->email;
    }

    public function render()
    {
        return view('livewire.force-password-reset')->layout('layouts.simple');
    }

    public function submit()
    {
        if (auth()->user()->force_password_reset === false) {
            return redirect()->route('dashboard');
        }

        try {
            $this->rateLimit(10);
            $this->validate();
            $user = auth()->user();
            $team = $user->currentTeam() ?? $user->teams()->first();
            $user->forceFill([
                'password' => Hash::make($this->password),
                'force_password_reset' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            Auth::login($user);
            session(['currentTeam' => $team]);

            return redirect()->route('dashboard');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
