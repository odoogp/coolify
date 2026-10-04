<div x-show="environment.enterHref" class="relative inline-flex" x-data="{ menu: false }" @click.stop @click.outside="menu = false" @keydown.escape.window="menu = false">
    <a :href="environment.enterHref" target="_blank" rel="noopener noreferrer" class="button button-highlighted"
        style="height:1.75rem;min-height:1.75rem;border-top-right-radius:0;border-bottom-right-radius:0;border-right-width:0;padding-left:0.5rem;padding-right:0.5rem;font-size:11px">{{ __('Open Odoo') }}</a>
    <button type="button" class="button button-highlighted" @click="menu = ! menu" :aria-expanded="menu"
        aria-label="{{ __('Connect as') }}" aria-haspopup="menu"
        style="height:1.75rem;min-height:1.75rem;border-top-left-radius:0;border-bottom-left-radius:0;padding-left:0.3rem;padding-right:0.35rem">
        <x-reicon name="chevron-down" class="size-3 opacity-70" />
    </button>
    <div x-cloak x-show="menu" x-transition.origin.top.right class="listbox-panel top-full! right-0! left-auto! mt-1! w-40! min-w-0!" role="menu">
        <a class="listbox-option justify-start!" :href="environment.enterHref" target="_blank" rel="noopener noreferrer" role="menuitem" @click="menu = false">{{ __('Open') }}</a>
        <button type="button" class="listbox-option justify-start!" role="menuitem"
            @click="menu = false; $wire.openOdooUsers(environment.serviceUuid)">{{ __('Connect as') }}</button>
    </div>
</div>
