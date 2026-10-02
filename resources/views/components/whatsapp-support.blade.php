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
        class="flex size-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-md"
        @click="open = !open" :aria-expanded="open.toString()"
        aria-label="{{ __('Chat with us on WhatsApp') }}">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-8" aria-hidden="true">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
        </svg>
    </button>
</div>
