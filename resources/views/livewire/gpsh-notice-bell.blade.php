<div class="relative" x-data="{ open: false }" @click.outside="open = false" wire:poll.30s>
    <button type="button" class="relative flex size-8 items-center justify-center rounded-lg text-neutral-500 hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-white"
        x-on:click="open = !open" aria-label="{{ __('Notices') }}">
        <x-reicon name="notifications" class="size-4" />
        @if ($unread > 0)
            <span class="absolute right-1 top-1 size-2 rounded-full bg-coollabs dark:bg-warning"></span>
        @endif
    </button>
    <div x-cloak x-show="open"
        class="absolute right-0 z-[60] mt-2 w-80 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg dark:border-white/[0.08] dark:bg-panel">
        <div class="flex items-center justify-between border-b border-neutral-200 px-3 py-2 dark:border-white/[0.06]">
            <span class="text-[13px] font-medium">{{ __('Notices') }}</span>
            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" class="text-[12px] text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-white">
                    {{ __('Mark as read') }}
                </button>
            @endif
        </div>
        <div class="max-h-96 overflow-y-auto">
            @forelse ($notices as $notice)
                <button type="button" wire:click="markRead({{ $notice->id }})"
                    class="block w-full border-b border-neutral-100 px-3 py-2.5 text-left last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.04] dark:hover:bg-white/[0.03]">
                    <span class="flex items-center gap-2 text-[11px] text-neutral-500 dark:text-fg-faint">
                        <span>{{ $notice->kindLabel() }}</span>
                        <span>{{ $notice->audienceLabel() }}</span>
                    </span>
                    <span class="mt-0.5 block text-[13px] font-medium text-black dark:text-white">{{ $notice->title }}</span>
                    <span class="mt-0.5 block text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">{{ str($notice->body)->limit(140) }}</span>
                </button>
            @empty
                <p class="px-3 py-6 text-center text-[13px] text-neutral-500 dark:text-fg-faint">{{ __('No notices') }}</p>
            @endforelse
        </div>
        @if (isInstanceOwner())
            <a href="{{ route('notifications.center') }}" {{ wireNavigate() }}
                class="block border-t border-neutral-200 px-3 py-2 text-center text-[12px] font-medium text-black hover:bg-neutral-50 dark:border-white/[0.06] dark:text-white dark:hover:bg-white/[0.03]">
                {{ __('Notification center') }}
            </a>
        @endif
    </div>
</div>
