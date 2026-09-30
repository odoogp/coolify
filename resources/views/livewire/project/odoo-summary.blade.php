<div>
    @if ($rows !== [])
        <section class="mb-5 overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.08]">
            <div class="flex flex-col lg:flex-row">
                <aside class="shrink-0 border-b border-neutral-200 p-3 lg:w-64 lg:border-r lg:border-b-0 dark:border-white/[0.08]">
                    <p class="px-2 text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-faint">{{ __('Production') }}</p>
                    @foreach ($rows as $row)
                        @continue($row['staging'])
                        <div wire:key="odoo-side-{{ $row['id'] }}" class="mt-1 rounded-md px-2 py-1.5 text-[13px]">
                            <p class="font-medium">{{ $row['name'] }}</p>
                            <p class="font-mono text-[12px] text-neutral-500 dark:text-fg-dim">{{ $row['branch'] ?: __('JupyterLab') }}</p>
                        </div>
                    @endforeach
                    <p class="mt-4 px-2 text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-faint">{{ __('Staging') }}</p>
                    @foreach ($rows as $row)
                        @continue(! $row['staging'])
                        <div wire:key="odoo-side-{{ $row['id'] }}" class="mt-1 rounded-md px-2 py-1.5 text-[13px]">
                            <p class="font-medium">{{ $row['name'] }}</p>
                            <p class="font-mono text-[12px] text-neutral-500 dark:text-fg-dim">{{ $row['branch'] ?: __('JupyterLab') }}</p>
                        </div>
                    @endforeach
                </aside>
                <div class="flex min-w-0 flex-1 flex-col gap-3 p-4">
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        {{ __('Each environment is a branch when a repository is associated. Without one, JupyterLab shows the same addon folder.') }}
                    </p>
                    @foreach ($rows as $row)
                        <div class="rounded-md border border-neutral-200 px-3 py-2 text-[13px] dark:border-white/[0.08]" wire:key="odoo-env-{{ $row['id'] }}">
                            <div class="flex items-start justify-between gap-3">
                                <p class="font-medium">{{ $row['name'] }}</p>
                                @if ($row['href'])
                                    <a class="button" href="{{ $row['href'] }}">{{ __('Associate repository') }}</a>
                                @endif
                            </div>
                            <p class="text-neutral-500 dark:text-fg-dim">
                                {{ $row['branch'] ?: __('JupyterLab') }}
                                · {{ __('Odoo version') }} {{ $row['version'] ?: '—' }}
                                @if ($row['status'])
                                    · {{ $row['status'] }}
                                @endif
                            </p>
                            @if ($canUpdate)
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <x-forms.button type="button" wire:click="deploy({{ $row['id'] }})">{{ __('Deploy') }}</x-forms.button>
                                    <x-forms.button type="button" wire:click="backup({{ $row['id'] }})">{{ __('Backup') }}</x-forms.button>
                                    @if ($row['staging'])
                                        <x-forms.button type="button" wire:click="syncStaging({{ $row['id'] }})">{{ __('Sync branch') }}</x-forms.button>
                                        <x-forms.button type="button" wire:click="cloneData({{ $row['id'] }})">{{ __('Clone data') }}</x-forms.button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
