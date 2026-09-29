            <div class="application-settings-form">
                <x-application.settings-section title="{{ __('Permissions') }}"
                    description="{{ __('GitHub permissions currently granted to this App.') }}">
                    <x-slot:actions>
                        @can('view', $github_app)
                            <x-forms.button type="button" wire:click.prevent="checkPermissions">
                                <x-reicon name="refresh" class="size-3.5" />
                                {{ __('Refetch') }}
                            </x-forms.button>
                            <a href="{{ getPermissionsPath($github_app) }}" class="button">
                                {{ __('Update on GitHub') }}
                                <x-external-link />
                            </a>
                        @endcan
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <x-forms.input canGate="view" :canResource="$github_app" id="contents"
                            helper="{{ __('Read access is mandatory.') }}" label="{{ __('Contents') }}" readonly placeholder="{{ __('N/A') }}" />
                        <x-forms.input canGate="view" :canResource="$github_app" id="metadata"
                            helper="{{ __('Read access is mandatory.') }}" label="{{ __('Metadata') }}" readonly placeholder="{{ __('N/A') }}" />
                        <x-forms.input canGate="view" :canResource="$github_app" id="pullRequests"
                            helper="{{ __('Write access is needed for preview deployment status updates.') }}"
                            label="{{ __('Pull requests') }}" readonly placeholder="{{ __('N/A') }}" />
                    </div>
                </x-application.settings-section>
            </div>
