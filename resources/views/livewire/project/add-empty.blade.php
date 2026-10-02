<div>
    @if ($launchRunning || $launchError)
        <div @if ($launchRunning) wire:poll.2s="refreshLaunchProgress" @endif
            class="fixed inset-0 z-99 flex items-center justify-center bg-black/50 p-4 backdrop-blur-[2px]">
            <div class="w-full max-w-lg rounded-xl border border-neutral-200 bg-white p-6 dark:border-white/[0.08] dark:bg-white/[0.025]">
                <h2 class="text-base font-semibold">
                    {{ $launchError ? __('The project could not be started.') : __('Creating the project') }}
                </h2>
                <ul class="mt-4 flex flex-col gap-2">
                    @foreach ([
                        1 => __('Creating the project'),
                        2 => __('Starting the containers'),
                        3 => __('Checking HTTPS'),
                        4 => __('Done'),
                    ] as $stepNumber => $label)
                        <li @class([
                            'flex items-center gap-2 text-[13px] leading-5',
                            'text-emerald-600 dark:text-emerald-400' => $launchStep > $stepNumber,
                            'text-neutral-900 dark:text-fg' => $launchStep === $stepNumber,
                            'text-neutral-500 dark:text-fg-dim' => $launchStep < $stepNumber,
                        ])>
                            <span class="flex size-4 shrink-0 items-center justify-center">
                                @if ($launchStep > $stepNumber)
                                    <x-reicon name="check-circle" class="size-4" />
                                @elseif ($launchStep === $stepNumber && ! $launchError)
                                    <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                @else
                                    <span class="size-3 rounded-full border border-neutral-300 dark:border-white/20"></span>
                                @endif
                            </span>
                            <span>{{ $label }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($launchError)
                    <p class="mt-4 text-[13px] text-red-500">{{ __($launchError) }}</p>
                    <button type="button" class="button mt-4" wire:click="dismissLaunchError">{{ __('Close') }}</button>
                @endif
            </div>
        </div>
    @endif
@if ($needsServer)
    <div class="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-3 dark:border-white/[0.08] dark:bg-white/[0.025]">
        <p class="text-[13px] leading-5 text-neutral-700 dark:text-fg">
            {{ __('Do you want to create a server?') }}
        </p>
        @if ($canAddServer)
            <a href="{{ route('server.create') }}" class="button button-highlighted" {{ wireNavigate() }}>
                {{ __('Create a new server') }}
            </a>
        @else
            <p class="text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                {{ __('The owner has to add a server, or allow you to add servers, before you can create a project.') }}
            </p>
        @endif
    </div>
@else
<form class="space-y-4" wire:submit="submit">
    <div class="grid gap-4 md:grid-cols-2">
        <x-forms.input placeholder="{{ __('Your project name') }}" id="name" label="{{ __('Name') }}" required />
        <x-forms.input placeholder="{{ __('A short project description') }}" id="description" label="{{ __('Description') }}" />
    </div>

    <x-creation-quota :quota="$creationQuota" />

    <x-forms.searchable-listbox id="service" label="{{ __('Service') }}" live portal
        searchPlaceholder="{{ __('Search services') }}"
        emptyText="{{ __('No matching service') }}"
        :options="$serviceOptions" />

    @if ($serverChoices !== [])
        <x-forms.listbox id="serverId" portal
            label="{{ $hasOtherServers ? __('Which server should run this project?') : __('Do you want to create a new server?') }}"
            :options="$serverChoices" />
    @endif

    @if ($service === 'odoo')
        <x-forms.listbox id="odooVersion" label="{{ __('Odoo version') }}" portal
            :options="collect(\App\Support\OdooVersion::SUPPORTED)->map(fn (string $version) => ['value' => $version, 'label' => 'Odoo '.$version])->all()" />
        <x-forms.checkbox id="connectGithub" label="{{ __('Connect GitHub') }}"
            helper="{{ __('You can leave this off. JupyterLab then shows the addon files, and a repository can be connected later.') }}" />
    @endif

    <p
        class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2.5 text-[12px] text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
        {{ __('A production environment will be created automatically.') }}
    </p>

    <footer class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <x-forms.button type="submit" wire:target="submit"
            defaultClass="button button-highlighted">
            {{ __('Create project') }}
        </x-forms.button>
    </footer>
</form>
@endif
</div>
