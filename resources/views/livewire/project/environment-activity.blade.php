<div x-show="environment.activity" x-cloak class="flex min-w-0 items-center justify-end gap-2" @click.stop>
    <svg x-show="environment.activity && environment.activity.running"
        class="size-3.5 shrink-0 animate-spin text-neutral-500 dark:text-fg-dim" xmlns="http://www.w3.org/2000/svg"
        fill="none" viewBox="0 0 24 24" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
        </path>
    </svg>
    <span x-show="environment.activity && environment.activity.running"
        x-data="{ shown: '', at: 0, pending: '', timer: null }"
        x-effect="pending = (environment.activity && environment.activity.message) || ''; if (!pending) return; if (!shown) { shown = pending; at = Date.now(); return } if (pending === shown) return; const wait = 20000 - (Date.now() - at); if (wait <= 0) { shown = pending; at = Date.now(); return } if (timer) return; timer = setTimeout(() => { timer = null; if (pending && pending !== shown) { shown = pending; at = Date.now() } }, wait)"
        class="max-w-48 truncate text-[11px] normal-case text-neutral-500 dark:text-fg-dim"
        x-text="shown || (environment.activity && environment.activity.message) || ''"></span>
    <a x-show="environment.enterHref" :href="environment.enterHref" target="_blank" @click.stop
        class="button button-highlighted h-7 shrink-0 px-2 text-[11px] normal-case">{{ __('Open Odoo') }}</a>
    <button type="button" x-show="environment.activity && environment.activity.error"
        class="flex size-6 shrink-0 items-center justify-center rounded-full border border-red-500/40 text-[13px] font-bold text-red-500"
        @click.stop="openError = openError === environment.uuid ? null : environment.uuid"
        aria-label="{{ __('Error') }}">!</button>
    <span x-show="openError === environment.uuid && environment.activity" class="max-w-xs text-[11px] text-red-500"
        x-text="environment.activity ? environment.activity.error : ''"></span>
</div>
