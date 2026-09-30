<div>
    <x-slot:title>{{ data_get_str($project, 'name')->limit(10) }} > Edit | Coolify</x-slot>
    <div class="w-full max-w-none">
        <header class="mb-5">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $project->name }}</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('Project settings') }}</p>
        </header>

        <div class="flex flex-col gap-6">
        <section class="application-settings-section" x-data="{
            preview: null,
            processing: false,
            uploadError: null,
            async prepareIcon(event) {
                const file = event.target.files?.[0];
                if (!file) return;
                this.processing = true;
                this.uploadError = null;

                try {
                    const image = await new Promise((resolve, reject) => {
                        const element = new Image();
                        element.onload = () => resolve(element);
                        element.onerror = reject;
                        element.src = URL.createObjectURL(file);
                    });
                    const cropSize = Math.min(image.naturalWidth, image.naturalHeight);
                    const canvas = document.createElement('canvas');
                    canvas.width = 256;
                    canvas.height = 256;
                    const context = canvas.getContext('2d');
                    context.fillStyle = '#ffffff';
                    context.fillRect(0, 0, 256, 256);
                    context.drawImage(image, (image.naturalWidth - cropSize) / 2, (image.naturalHeight - cropSize) / 2, cropSize, cropSize, 0, 0, 256, 256);
                    const blob = await new Promise((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('JPEG compression failed')), 'image/jpeg', 0.8));
                    const previewUrl = URL.createObjectURL(blob);
                    const compressed = new File([blob], 'project-icon.jpg', { type: 'image/jpeg' });
                    this.$wire.upload('icon', compressed, async () => {
                        const uploaded = await this.$wire.uploadIcon();
                        if (uploaded) {
                            if (this.preview) URL.revokeObjectURL(this.preview);
                            this.preview = previewUrl;
                        } else {
                            URL.revokeObjectURL(previewUrl);
                        }
                        this.processing = false;
                    }, () => {
                        URL.revokeObjectURL(previewUrl);
                        this.processing = false;
                        this.uploadError = 'The image could not be uploaded.';
                    });
                } catch (error) {
                    this.processing = false;
                    this.uploadError = 'The image could not be processed in this browser.';
                }
            },
        }">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Project icon') }}</h2>
                    <p>{{ __('Upload a JPG, PNG, or WebP image. It will appear in the projects list.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body flex items-center gap-4">
                <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                    <img x-cloak x-show="preview" :src="preview" alt="{{ __('Project icon preview') }}" class="h-full w-full object-cover">
                    @if ($project->icon_path)
                        <img x-show="!preview" src="{{ route('project.icon', ['project_uuid' => $project->uuid, 'v' => $project->updated_at->timestamp]) }}"
                            alt="{{ $project->name }} icon" class="h-full w-full object-cover">
                    @else
                        <x-reicon x-show="!preview" name="projects" class="size-6" />
                    @endif
                </div>
                <div class="flex min-w-0 flex-1 flex-col gap-3">
                    <div class="flex flex-wrap gap-2">
                        <input x-ref="iconInput" type="file" x-on:change="prepareIcon($event)" accept="image/jpeg,image/png,image/webp" class="hidden">
                        <x-forms.button type="button" x-on:click="$refs.iconInput.click()" x-bind:disabled="processing">
                            <span x-text="processing ? @js(__('Uploading…')) : @js(__('Browse…'))"></span>
                        </x-forms.button>
                        @if ($project->icon_path)
                            <x-forms.button type="button" wire:click="removeIcon" x-bind:disabled="processing" isError>{{ __('Remove') }}</x-forms.button>
                        @endif
                    </div>
                    <p x-cloak x-show="uploadError" x-text="uploadError" class="text-xs text-red-500"></p>
                    @error('icon') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div>
                        <h2>{{ __('Project details') }}</h2>
                        <p>{{ __('Name and describe this project across the dashboard.') }}</p>
                    </div>
                </div>
                <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                    <x-forms.input label="{{ __('Name') }}" id="name" />
                    <x-forms.input label="{{ __('Description') }}" id="description" />
                </div>
            </section>
        </form>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Odoo') }}</h2>
                    <p>{{ __('Turn this project into an Odoo client. The first staging environment is created empty when none exists. Nothing is deployed.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body flex flex-col gap-4">
                <div class="max-w-sm">
                    <x-forms.listbox canGate="update" :canResource="$project" id="odooVersion" label="{{ __('Odoo version') }}"
                        :disabled="! auth()->user()->can('update', $project)" :options="collect(\App\Support\OdooVersion::SUPPORTED)->map(fn (string $version) => ['value' => $version, 'label' => 'Odoo '.$version])->all()" />
                </div>
                <div class="max-w-sm">
                    <p class="mb-1.5 text-sm font-medium">{{ __('Staging environments') }}</p>
                    <p class="mb-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                        {{ __('Sets how many staging environments this project can have. Each environment can be linked to a different branch later.') }}
                    </p>
                    <x-forms.input canGate="update" :canResource="$project" id="maxStagingEnvironments" type="number" min="0"
                        label="{{ __('Maximum number') }}"
                        :disabled="$unlimitedStagingEnvironments || ! auth()->user()->can('update', $project)" />
                </div>
                <x-forms.checkbox canGate="update" :canResource="$project" id="unlimitedStagingEnvironments" live
                    label="{{ __('Allow unlimited staging environments') }}"
                    helper="{{ __('When this is on, the maximum number is not used.') }}" />
                @if ($unlimitedStagingEnvironments)
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        {{ __('The maximum number is not used while unlimited staging is on.') }}
                    </p>
                @endif
                @if ($project->odooProfile && $odooStagingIsEmpty)
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        {{ __('Staging is empty. No database, filestore, or addons are deployed yet.') }}
                    </p>
                @endif
                <div class="flex flex-wrap gap-2">
                    <x-forms.button type="button" wire:click="enableOdoo" canGate="update" :canResource="$project" isHighlighted>
                        {{ $project->odooProfile ? __('Save Odoo settings') : __('Enable Odoo') }}
                    </x-forms.button>
                    @if ($project->odooProfile)
                        <x-forms.button type="button" wire:click="cloneProductionAsStaging" canGate="update" :canResource="$project"
                            :disabled="! $canCloneProductionToStaging">
                            {{ __('Clone production as staging') }}
                        </x-forms.button>
                    @endif
                </div>
                @if ($project->odooProfile)
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        {{ $canCloneProductionToStaging
                            ? __('Creates one staging environment. It does not create another production.')
                            : __('The staging limit is reached. Raise it before cloning production again.') }}
                    </p>
                @endif
                @if ($project->odooProfile)
                    <div class="flex flex-col gap-4 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                        <div>
                            <p class="text-sm font-medium">{{ __('GitHub') }}</p>
                            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                                {{ __('The environment stays named production or staging. The branch must be the name GitHub uses. main and produccion are not the same branch.') }}
                            </p>
                        </div>
                        @if (! $odooGithubConnected)
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Sign in with GitHub to create the key and connect the webhook for this environment.') }}
                            </p>
                            <div>
                                <a class="button" href="{{ route('source.all') }}">{{ __('Connect GitHub') }}</a>
                            </div>
                        @else
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            {{ __('The connected GitHub account is reused. Its webhook marks the exact branch as updating and restarts Odoo.') }}
                        </p>
                        <div class="grid max-w-xl gap-4 sm:grid-cols-2">
                            <x-forms.listbox canGate="update" :canResource="$project" id="odooGithubAppId" label="{{ __('GitHub App') }}"
                                :disabled="! auth()->user()->can('update', $project)" :options="$odooGithubApps" />
                            <x-forms.listbox canGate="update" :canResource="$project" id="odooRepositoryId" label="{{ __('Repository') }}"
                                :disabled="$odooRepositories === [] || ! auth()->user()->can('update', $project)"
                                :options="collect($odooRepositories)->map(fn (array $repository) => ['value' => $repository['id'], 'label' => $repository['full_name']])->all()" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-forms.button type="button" wire:click="loadOdooRepositories" canGate="update" :canResource="$project">
                                {{ __('Load repositories') }}
                            </x-forms.button>
                            <x-forms.button type="button" wire:click="loadOdooBranches" canGate="update" :canResource="$project">
                                {{ __('Load branches') }}
                            </x-forms.button>
                        </div>
                        @foreach ($odooTrackedEnvironments as $environment)
                            <div wire:key="odoo-branch-{{ $environment['id'] }}" class="max-w-sm">
                                <label class="mb-1.5 block text-sm font-medium">{{ $environment['name'] }}</label>
                                @if (($environment['status'] ?? 'idle') === 'updating')
                                    <p class="mb-2 text-[13px] font-medium text-amber-700 dark:text-amber-300">{{ __('Odoo is updating') }}</p>
                                @elseif (($environment['status'] ?? 'idle') === 'failed')
                                    <p class="mb-2 text-[13px] font-medium text-red-700 dark:text-red-300">{{ __('Odoo update failed') }}</p>
                                @endif
                                <p class="mb-2 text-[13px] text-neutral-500 dark:text-fg-dim">
                                    {{ strcasecmp($environment['name'], 'production') === 0
                                        ? __('Category: production. Choose the GitHub branch for this environment.')
                                        : __('Category: staging. The GitHub branch can be any branch in the repository.') }}
                                </p>
                                @if ($odooGithubBranches !== [])
                                    <select wire:model="odooEnvironmentBranches.{{ $environment['id'] }}" class="input"
                                        @disabled(! auth()->user()->can('update', $project))>
                                        <option value="">{{ __('Choose a GitHub branch') }}</option>
                                        @foreach ($odooGithubBranches as $branch)
                                            <option value="{{ $branch }}">{{ $branch }}</option>
                                        @endforeach
                                    </select>
                                @elseif (filled($odooEnvironmentBranches[$environment['id']] ?? null))
                                    <p class="font-mono text-sm">{{ $odooEnvironmentBranches[$environment['id']] }}</p>
                                @endif
                            </div>
                        @endforeach
                        <div>
                            <x-forms.button type="button" wire:click="saveOdooGit" canGate="update" :canResource="$project" isHighlighted>
                                {{ __('Save GitHub branches') }}
                            </x-forms.button>
                        </div>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        <section
            class="overflow-hidden rounded-[10px] border border-red-300 bg-red-50/80 dark:border-red-500/25 dark:bg-red-500/[0.06]">
            <div class="flex items-start justify-between gap-4 px-5 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-red-800 dark:text-red-300">{{ __('Delete project') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm text-red-700/80 dark:text-red-200/70">
                        {{ __('Empty the project before permanently deleting it.') }}
                    </p>
                </div>
                <livewire:project.delete-project :disabled="! $project->isEmpty()" :project_id="$project->id" />
            </div>
        </section>
        </div>
    </div>
</div>
