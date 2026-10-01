@props(['number'])

@php
    $user = auth()->user();
    $who = trim((string) ($user->name ?? ''));
    if (filled($user?->email)) {
        $who = trim($who.' ('.$user->email.')');
    }
    $topics = [
        __('I cannot sign in'),
        __('I want to launch a project'),
        __('GitHub'),
        __('Something else'),
    ];
@endphp

<div class="fixed right-4 bottom-4 z-40 flex flex-col items-end" x-data="{ open: false }">
    <div x-show="open" x-cloak x-transition
        class="mb-3 w-[min(100vw-2rem,20rem)] overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-lg dark:border-white/10 dark:bg-panel">
        <div class="flex items-start justify-between gap-3 bg-[#128C7E] px-4 py-3 text-white">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">{{ product_name() }}</p>
                <p class="text-xs text-white/80">{{ __('Which area do you want to talk about?') }}</p>
            </div>
            <button type="button" class="shrink-0 text-white/80 hover:text-white" @click="open = false"
                aria-label="{{ __('Close') }}">
                <x-reicon name="x" class="size-4" />
            </button>
        </div>
        <div class="flex flex-col gap-1 p-2">
            @foreach ($topics as $topic)
                <a class="rounded-xl px-3 py-2.5 text-left text-sm text-neutral-800 hover:bg-neutral-100 dark:text-fg dark:hover:bg-white/[0.06]"
                    target="_blank" rel="noopener noreferrer"
                    href="https://wa.me/{{ $number }}?text={{ rawurlencode(__('Hello, I am :who on :product. I need help with: :topic.', ['who' => $who !== '' ? $who : product_name(), 'product' => product_name(), 'topic' => $topic])) }}">
                    {{ $topic }}
                </a>
            @endforeach
        </div>
        <p class="px-4 pb-3 text-center text-[11px] text-neutral-500 dark:text-fg-dim">
            {{ __('Choose an option to start the conversation') }}
        </p>
    </div>
    <button type="button"
        class="flex size-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg"
        @click="open = !open" :aria-expanded="open.toString()"
        aria-label="{{ __('Chat with us on WhatsApp') }}">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-7" aria-hidden="true">
            <path d="M20.5 3.5A11 11 0 0 0 2.1 17.8L1 23l5.3-1.1A11 11 0 0 0 20.5 3.5zM12 20.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3.1.7.7-3-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.4-.7-1.6-.8s-.4-.1-.5.1-.6.8-.7.9-.3.2-.5.1a6.7 6.7 0 0 1-2-1.2 7.4 7.4 0 0 1-1.4-1.7c-.1-.2 0-.4.1-.5l.4-.4.2-.3a1.6 1.6 0 0 0 0-.5c0-.1-.5-1.3-.7-1.7s-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4 15 15 0 0 0 1.5.6 3.6 3.6 0 0 0 1.7.1 2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.2-.1-.4-.2z" />
        </svg>
    </button>
</div>
