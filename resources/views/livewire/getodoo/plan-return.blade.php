@php
    $paymentUrl = filled($signup?->wompi_link_url) && $signup?->status === 'awaiting_payment'
        ? (string) $signup->wompi_link_url
        : null;
@endphp
<div @if (($signup?->payment_gateway) === 'n1co') wire:poll.5s="refreshStatus" @endif>
    <x-auth.shell :title="product_name()" :description="$signup?->plan?->name">
        @if (filled($message))
            <x-auth.alert type="warning">
                <p>{{ $message }}</p>
            </x-auth.alert>
        @endif
        @if ($paymentUrl)
            <a href="{{ $paymentUrl }}" target="_blank" rel="noopener noreferrer"
                class="button button-highlighted mt-4 w-full justify-center">
                {{ __('Continue to n1co') }}
            </a>
            <p class="mt-3 text-center text-sm text-neutral-500 dark:text-fg-faint">
                {{ __('Keep this page open. When n1co confirms the payment, your account opens here.') }}
            </p>
        @elseif ($signup?->plan)
            <a href="{{ $signup->plan->publicUrl() }}" class="button mt-4 w-full justify-center">
                {{ __('Back to the plan') }}
            </a>
        @endif
    </x-auth.shell>
</div>
