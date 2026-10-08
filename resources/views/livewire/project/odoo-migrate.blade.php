<div>
    <x-slot:title>
        {{ __('Migrate') }} | {{ data_get_str($project, 'name')->limit(10) }} | {{ product_name() }}
    </x-slot>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Migrate from Odoo.sh') }}</h1>
            <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                {{ __('Connect GitHub, point at the repository, then upload the database dump and the filestore archive. We restore both onto production together.') }}
            </p>
        </div>
        <a href="{{ route('project.show', ['project_uuid' => $project->uuid]) }}" class="button" {{ wireNavigate() }}>
            {{ __('Back to project') }}
        </a>
    </div>

    @if ($migration)
        <p class="mb-4 text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ __('Status') }}: <span class="font-medium text-neutral-800 dark:text-fg">{{ $migration->statusLabel() }}</span>
            @if (filled($migration->error))
                · <span class="text-red-500">{{ $migration->error }}</span>
            @endif
        </p>
    @endif

    <div class="flex flex-col gap-6">
        <x-application.settings-section :title="__('1. GitHub')">
            @if ($githubReady)
                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('GitHub is connected on this team.') }}</p>
            @else
                <p class="mb-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Connect the GitHub account that holds the Odoo addons repository.') }}
                </p>
                <x-forms.button type="button" wire:click="connectGithub" isHighlighted>{{ __('Connect GitHub') }}</x-forms.button>
            @endif
        </x-application.settings-section>

        <x-application.settings-section :title="__('2. Repository')">
            <form class="flex flex-col gap-3" wire:submit="saveRepository">
                <x-forms.input id="gitRepository" label="{{ __('Repository') }}"
                    helper="{{ __('owner/name, for example my-org/odoo-addons. Optional if you only restore data.') }}"
                    placeholder="owner/odoo-addons" />
                <x-forms.button type="submit">{{ __('Save repository') }}</x-forms.button>
            </form>
        </x-application.settings-section>

        <x-application.settings-section :title="__('3. Dump and filestore')">
            <p class="mb-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Same pair Odoo.sh uses: a database dump (.sql / .dump / .gz) and a filestore archive (.tar.gz / .zip).') }}
            </p>
            <form class="flex flex-col gap-3" wire:submit="uploadFiles">
                <div>
                    <label class="mb-1.5 block text-sm font-medium" for="databaseDump">{{ __('Database dump') }}</label>
                    <input id="databaseDump" type="file" class="input w-full" wire:model="databaseDump">
                    @error('databaseDump')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                    @if ($migration?->database_original_name)
                        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ $migration->database_original_name }}</p>
                    @endif
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium" for="filestoreArchive">{{ __('Filestore archive') }}</label>
                    <input id="filestoreArchive" type="file" class="input w-full" wire:model="filestoreArchive">
                    @error('filestoreArchive')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                    @if ($migration?->filestore_original_name)
                        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ $migration->filestore_original_name }}</p>
                    @endif
                </div>
                <x-forms.button type="submit" wire:loading.attr="disabled">{{ __('Upload files') }}</x-forms.button>
            </form>
        </x-application.settings-section>

        <x-application.settings-section :title="__('4. Restore')">
            <p class="mb-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Production must already have an Odoo instance. The restore writes the dump and filestore, then reclones addons if a repository is set.') }}
            </p>
            <x-forms.button type="button" wire:click="start" isHighlighted
                wire:confirm="{{ __('Restore dump and filestore onto production? This overwrites the current database and filestore.') }}">
                {{ __('Start migration') }}
            </x-forms.button>
        </x-application.settings-section>

        @if ($plan)
            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                {{ __('Plan') }}: {{ $plan->name }}
                · {{ __('Backups') }}: {{ \App\Support\GetOdooBackupFrequency::label((string) ($plan->backup_frequency ?: 'daily')) }}
            </p>
        @endif
    </div>
</div>
