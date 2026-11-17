<div x-data x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })"
    class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
    <form wire:submit="loadBranch">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Public Git repository') }}</h2>
                    <p>{{ __('Connect a public repository over HTTPS and inspect its default branch.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <x-forms.input required id="repository_url" label="{{ __('Repository URL') }}"
                            helper="{!! __('repository.url') !!}" placeholder="https://github.com/owner/repository"
                            autofocus />
                    </div>
                    <x-forms.button type="submit" class="w-full justify-center sm:w-auto"
                        wire:loading.attr="disabled" wire:target="loadBranch" :showLoadingIndicator="false">
                        <svg wire:loading wire:target="loadBranch" class="size-3.5 shrink-0 animate-spin"
                            viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor"
                                stroke-width="3" />
                            <path class="opacity-75" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor"
                                stroke-width="3" stroke-linecap="round" />
                        </svg>
                        {{ __('Check repository') }}
                    </x-forms.button>
                </div>
                <p class="mt-2 text-xs text-neutral-500 dark:text-fg-dim">
                    {{ __('Need a sample? Browse') }}
                    <a class="font-medium text-coollabs hover:underline dark:text-warning"
                        href="https://github.com/coollabsio/coolify-examples/" target="_blank">
                        {{ __('Coolify Examples') }}
                    </a>.
                </p>
            </div>
        </section>
    </form>

    @if ($branchFound)
        <form wire:submit="submit">
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div>
                        <h2>{{ __('Build configuration') }}</h2>
                        <p>{{ __('Choose how Coolify builds and runs this repository.') }}</p>
                    </div>
                    <x-forms.button type="submit" isHighlighted>{{ __('Continue') }}</x-forms.button>
                </div>
                <div class="application-settings-section-body space-y-5">
                    @if ($rate_limit_remaining && $rate_limit_reset)
                        <x-callout type="info" title="{{ __('Git provider rate limit') }}">
                            {{ $rate_limit_remaining }} requests remain. The limit resets at
                            {{ $rate_limit_reset }} UTC.
                        </x-callout>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-forms.input id="git_branch" label="{{ __('Branch') }}"
                            :disabled="$git_source !== 'other'"
                            helper="{{ __('You can choose another branch after the application is created.') }}" />
                        <x-forms.listbox id="build_pack" label="{{ __('Build pack') }}" required live :options="[
                            ['value' => 'railpack', 'label' => __('Railpack')],
                            ['value' => 'nixpacks', 'label' => __('Nixpacks')],
                            ['value' => 'static', 'label' => __('Static')],
                            ['value' => 'dockerfile', 'label' => __('Dockerfile')],
                            ['value' => 'dockercompose', 'label' => __('Docker Compose')],
                        ]" />
                        @if ($show_is_static)
                            <x-forms.listbox id="isStatic" label="{{ __('Output type') }}" onChange="instantSave"
                                :options="[
                                    ['value' => false, 'label' => __('Web application')],
                                    ['value' => true, 'label' => __('Static site')],
                                ]" />
                            <x-forms.input type="number" id="port" label="{{ __('Port') }}"
                                :readonly="$isStatic || $build_pack === 'static'"
                                helper="{{ __('Port the application listens on.') }}" />
                        @endif
                        @if ($isStatic)
                            <x-forms.input id="publish_directory" label="{{ __('Publish directory') }}"
                                helper="{{ __('Directory containing the generated static assets.') }}" />
                        @endif
                    </div>

                    @if ($build_pack === 'dockercompose')
                        <div x-data="{
                            baseDir: @js($base_directory),
                            composeLocation: @js($docker_compose_location),
                            normalize(path) {
                                if (!path || path.trim() === '') return '/';
                                const normalized = path.trim().replace(/\/+$/, '');
                                return normalized.startsWith('/') ? normalized : '/' + normalized;
                            },
                        }" class="grid gap-4 sm:grid-cols-2">
                            <x-forms.input placeholder="/" wire:model.defer="base_directory"
                                label="{{ __('Base directory') }}" helper="{{ __('Repository directory used as the build root.') }}"
                                x-model="baseDir" @blur="baseDir = normalize(baseDir)" />
                            <x-forms.input placeholder="/docker-compose.yaml"
                                wire:model.defer="docker_compose_location" label="{{ __('Compose file') }}"
                                helper="{{ __('Path relative to the base directory.') }}" x-model="composeLocation"
                                @blur="composeLocation = normalize(composeLocation)" />
                            <p class="sm:col-span-2 text-xs text-neutral-500 dark:text-fg-dim">
                                Resolved file:
                                <code class="font-mono text-coollabs dark:text-warning"
                                    x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></code>
                            </p>
                        </div>
                    @else
                        <x-forms.input wire:model="base_directory" label="{{ __('Base directory') }}"
                            helper="{{ __('Repository directory used as the build root.') }}" />
                    @endif
                </div>
            </section>
        </form>
    @endif
</div>
