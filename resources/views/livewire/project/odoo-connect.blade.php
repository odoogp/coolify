<div class="relative inline-flex" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape.window="menu = false">
    <a class="button button-highlighted" target="_blank" rel="noopener noreferrer" href="{{ $enterUrl }}"
        style="border-top-right-radius:0;border-bottom-right-radius:0;border-right-width:0">
        {{ __('Open Odoo') }}
    </a>
    <button type="button" class="button button-highlighted" x-on:click="menu = ! menu" :aria-expanded="menu"
        aria-label="{{ __('Connect as') }}" aria-haspopup="menu"
        style="border-top-left-radius:0;border-bottom-left-radius:0;padding-left:0.4rem;padding-right:0.45rem">
        <x-reicon name="chevron-down" class="size-3 opacity-70" />
    </button>
    <div x-cloak x-show="menu" x-transition.origin.top.right class="listbox-panel top-full! right-0! left-auto! mt-1! w-44! min-w-0!" role="menu">
        <a class="listbox-option justify-start!" target="_blank" rel="noopener noreferrer" role="menuitem"
            href="{{ $enterUrl }}" x-on:click="menu = false">{{ __('Open') }}</a>
        <button type="button" class="listbox-option justify-start!" role="menuitem"
            wire:click="connectAs" x-on:click="menu = false">{{ __('Connect as') }}</button>
    </div>
    @if ($open)
        <div class="fixed inset-0 z-[120] flex items-center justify-center bg-black/50 p-4" wire:click="$set('open', false)">
            <div wire:click.stop class="w-full max-w-lg rounded-xl border border-neutral-200 bg-white p-5 dark:border-white/10 dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">{{ __('Internal Users') }} ({{ count($users) }})</h2>
                    <button type="button" class="text-sm" wire:click="$set('open', false)">{{ __('Close') }}</button>
                </div>
                @forelse ($users as $user)
                    <div class="flex items-center justify-between gap-3 border-t border-neutral-200 py-3 text-sm dark:border-white/10" wire:key="odoo-user-{{ $user['login'] }}">
                        <span>
                            <span class="font-medium">{{ $user['name'] }}</span>
                            <span class="ml-3 font-mono text-neutral-500">{{ $user['login'] }}</span>
                        </span>
                        <a class="font-medium text-emerald-700 dark:text-emerald-300" target="_blank" rel="noopener noreferrer"
                            href="{{ $enterUrl }}?login={{ urlencode($user['login']) }}">{{ __('Connect') }}</a>
                    </div>
                @empty
                    <p class="text-sm text-neutral-500">{{ __('Odoo has no internal users yet, or it is still starting.') }}</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
