<div @if ($workRunning) wire:poll.2s="refreshCloneProgress" @endif>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Environments | Coolify
    </x-slot>
    <div x-data="projectEnvironments()" class="w-full">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $project->name }}</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    <span
                        x-text="`${environments.length} ${environments.length === 1 ? 'environment' : 'environments'}`"></span>
                    in this project
                </p>
            </div>

            <div class="flex w-fit shrink-0 items-center gap-2">
            @can('update', $project)
                    <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                        {{ wireNavigate() }}
                        class="button"
                        title="{{ __('Project settings') }}"
                        aria-label="Open settings for {{ $project->name }}">
                        <x-reicon name="settings" class="size-3.5" />
                        {{ __('Settings') }}
                    </a>

                    @if (! $project->odooProfile)
                    <x-modal-input title="{{ __('New Environment') }}">
                        <x-slot:content>
                            <button type="button"
                                class="button button-highlighted">
                                <x-reicon name="plus" class="size-3.5" />
                                {{ __('New environment') }}
                            </button>
                        </x-slot:content>

                        <form class="space-y-4" wire:submit="submit">
                            <x-creation-quota :quota="$creationQuota" />
                            <x-forms.input placeholder="{{ __('staging') }}" id="name" label="{{ __('Name') }}" required />

                            <footer
                                class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                <x-forms.button type="submit"
                                    defaultClass="button button-highlighted">
                                    {{ __('Create environment') }}
                                </x-forms.button>
                            </footer>
                        </form>
                    </x-modal-input>
                    @endif
            @endcan
                </div>
        </header>

        @if ($project->environments->isEmpty())
            <x-empty title="{{ __('No environments yet') }}"
                description="{{ __('Add an environment to start organizing this project\'s resources.') }}"
                icon-name="layers" />
        @else
            <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" x-on:input="page = 1" type="search"
                        placeholder="{{ __('Search environments') }}"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button x-cloak x-show="search" x-on:click="search = ''; page = 1" type="button"
                        class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                        aria-label="{{ __('Clear search') }}">
                        <span class="text-sm leading-none">×</span>
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <x-table.dropdown panel-class="w-48!">
                        <x-slot:trigger>
                            <button type="button" class="button" aria-haspopup="listbox" :aria-expanded="open">
                            <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <path d="M8 5v14m0 0-3-3m3 3 3-3M16 19V5m0 0-3 3m3-3 3 3"
                                    stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            {{ __('Sort') }}
                            </button>
                        </x-slot:trigger>
                            <template x-for="option in sortOptions" :key="option.value">
                                <button type="button"
                                    class="flex h-9 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                    x-on:click="sortBy = option.value; close(); page = 1">
                                    <span class="flex-1" x-text="option.label"></span>
                                    <svg x-show="sortBy === option.value" class="size-3.5 text-warning"
                                        viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor"
                                            stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </template>
                    </x-table.dropdown>

                    <div
                        class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.06]">
                        <button type="button" x-on:click="setViewMode('table')"
                            class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'table'
                                ?
                                'control-selected' :
                                'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="{{ __('Table view') }}" title="{{ __('Table view') }}">
                            <x-reicon name="unordered-list" class="size-3.5" />
                        </button>
                        <button type="button" x-on:click="setViewMode('grid')"
                            class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'grid'
                                ?
                                'control-selected' :
                                'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="{{ __('Grid view') }}" title="{{ __('Grid view') }}">
                            <x-reicon name="grid" class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            @if ($selectedEnvironment)
                <div
                    class="mb-3 flex flex-col gap-3 rounded-xl border border-neutral-200 bg-white p-3 dark:border-white/[0.08] dark:bg-white/[0.025] sm:flex-row sm:items-center sm:justify-between">
                    <p class="truncate text-[13px] font-semibold">{{ __('Selected: :name', ['name' => $selectedEnvironment->name]) }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        @php
                            $selectedOdoo = $project->odooProfile
                                ? $selectedEnvironment->services->first(fn ($service) => $service->supportsOdooJupyter())
                                : null;
                            $openOdooUrl = $selectedOdoo instanceof \App\Models\Service && \App\Support\OdooGit::odooIsUp($selectedOdoo)
                                ? \App\Support\OdooGit::enterUrl($selectedOdoo)
                                : '';
                        @endphp
                        @unless (($activities[$selectedEnvironment->uuid] ?? null) || $cloneRunning)
                        @if ($project->odooProfile)
                            @can('update', $project)
                                @if ($selectedOdoo && strcasecmp($selectedEnvironment->name, 'production') === 0)
                                    <button type="button" class="button button-highlighted" wire:click="openCloneWizard">
                                        {{ __('Clone') }}
                                    </button>
                                @endif
                            @endcan
                            <a class="button button-highlighted" {{ wireNavigate() }}
                                href="{{ $selectedOdoo
                                    ? route('project.service.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $selectedEnvironment->uuid, 'service_uuid' => $selectedOdoo->uuid])
                                    : route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $selectedEnvironment->uuid]) }}">
                                {{ __('Open environment') }}
                            </a>
                        @endif
                        @can('delete', $selectedEnvironment)
                            <livewire:project.delete-environment :environment_id="$selectedEnvironment->id"
                                :key="'delete-environment-'.$selectedEnvironment->id" />
                        @endcan
                        @endunless
                        @if ($openOdooUrl !== '')
                            <a class="button button-highlighted" target="_blank"
                                href="{{ route('project.service.odoo.enter', ['project_uuid' => $project->uuid, 'environment_uuid' => $selectedEnvironment->uuid, 'service_uuid' => $selectedOdoo->uuid]) }}">
                                {{ __('Open Odoo') }}
                            </a>
                        @endif
                    </div>
                </div>
                @if ($showCloneWizard)
                    <div wire:click="closeCloneWizard"
                        class="fixed inset-0 z-99 flex items-center justify-center bg-black/50 p-4 backdrop-blur-[2px]">
                    <div wire:click.stop
                        class="w-full max-w-lg space-y-3 rounded-xl border border-neutral-200 bg-white p-6 dark:border-white/[0.08] dark:bg-white/[0.025]">
                        <h2 class="text-base font-semibold">{{ __('Clone') }}</h2>
                        <p class="text-[13px] font-medium">
                            {{ __('This creates staging :name from production and starts Odoo. It copies the database and files, then neutralizes that copy. It does not create another production.', ['name' => \App\Support\OdooStaging::nextName($project)]) }}
                        </p>
                        <label class="flex items-center gap-2 text-[13px]">
                            <input type="radio" wire:model="cloneAddons" value="copy" class="rounded-full">
                            {{ __('Copy addons') }}
                        </label>
                        <label class="flex items-center gap-2 text-[13px]">
                            <input type="radio" wire:model="cloneAddons" value="empty" class="rounded-full">
                            {{ __('New, without modules') }}
                        </label>
                        @if (filled($project->odooProfile?->git_repository) && $project->odooProfile?->githubApp)
                            <div class="max-w-sm">
                                <x-forms.searchable-listbox id="stagingBranch" label="{{ __('Branch') }}"
                                    searchPlaceholder="{{ __('Search branches') }}"
                                    emptyText="{{ __('No matching branch') }}"
                                    :options="collect($cloneBranches)->map(fn (string $branch): array => ['value' => $branch, 'label' => $branch])->all()" />
                            </div>
                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                {{ __('The staging branch cannot be one already used by this repository.') }}
                                {{ __('Already used: :branches.', ['branches' => $usedBranches === [] ? '—' : implode(', ', $usedBranches)]) }}
                            </p>
                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                {{ __('GitHub is connected, so the new staging is another branch of :repository. It does not reuse the production branch.', ['repository' => $project->odooProfile->git_repository]) }}
                            </p>
                        @else
                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Without a repository, the new staging is empty. Addons stay in the production Jupyter folder until that staging has its own service.') }}
                            </p>
                        @endif
                        <div class="flex justify-end gap-2">
                            <button type="button" class="button" wire:click="closeCloneWizard">{{ __('Cancel') }}</button>
                            <button type="button" class="button button-highlighted" wire:click="cloneToStaging">
                                {{ __('Clone') }}
                            </button>
                        </div>
                    </div>
                    </div>
                @endif
            @endif

            <div x-cloak x-show="viewMode === 'grid'">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <template x-for="environment in paginatedEnvironments" :key="environment.uuid">
                        <article
                            class="group relative flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                            <a x-show="!environment.odoo" :href="environment.href" {{ wireNavigate() }} class="absolute inset-0 rounded-xl"
                                :aria-label="`Open ${environment.name}`"></a>

                            <div class="flex items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon name="layers" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2
                                        class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg"
                                        x-text="environment.name"></h2>
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                        x-text="environment.branch || environment.description || @js(__('Environment'))"></p>
                                </div>
                            </div>

                            <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                                <p class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-dim"
                                    x-text="`${environment.resourceCount} ${environment.resourceCount === 1 ? 'resource' : 'resources'}`">
                                </p>

                                <div class="relative z-10 flex shrink-0 items-center gap-0.5" x-show="!environment.activity">
                                    @include('livewire.project.environment-shortcuts')
                                    <a x-show="environment.enterHref" :href="environment.enterHref" target="_blank" @click.stop
                                        class="button button-highlighted h-7 px-2 text-[11px]">{{ __('Open Odoo') }}</a>
                                    <a x-show="environment.serviceHref && !environment.odoo" :href="environment.serviceHref"
                                        {{ wireNavigate() }}
                                        class="button h-7 px-2 text-[11px]"
                                        title="{{ __('Open') }}">{{ __('Open') }}</a>
                                    <a x-show="environment.addResourceHref" :href="environment.addResourceHref"
                                        {{ wireNavigate() }}
                                        class="flex size-7.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                        title="{{ __('Add resource') }}" :aria-label="`Add resource to ${environment.name}`">
                                        <x-reicon name="plus" class="size-3" />
                                    </a>
                                    <a x-show="environment.settingsHref" :href="environment.settingsHref"
                                        {{ wireNavigate() }}
                                        class="flex size-7.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                        title="{{ __('Environment settings') }}"
                                        :aria-label="`Open settings for ${environment.name}`">
                                        <x-reicon name="settings" class="size-3" />
                                    </a>
                                </div>
                                @include('livewire.project.environment-activity')
                            </div>
                        </article>
                    </template>
                </div>
                <x-client-pagination x-show="filteredEnvironments.length > 0" class="mt-3 rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]"
                    summary="`${rangeStart}-${rangeEnd} of ${filteredEnvironments.length}`" page-size-model="pageSize"
                    storage-key="coolify.page-size.project-environments" :options="[12, 24, 48, 96]" />
            </div>

            @if ($project->odooProfile)
                <p class="mb-2 text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Click a row to select it. The only clone action creates a staging environment from production.') }}
                </p>
            @endif
            <div x-show="viewMode === 'table'"
                class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                <div
                    class="environments-table-grid border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div>{{ __('Environment') }}</div>
                    <div class="environment-resource-count">{{ __('Resources') }}</div>
                    <div class="environment-description">{{ __('Description') }}</div>
                    <div></div>
                </div>

                <template x-for="environment in paginatedEnvironments" :key="environment.uuid">
                    <div
                        class="environments-table-grid group relative min-h-14 cursor-pointer items-center border-b border-neutral-200 px-4 py-2.5 transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]"
                        :class="selected === environment.uuid ? 'bg-neutral-100 dark:bg-white/[0.06]' : ''"
                        x-on:click="$wire.selectEnvironment(environment.uuid)">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-4 shrink-0 items-center justify-center rounded-full border"
                                :class="selected === environment.uuid ? 'border-accent bg-accent' : 'border-neutral-300 dark:border-white/20'"
                                aria-hidden="true"></span>
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="layers" class="size-4" />
                            </div>
                            <a x-show="!environment.odoo" :href="environment.href" {{ wireNavigate() }}
                                class="relative truncate text-[13px] font-semibold text-black hover:underline dark:text-fg"
                                x-text="environment.name"></a>
                            <a x-show="environment.odoo && !environment.activity" :href="environment.environmentHref" {{ wireNavigate() }}
                                class="truncate text-[13px] font-semibold hover:underline"
                                x-text="environment.name"></a>
                            <span x-show="environment.odoo && environment.activity" class="truncate text-[13px] font-semibold opacity-70"
                                x-text="environment.name"></span>
                        </div>

                        <div class="environment-resource-count text-[12px] text-neutral-600 dark:text-fg-dim"
                            x-text="environment.resourceCount"></div>
                        <p class="environment-description truncate text-[12px] text-neutral-500 dark:text-fg-dim"
                            x-text="environment.branch || environment.description || '-'"></p>

                        <div class="relative flex items-center justify-end gap-0.5" x-show="!environment.activity">
                            @include('livewire.project.environment-shortcuts')
                            <a x-show="environment.enterHref" :href="environment.enterHref" target="_blank" @click.stop
                                class="button button-highlighted h-7 px-2 text-[11px]">{{ __('Open Odoo') }}</a>
                            <a x-show="environment.serviceHref && !environment.odoo" :href="environment.serviceHref" {{ wireNavigate() }}
                                class="button h-7 px-2 text-[11px]">{{ __('Open') }}</a>
                            <a x-show="environment.addResourceHref" :href="environment.addResourceHref"
                                {{ wireNavigate() }}
                                class="flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                title="{{ __('Add resource') }}" :aria-label="`Add resource to ${environment.name}`">
                                <x-reicon name="plus" class="size-3.5" />
                            </a>
                            <a x-show="environment.settingsHref" :href="environment.settingsHref" {{ wireNavigate() }}
                                class="flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                title="{{ __('Environment settings') }}"
                                :aria-label="`Open settings for ${environment.name}`">
                                <x-reicon name="settings" class="size-3.5" />
                            </a>
                        </div>
                        @include('livewire.project.environment-activity')
                    </div>
                </template>
                <x-client-pagination x-show="filteredEnvironments.length > 0"
                    summary="`${rangeStart}-${rangeEnd} of ${filteredEnvironments.length}`" page-size-model="pageSize"
                    storage-key="coolify.page-size.project-environments" :options="[12, 24, 48, 96]" />
            </div>

            <div x-show="filteredEnvironments.length === 0"
                class="flex min-h-52 flex-col items-center justify-center rounded-xl border border-neutral-200 bg-white px-6 text-center dark:border-white/[0.08] dark:bg-white/[0.025]">
                <x-reicon name="search" class="mb-3 size-6 text-neutral-300 dark:text-fg-faint" />
                <p class="text-[13px] font-medium">{{ __('No matching environments') }}</p>
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Try a different search.') }}
                </p>
            </div>
        @endif
    </div>
