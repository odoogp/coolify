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
        </form>
    </x-settings.layout>
</div>
