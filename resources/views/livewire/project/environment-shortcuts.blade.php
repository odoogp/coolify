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
