{{-- Shared version step for catalog Launch (empty env / New environment). --}}
@php
    $versionOptions = $versionOptions ?? [];
    $needsVersionChoice = $needsVersionChoice ?? false;
@endphp

@if ($selectedService === 'odoo' && $versionOptions === [])
    <p class="rounded-lg border border-amber-200/80 bg-amber-50 px-3 py-2.5 text-[12px] text-amber-950 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-100">
        {{ __('No Odoo Compose templates are configured. Add one in Settings → Odoo first.') }}
    </p>
@elseif ($needsVersionChoice && $selectedService === 'odoo')
    <x-forms.listbox id="odooVersion" label="{{ __('Odoo version') }}" portal required
        :options="$versionOptions" />
    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
        {{ __('Choose the Compose for this environment. Other environments can use a different Odoo version.') }}
    </p>
@elseif ($needsVersionChoice && $selectedService !== 'odoo' && $versionOptions !== [])
    <x-forms.listbox id="serviceVersion" label="{{ __('Version') }}" portal required
        :options="$versionOptions" />
    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
        {{ __('Choose which version to install for this service.') }}
    </p>
@endif
