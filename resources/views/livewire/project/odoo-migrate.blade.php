<div>
    <x-slot:title>
        {{ __('Migrate') }} | {{ data_get_str($project, 'name')->limit(10) }} | {{ product_name() }}
    </x-slot>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Migrate') }}</h1>
            <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                {{ __('Choose an existing environment or create a new one, connect GitHub or upload a modules zip, then upload the database dump and filestore. We restore both onto the selected environment.') }}
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
        <x-application.settings-section :title="__('1. Target environment')">
            <x-forms.listbox id="environmentId" portal label="{{ __('Restore into') }}"
                :options="$environmentChoices"
                helper="{{ __('Pick production, an existing staging, or a brand-new environment. If Odoo is missing there, we create and start it before restoring.') }}" />
        </x-application.settings-section>

        <x-application.settings-section :title="__('2. GitHub (optional)')">
            @if ($githubReady)
                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('GitHub is connected on this team.') }}</p>
            @else
                <p class="mb-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Connect the GitHub account that holds the Odoo addons repository. Skip this if you will upload a zip of modules instead.') }}
                </p>
                <x-forms.button type="button" wire:click="connectGithub" isHighlighted>{{ __('Connect GitHub') }}</x-forms.button>
            @endif
        </x-application.settings-section>

        <x-application.settings-section :title="__('3. Repository or modules zip')">
            <form class="flex flex-col gap-3" wire:submit="saveRepository">
                <x-forms.input id="gitRepository" label="{{ __('Repository') }}"
                    helper="{{ __('owner/name when the addons live on GitHub. Leave empty if you upload a zip below.') }}"
                    placeholder="owner/odoo-addons" />
                <x-forms.button type="submit">{{ __('Save repository') }}</x-forms.button>
            </form>
            <p class="mt-4 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('No GitHub modules? Upload a .zip (or .tar.gz) of the custom addons. We unpack it into /mnt/extra-addons.') }}
            </p>
            @if ($migration?->addons_original_name)
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('Modules zip') }}: {{ $migration->addons_original_name }}</p>
            @endif
        </x-application.settings-section>

        <x-application.settings-section :title="__('4. Dump, filestore, and optional modules zip')">
            <p class="mb-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Upload a database dump and a filestore archive. Add the modules zip when they are not in GitHub.') }}
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
                <div>
                    <label class="mb-1.5 block text-sm font-medium" for="addonsZip">{{ __('Modules zip (optional)') }}</label>
                    <input id="addonsZip" type="file" class="input w-full" wire:model="addonsZip" accept=".zip,.tar,.gz,.tgz">
                    @error('addonsZip')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                </div>
                <x-forms.button type="submit" wire:loading.attr="disabled">{{ __('Upload files') }}</x-forms.button>
            </form>
        </x-application.settings-section>

        <x-application.settings-section :title="__('5. Restore')">
            <p class="mb-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('A new environment gets a fresh Odoo stack first. Restoring onto an environment that already has data overwrites its database and filestore.') }}
            </p>
            <x-forms.button type="button" wire:click="start" isHighlighted
                wire:confirm="{{ __('Start migration onto the selected environment? Existing database and filestore data there will be overwritten.') }}">
                {{ __('Start migration') }}
            </x-forms.button>
        </x-application.settings-section>

        @if ($plan)
            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                {{ __('Plan') }}: {{ $plan->name }}
            </p>
        @endif
    </div>
</div>
