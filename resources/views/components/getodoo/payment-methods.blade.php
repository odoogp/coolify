@props([
    'gateways' => [],
    'model' => null,
])

@php
    $gateways = array_values(array_filter(
        $gateways,
        fn ($gateway) => in_array($gateway, ['wompi', 'n1co'], true),
    ));
@endphp

@if ($gateways !== [])
    <div class="flex flex-col gap-2">
        @foreach ($gateways as $gateway)
            @php $tag = $model ? 'label' : 'div'; @endphp
            <{{ $tag }} @class([
                'group flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2.5 dark:border-white/10 dark:bg-white/[0.03]',
                'cursor-pointer has-[:checked]:border-neutral-900 has-[:checked]:ring-1 has-[:checked]:ring-neutral-900 dark:has-[:checked]:border-white dark:has-[:checked]:ring-white' => $model,
                'ring-1 ring-neutral-900 dark:ring-white' => ! $model,
            ])>
                @if ($model)
                    <input type="radio" name="gateway" value="{{ $gateway }}" wire:model="{{ $model }}" class="sr-only">
                @endif
                <span @class([
                    'flex size-8 shrink-0 items-center justify-center rounded-lg text-[13px] font-bold tracking-tight text-white',
                    'bg-[#1434A4]' => $gateway === 'wompi',
                    'bg-[#111111]' => $gateway === 'n1co',
                ]) aria-hidden="true">
                    {{ $gateway === 'wompi' ? 'W' : 'n1' }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold tracking-tight text-neutral-900 dark:text-fg">
                        {{ $gateway === 'wompi' ? 'Wompi' : 'n1co' }}
                    </span>
                    <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <span class="sr-only">{{ __('Accepted cards') }}</span>
                        <span class="inline-flex h-6 items-center rounded bg-[#1A1F71] px-1.5 text-[11px] font-bold italic tracking-wide text-white" title="Visa">VISA</span>
                        <span class="sr-only">Visa</span>
                        <span class="inline-flex h-6 items-center rounded bg-white px-1 ring-1 ring-neutral-200" title="Mastercard">
                            <svg width="28" height="16" viewBox="0 0 28 16" aria-hidden="true">
                                <circle cx="11" cy="8" r="6.5" fill="#EB001B" />
                                <circle cx="17" cy="8" r="6.5" fill="#F79E1B" />
                                <path d="M14 2.7a6.5 6.5 0 0 1 0 10.6 6.5 6.5 0 0 1 0-10.6z" fill="#FF5F00" />
                            </svg>
                        </span>
                        <span class="sr-only">Mastercard</span>
                    </span>
                </span>
                @if ($model)
                    <span class="size-4 shrink-0 rounded-full border border-neutral-300 group-has-[:checked]:border-[5px] group-has-[:checked]:border-neutral-900 dark:border-white/30 dark:group-has-[:checked]:border-white" aria-hidden="true"></span>
                @endif
            </{{ $tag }}>
        @endforeach
    </div>
@endif
