<?php

namespace App\Livewire\GetOdoo;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class Terms extends Component
{
    public function render(): View
    {
        $settings = instanceSettings();

        return view('livewire.getodoo.terms', [
            'html' => $settings->sanitizedTermsAndConditionsHtml(),
            'hasTerms' => $settings->hasTermsAndConditions(),
        ])->layout('layouts.simple');
    }
}
