<div x-show="environment.enterHref" class="relative inline-flex overflow-hidden rounded-md border border-coollabs-200" x-data="{ menu: false }" @click.stop @click.outside="menu = false">
    <a :href="environment.enterHref" target="_blank" rel="noopener noreferrer"
        class="button button-highlighted h-7 rounded-none border-0 px-2 text-[11px]">{{ __('Open Odoo') }}</a>
    <button type="button" class="button button-highlighted h-7 rounded-none border-0 border-l border-white/30 px-1.5"
        @click="menu = ! menu" aria-label="{{ __('Connect as') }}" aria-haspopup="menu">
        <span aria-hidden="true">▾</span>
    </button>
    <div x-cloak x-show="menu" class="absolute right-0 top-full z-40 mt-1 w-36 overflow-hidden rounded-md border border-neutral-200 bg-white py-1 text-left text-[12px] shadow-lg dark:border-white/10 dark:bg-neutral-900">
        <a class="block px-3 py-1.5 hover:bg-neutral-100 dark:hover:bg-white/5" :href="environment.enterHref" target="_blank" rel="noopener noreferrer" @click="menu = false">{{ __('Open') }}</a>
        <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-neutral-100 dark:hover:bg-white/5"
            @click="menu = false; $wire.openOdooUsers(environment.serviceUuid)">{{ __('Connect as') }}</button>
    </div>
</div>