</div>

<script>
    function projectEnvironments() {
        return {
            search: '',
            sortBy: 'name-asc',
            sortOpen: false,
            selected: @entangle('selectedEnvironmentUuid'),
            viewMode: @js($project->odooProfile !== null) ? 'table' : (localStorage.getItem('project-environments-view') || 'grid'),
            page: 1,
            pageSize: 12,
            openError: null,
            environments: @entangle('environmentPayload'),
            sortOptions: [{
                    value: 'name-asc',
                    label: 'Name A–Z'
                },
                {
                    value: 'name-desc',
                    label: 'Name Z–A'
                },
                {
                    value: 'resources',
                    label: 'Most resources'
                },
            ],
            get filteredEnvironments() {
                const query = this.search.trim().toLowerCase();
                const environments = this.environments.filter((environment) => {
                    const searchable = [environment.name, environment.description]
                        .filter(Boolean)
                        .join(' ')
                        .toLowerCase();

                    return !query || searchable.includes(query);
                });

                return environments.sort((first, second) => {
                    if (this.sortBy === 'name-desc') {
                        return second.name.localeCompare(first.name);
                    }
                    if (this.sortBy === 'resources') {
                        return second.resourceCount - first.resourceCount ||
                            first.name.localeCompare(second.name);
                    }

                    return first.name.localeCompare(second.name);
                });
            },
            get totalPages() {
                return Math.max(1, Math.ceil(this.filteredEnvironments.length / this.pageSize));
            },
            get paginatedEnvironments() {
                if (this.page > this.totalPages) {
                    this.page = this.totalPages;
                }

                const start = (this.page - 1) * this.pageSize;
                return this.filteredEnvironments.slice(start, start + this.pageSize);
            },
            get rangeStart() {
                return this.filteredEnvironments.length === 0 ? 0 : ((this.page - 1) * this.pageSize) + 1;
            },
            get rangeEnd() {
                return Math.min(this.page * this.pageSize, this.filteredEnvironments.length);
            },
            setViewMode(mode) {
                this.viewMode = mode;
                this.page = 1;
                localStorage.setItem('project-environments-view', mode);
            },
            previousPage() {
                this.page = Math.max(1, this.page - 1);
            },
            nextPage() {
                this.page = Math.min(this.totalPages, this.page + 1);
            },
        };
    }
</script>
