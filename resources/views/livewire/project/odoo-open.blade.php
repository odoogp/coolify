<div x-show="environment.enterHref" class="relative inline-flex" x-data="{
    menu: false,
    panelStyle: 'position: fixed; visibility: hidden;',
    toggle() {
        if (this.menu) {
            this.menu = false;
            return;
        }
        this.panelStyle = 'position: fixed; visibility: hidden;';
        this.menu = true;
        this.$nextTick(() => this.place());
    },
    place() {
        const trigger = this.$root.getBoundingClientRect();
        const panel = this.$refs.panel.getBoundingClientRect();
        const pad = 8;
        let left = trigger.left;
        if (left + panel.width + pad > window.innerWidth) {
            left = window.innerWidth - panel.width - pad;
        }
        left = Math.max(pad, left);
        const below = window.innerHeight - trigger.bottom - pad;
        const top = below >= panel.height
            ? trigger.bottom + 4
            : Math.max(pad, trigger.top - panel.height - 4);
        this.panelStyle = `position: fixed; left: ${left}px; top: ${top}px;`;
    },
}" @click.stop @click.outside="menu = false" @keydown.escape.window="menu = false"
    x-on:resize.window="if (menu) place()" x-on:scroll.window="if (menu) place()">
    <a :href="environment.enterHref" target="_blank" rel="noopener noreferrer" class="button button-highlighted"
        style="height:1.75rem;min-height:1.75rem;border-top-right-radius:0;border-bottom-right-radius:0;border-right-width:0;padding-left:0.5rem;padding-right:0.5rem;font-size:11px">{{ __('Open Odoo') }}</a>
    <button type="button" class="button button-highlighted" @click="toggle()" :aria-expanded="menu"
        aria-label="{{ __('Connect as') }}" aria-haspopup="menu"
        style="height:1.75rem;min-height:1.75rem;border-top-left-radius:0;border-bottom-left-radius:0;padding-left:0.3rem;padding-right:0.35rem">
        <x-reicon name="chevron-down" class="size-3 opacity-70" />
    </button>
    <div x-ref="panel" x-cloak x-show="menu" :style="panelStyle"
        class="listbox-panel fixed! right-auto! bottom-auto! z-[90]! mt-0! w-44! min-w-0!" role="menu">
        <a class="listbox-option justify-start!" :href="environment.enterHref" target="_blank" rel="noopener noreferrer" role="menuitem" @click="menu = false">{{ __('Open') }}</a>
        <button type="button" class="listbox-option justify-start!" role="menuitem"
            @click="menu = false; $wire.openOdooUsers(environment.serviceUuid)">{{ __('Connect as') }}</button>
    </div>
</div>
