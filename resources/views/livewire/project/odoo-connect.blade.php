<div class="relative inline-flex" x-data="{ menu: false }" @click.outside="menu = false">
    <a class="button button-highlighted rounded-r-none" target="_blank" rel="noopener noreferrer" href="{{ $enterUrl }}">
        {{ __('Open Odoo') }}
    </a>
    <button type="button" class="button button-highlighted rounded-l-none border-l border-white/30 px-2"
        x-on:click="menu = ! menu" aria-label="{{ __('Connect as') }}" aria-haspopup="menu">
        <span aria-hidden="true">▾</span>
    </button>
    <div x-cloak x-show="menu" class="absolute right-0 top-full z-30 mt-1 w-40 overflow-hidden rounded-md border border-neutral-200 bg-white py-1 text-sm shadow-lg dark:border-white/10 dark:bg-neutral-900">
        <a class="block px-3 py-2 hover:bg-neutral-100 dark:hover:bg-white/5" target="_blank" rel="noopener noreferrer"
            href="{{ $enterUrl }}" x-on:click="menu = false">{{ __('Open') }}</a>
        <button type="button" class="block w-full px-3 py-2 text-left hover:bg-neutral-100 dark:hover:bg-white/5"
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
