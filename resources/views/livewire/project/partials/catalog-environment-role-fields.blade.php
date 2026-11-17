@php
    $environmentRoleOptions = $environmentRoleOptions ?? [
        ['value' => \App\Models\Environment::ROLE_PRODUCTION, 'label' => __('Production')],
        ['value' => \App\Models\Environment::ROLE_STAGING, 'label' => __('Staging')],
    ];
    $roleModel = $roleModel ?? 'environmentRole';
    $roleLabel = $roleLabel ?? __('Environment type');
@endphp
<div class="space-y-1">
    <p class="text-[13px] font-medium">{{ $roleLabel }}</p>
    <div class="flex flex-col gap-2 text-[13px]">
        @foreach ($environmentRoleOptions as $option)
            <label class="inline-flex items-center gap-2">
                <input type="radio" wire:model.live="{{ $roleModel }}" value="{{ $option['value'] }}" class="rounded-full">
                {{ $option['label'] }}
            </label>
        @endforeach
    </div>
</div>
