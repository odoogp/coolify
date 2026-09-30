<div>
    @if ($rows !== [])
        <section class="mb-5 rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
            <h2 class="text-sm font-medium">{{ __('Odoo') }}</h2>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Each environment has its own service, domain, and addon volume. Deploy history uses the same statuses as any other deployment.') }}
            </p>
            <div class="mt-3 flex flex-col gap-3">
                @foreach ($rows as $row)
                    <div class="rounded-md border border-neutral-200 px-3 py-2 text-[13px] dark:border-white/[0.08]" wire:key="odoo-env-{{ $row['id'] }}">
                        <p class="font-medium">{{ $row['name'] }}</p>
                        <p class="text-neutral-500 dark:text-fg-dim">
                            {{ $row['domain'] ?: __('No domain yet') }}
                            · {{ $row['branch'] ?: __('No branch yet') }}
                            · {{ __('Odoo version') }} {{ $row['version'] ?: '—' }}
                            · {{ __('Workers') }} {{ $row['workers'] ?? 0 }}
                            · {{ $row['addons_path'] ?: '/mnt/extra-addons' }}
                            @if ($row['jupyter'])
                                · Jupyter
                            @endif
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
        </section>
    @endif
</div>
