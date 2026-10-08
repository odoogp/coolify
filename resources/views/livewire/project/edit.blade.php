<div>
    <x-slot:title>{{ data_get_str($project, 'name')->limit(10) }} > Edit | {{ product_name() }}</x-slot>
    <div class="w-full max-w-none">
        <header class="mb-5">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $project->name }}</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Project settings') }}
                @if (filled($planName))
                    · {{ __('Plan') }}: {{ $planName }}
                @endif
            </p>
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
                    <div class="sm:col-span-2">
                        <p class="mb-1 text-[13px] font-medium text-neutral-700 dark:text-fg">{{ __('Plan') }}</p>
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            {{ $planName ?? __('No plan') }}
                            @if (filled($planBackupPolicy))
                                · {{ $planBackupPolicy }}
                            @endif
                        </p>
                        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                            {{ __('The plan is set when the team signs up. Contact an advisor to change it.') }}
                        </p>
                    </div>
                </div>
            </section>
        </form>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Odoo') }}</h2>
                    <p>{{ __('GitHub is optional. Without it, JupyterLab stays on and shows the addon files. Connecting later creates a repository named after the project, and each environment becomes a branch of that same folder.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body flex flex-col gap-4">
                @unless ($project->odooProfile)
                    <div class="max-w-sm">
                        <x-forms.listbox canGate="update" :canResource="$project" id="odooVersion" label="{{ __('Odoo version') }}"
                            :disabled="! auth()->user()->can('update', $project)" :options="collect(\App\Domain\Odoo\OdooVersion::SUPPORTED)->map(fn (string $version) => ['value' => $version, 'label' => 'Odoo '.$version])->all()" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-forms.button type="button" wire:click="enableOdoo" canGate="update" :canResource="$project" isHighlighted>
                            {{ __('Enable Odoo') }}
                        </x-forms.button>
                    </div>
                @endunless
                @if ($project->odooProfile)
                    <div class="flex flex-col gap-4 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            {{ __('This project runs Odoo :version. Staging uses this same version.', ['version' => $project->odooProfile->odoo_version]) }}
                        </p>
                        @php
                            $baseDomain = \App\Support\OdooGit::baseDomain();
                            $productionHost = \App\Support\OdooGit::projectHost($odooSubdomain, $baseDomain, 'production');
                            $stagingEnvironment = $project->environments->first(fn ($environment) => strcasecmp($environment->name, 'production') !== 0);
                            $stagingHost = $stagingEnvironment
                                ? \App\Support\OdooGit::projectHost($odooSubdomain, $baseDomain, $stagingEnvironment->name, $stagingEnvironment->id)
                                : '';
                        @endphp
                        <form wire:submit="saveOdooRuntime" class="flex flex-col gap-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input canGate="update" :canResource="$project" id="odooSubdomain" label="{{ __('Subdomain') }}"
                                    helper="{{ __('Leave this empty to use the address Coolify generates. Saving a new address restarts Odoo so the proxy and SSL apply.') }}"
                                    placeholder="arielmim97-20demo" />
                                <x-forms.input canGate="update" :canResource="$project" id="odooWorkers" type="number" min="0" max="32"
                                    label="{{ __('Workers') }}"
                                    helper="{{ __('Zero is the default. Odoo reads this number the next time it starts. Saving does not restart it.') }}" />
                            </div>
                            @if ($baseDomain === '')
                                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('Set the Odoo domain in Settings before this project gets its own address.') }}</p>
                            @else
                                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                    {{ __('Production address') }}:
                                    <span class="font-mono">{{ $productionHost !== '' ? $productionHost : '____.'.$baseDomain }}</span>
                                </p>
                                @if ($stagingHost !== '')
                                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                        {{ __('Staging address') }}:
                                        <span class="font-mono">{{ $stagingHost }}</span>
                                    </p>
                                @endif
                            @endif
                            <div>
                                <x-forms.button type="submit" canGate="update" :canResource="$project">{{ __('Save') }}</x-forms.button>
                            </div>
                        </form>
                        @if (filled($project->odooProfile->git_repository))
                            <form wire:submit="inviteGithubUser" class="flex flex-col gap-3 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                <p class="text-sm font-medium">{{ __('Collaborators') }}</p>
                                <div class="flex max-w-xl items-end gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.input canGate="update" :canResource="$project" id="githubLogin" label="{{ __('GitHub username') }}"
                                            placeholder="ronaldleongg" />
                                    </div>
                                    <x-forms.button type="submit" canGate="update" :canResource="$project">{{ __('Invite') }}</x-forms.button>
                                </div>
                                <ul class="max-w-xl divide-y divide-neutral-200 dark:divide-white/10">
                                    @forelse ($odooCollaborators as $collaborator)
                                        <li class="flex items-center justify-between gap-3 py-2 text-sm" wire:key="github-collaborator-{{ $collaborator['login'] }}">
                                            <span class="font-mono">{{ $collaborator['login'] }}</span>
                                            <button type="button" class="text-red-500" wire:click="removeGithubUser('{{ $collaborator['login'] }}')">{{ __('Remove') }}</button>
                                        </li>
                                    @empty
                                        <li class="py-2 text-sm text-neutral-500">{{ __('No collaborators yet.') }}</li>
                                    @endforelse
                                </ul>
                            </form>
                        @endif
                        @if (! $odooGithubConnected)
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Connect your GitHub account') }}
                                {{ __('You can skip this. JupyterLab is the file manager until you connect.') }}
                            </p>
                            <div>
                                <x-forms.button type="button" wire:click="connectOdooGithub" canGate="update" :canResource="$project">
                                    {{ __('Connect GitHub') }}
                                </x-forms.button>
                            </div>
                        @else
                            <p class="text-[13px] font-medium text-emerald-700 dark:text-emerald-300">
                                {{ __('Connected to GitHub') }}
                            </p>
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                @if (filled($project->odooProfile->git_repository))
                                    {{ __('Repository') }}: <span class="font-mono">{{ $project->odooProfile->git_repository }}</span>
                                @else
                                    {{ __('The first launch creates the GitHub repository. The branch name is the environment name.') }}
                                @endif
                            </p>
                            @if (count($odooGithubApps) > 1)
                                <div class="max-w-sm">
                                    <x-forms.listbox canGate="update" :canResource="$project" id="odooGithubAppId" label="{{ __('GitHub account') }}"
                                        :disabled="! auth()->user()->can('update', $project)" :options="$odooGithubApps" />
                                </div>
                            @endif
                        @endif
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            {{ __('Addon files are one folder. Odoo reads /mnt/extra-addons. JupyterLab opens the same folder at /workspace/addons.') }}
                        </p>
                        <div class="max-w-sm">
                            <label class="mb-1.5 block text-sm font-medium" for="odoo-launch-category">{{ __('Category') }}</label>
                            <select id="odoo-launch-category" wire:model="odooLaunchCategory" class="input"
                                @disabled(! auth()->user()->can('update', $project))>
                                <option value="production">{{ __('Production') }}</option>
                                <option value="staging">{{ __('Staging') }}</option>
                            </select>
                        </div>
                        <div>
                            <x-forms.button type="button" wire:click="launchOdooEnvironment" canGate="update" :canResource="$project" isHighlighted>
                                {{ __('Launch environment') }}
                            </x-forms.button>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-sm font-medium">{{ __('Production') }}</p>
                                @foreach ($odooTrackedEnvironments as $environment)
                                    @continue(strcasecmp($environment['name'], 'production') !== 0)
                                    <p wire:key="odoo-panel-{{ $environment['id'] }}" class="mt-2 font-mono text-sm">
                                        {{ $odooEnvironmentBranches[$environment['id']] ?? __('JupyterLab') }}
                                    </p>
                                @endforeach
                            </div>
                            <div>
                                <p class="text-sm font-medium">{{ __('Staging') }}</p>
                                @foreach ($odooTrackedEnvironments as $environment)
                                    @continue(strcasecmp($environment['name'], 'production') === 0)
                                    <p wire:key="odoo-panel-{{ $environment['id'] }}" class="mt-2 text-sm">
                                        <span class="font-medium">{{ $environment['name'] }}</span>
                                        <span class="font-mono">{{ $odooEnvironmentBranches[$environment['id']] ?? __('JupyterLab') }}</span>
                                    </p>
                                @endforeach
                            </div>
                        </div>
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
