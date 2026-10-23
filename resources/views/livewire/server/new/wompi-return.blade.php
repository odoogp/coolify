<div
    wire:poll.5s="refreshStatus"
    class="mx-auto flex max-w-lg flex-col gap-3 py-10"
    @if ($paymentUrl ?? null)
        x-data
        x-init="
            const key = 'n1co-pay-order-{{ ($order ?? null)?->uuid }}';
            if (!sessionStorage.getItem(key)) {
                sessionStorage.setItem(key, '1');
                window.open(@js($paymentUrl), '_blank', 'noopener');
            }
        "
    @endif
>
    <h1 class="text-lg font-semibold">{{ product_name() }}</h1>
    <p class="text-sm text-neutral-600 dark:text-fg-dim">{{ $message }}</p>
    @if ($paymentUrl ?? null)
        <a href="{{ $paymentUrl }}" target="_blank" rel="noopener noreferrer" class="button button-highlighted w-fit">
            {{ __('Continue to n1co') }}
        </a>
        <p class="text-sm text-neutral-500 dark:text-fg-faint">
            {{ __('Keep this page open. When n1co confirms the payment, this page continues.') }}
        </p>
    @endif
    <a href="{{ route('server.create.type', ['type' => 'getodoo']) }}" class="text-sm font-medium" {{ wireNavigate() }}>
        {{ __('GetOdoo server') }}
    </a>
</div>
