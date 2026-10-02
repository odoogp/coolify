<div>
    <x-slot:title>
        {{ __('GitHub App | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-unsaved-bar action="submit" targets="github_app_name,github_app_icon" />
            <x-application.settings-section title="{{ __('GitHub App') }}"
                description="{{ __('Name and icon used the next time GPSH registers its GitHub App. An app that is already installed is reused.') }}">
                <div class="grid max-w-xl gap-4">
                    <x-forms.input canGate="update" :canResource="$settings" id="github_app_name"
                        label="{{ __('GitHub App name') }}" placeholder="gpsh1"
                        helper="{{ __('Name used the next time GPSH registers its GitHub App. An app that is already installed is reused.') }}" />
                    <div>
                        <x-forms.input canGate="update" :canResource="$settings" id="github_app_icon"
                            label="{{ __('GitHub App icon') }}"
                            placeholder="https://example.com/icon.png"
                            helper="{{ __('Image URL. GPSH shows it on the GitHub connection. After registering, upload the same image as the app logo on GitHub.') }}" />
                        @if (is_string($github_app_icon) && $github_app_icon !== '')
                            <img src="{{ $github_app_icon }}" alt="" class="mt-2 size-10 rounded-md border border-neutral-200 object-cover dark:border-white/10">
                        @endif
                    </div>
                </div>
            </x-application.settings-section>
            <x-application.settings-section title="{{ __('Installed apps') }}"
                description="{{ __('GitHub Apps created from GPSH. This is the only place they are managed.') }}">
                @if ($apps->isEmpty())
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('No GitHub App is installed yet.') }}</p>
                @else
                    <ul class="flex flex-col gap-2">
                        @foreach ($apps as $app)
                            <li class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-[13px]">{{ $app->name ?: __('GitHub App') }}@if ($app->team) · {{ $app->team->name }}@endif</span>
                                <a class="button" href="{{ route('source.github.show', ['github_app_uuid' => $app->uuid]) }}">{{ __('Manage') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
