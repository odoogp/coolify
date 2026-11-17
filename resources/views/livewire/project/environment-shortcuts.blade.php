<a x-show="environment.jupyterHref" x-cloak :href="environment.jupyterHref" target="_blank"
    rel="noopener noreferrer" @click.stop
    class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
    aria-label="{{ __('Editor') }}">
    <span class="flex size-7 shrink-0 items-center justify-center">
        <x-reicon name="code" class="size-3.5" />
    </span>
    <span
        class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Editor') }}</span>
</a>
<a x-show="environment.monitorHref" x-cloak :href="environment.monitorHref" target="_blank"
    rel="noopener noreferrer" @click.stop
    class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
    aria-label="{{ __('Monitor') }}">
    <span class="flex size-7 shrink-0 items-center justify-center">
        <x-reicon name="dashboard" class="size-3.5" />
    </span>
    <span
        class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Monitor') }}</span>
</a>
<a x-show="environment.logsHref" x-cloak :href="environment.logsHref" {{ wireNavigate() }} @click.stop
    class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
    aria-label="{{ __('Logs') }}">
    <span class="flex size-7 shrink-0 items-center justify-center">
        <x-reicon name="file-content" class="size-3.5" />
    </span>
    <span
        class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Logs') }}</span>
</a>
<a x-show="environment.terminalHref" x-cloak :href="environment.terminalHref" {{ wireNavigate() }} @click.stop
    class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
    aria-label="{{ __('Terminal') }}">
    <span class="flex size-7 shrink-0 items-center justify-center">
        <svg viewBox="0 0 24 24" class="size-4" aria-hidden="true">
            <rect width="24" height="24" rx="6" fill="currentColor" />
            <path d="M6.75 8.25 10.75 12 6.75 15.75" fill="none" class="stroke-white dark:stroke-neutral-950"
                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M12.5 16.25h5" fill="none" class="stroke-white dark:stroke-neutral-950" stroke-width="1.8"
                stroke-linecap="round" />
        </svg>
    </span>
    <span
        class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Terminal') }}</span>
</a>
