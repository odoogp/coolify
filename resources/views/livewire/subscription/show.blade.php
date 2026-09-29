<div class="application-settings-form w-full max-w-none">
    <x-slot:title>
        {{ __('Subscription | Coolify') }}
    </x-slot>

    <x-dashboard.navbar section="subscription" title="{{ __('Subscription') }}"
        subtitle="{{ __('Plan and billing for Coolify Cloud') }}" />

    <livewire:subscription.actions />
</div>
