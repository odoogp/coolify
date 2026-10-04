<div class="relative" x-data="{ open: false, show: false, title: '', body: '', hide: null }"
    @if ($announce)
        x-on:gpsh-toast.window="title = $event.detail.title || ''; body = $event.detail.body || ''; show = true; clearTimeout(hide); hide = setTimeout(() => { show = false }, ($event.detail.seconds || 8) * 1000)"
    @endif
    @click.outside="open = false" wire:poll.15s>
    <button type="button" class="relative flex size-8 items-center justify-center rounded-lg text-neutral-500 hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-white"
        x-on:click="open = !open" aria-label="{{ __('Notices') }}">
        <x-reicon name="notifications" class="size-4" />
        @if ($unread > 0)
            <span class="absolute right-1 top-1 size-2 rounded-full bg-coollabs dark:bg-warning"></span>
        @endif
    </button>
    <div x-cloak x-show="open"
        class="notice-panel z-[80] overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg dark:border-white/[0.08] dark:bg-panel">
        <div class="flex shrink-0 items-center justify-between gap-2 border-b border-neutral-200 px-3 py-2 dark:border-white/[0.06]">
            <span class="min-w-0 truncate text-[13px] font-medium">{{ __('Notices') }}</span>
            <div class="flex shrink-0 items-center gap-2">
                @if ($unread > 0)
                    <button type="button" wire:click="markAllRead" class="text-[12px] text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-white">
                        {{ __('Mark as read') }}
                    </button>
                @endif
                @if (isInstanceOwner() && $notices->isNotEmpty())
                    <button type="button" wire:click="deleteAll" wire:confirm="{{ __('Delete all notices?') }}"
                        class="text-[12px] text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-white">
                        {{ __('Delete all') }}
                    </button>
                @endif
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            @forelse ($notices as $notice)
                <button type="button" wire:click="openNotice({{ $notice->id }})"
                    class="block w-full border-b border-neutral-100 px-3 py-2.5 text-left last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.04] dark:hover:bg-white/[0.03]">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                        @if ($loop->first)
                            <span class="rounded-full bg-coollabs/15 px-1.5 py-0.5 text-[10px] font-medium text-coollabs dark:bg-warning/15 dark:text-warning">{{ __('Latest') }}</span>
                        @endif
                        <span>{{ $notice->kindLabel() }}</span>
                        <span>{{ $notice->audienceLabel() }}</span>
                    </span>
                    <span class="mt-0.5 block break-words text-[13px] font-medium text-black dark:text-white">{{ $notice->title }}</span>
                    <span class="mt-0.5 block break-words text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">{{ str($notice->body)->limit(140) }}</span>
                    @if ($notice->kind === 'accessible' && is_string($notice->href()) && ! str_starts_with((string) $notice->href(), url('/')))
                        <span class="mt-1 block text-[12px] font-medium text-coollabs dark:text-warning">{{ __('Open Odoo') }}</span>
                    @endif
                </button>
            @empty
                <p class="px-3 py-6 text-center text-[13px] text-neutral-500 dark:text-fg-faint">{{ __('No notices') }}</p>
            @endforelse
        </div>
        @if (isInstanceOwner())
            <a href="{{ route('notifications.center') }}" {{ wireNavigate() }}
                class="block shrink-0 border-t border-neutral-200 px-3 py-2 text-center text-[12px] font-medium text-black hover:bg-neutral-50 dark:border-white/[0.06] dark:text-white dark:hover:bg-white/[0.03]">
                {{ __('Notification center') }}
            </a>
        @endif
    </div>
    @if ($announce)
        <div x-cloak x-show="show" x-transition.opacity.duration.200ms
            class="fixed inset-x-3 top-16 z-[100] max-w-sm rounded-lg border border-neutral-200 bg-white p-3 shadow-lg sm:inset-x-auto sm:right-4 dark:border-white/[0.08] dark:bg-panel">
            <p class="break-words text-sm font-medium text-black dark:text-white" x-text="title"></p>
            <p class="mt-1 break-words text-sm text-neutral-600 dark:text-fg-dim" x-text="body"></p>
        </div>
    @endif
</div>
