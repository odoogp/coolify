<div wire:poll.5s="refreshStatus" class="mx-auto flex max-w-lg flex-col gap-3 py-10">
    <h1 class="text-lg font-semibold">GetOdoo</h1>
    <p class="text-sm text-neutral-600 dark:text-fg-dim">{{ $message }}</p>
    <a href="{{ route('server.create.type', ['type' => 'getodoo']) }}" class="text-sm font-medium" {{ wireNavigate() }}>
        {{ __('GetOdoo server') }}
    </a>
</div>
