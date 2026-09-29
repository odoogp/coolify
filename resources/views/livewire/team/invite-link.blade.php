<div>
    @can('manageInvitations', currentTeam())
        <form wire:submit="viaLink">
            <x-application.settings-section title="{{ __('Invite a member') }}"
                description="{{ __('Create a reusable invitation link or deliver it by email.') }}">
                <x-slot:actions>
                    @if (is_transactional_emails_enabled())
                        <x-forms.button type="button" wire:click.prevent="viaEmail">
                            <x-reicon name="notifications" class="size-3.5" />
                            {{ __('Send email') }}
                        </x-forms.button>
                    @endif
                    <x-forms.button type="submit" wire:target="viaLink"
                        defaultClass="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        {{ __('Generate link') }}
                    </x-forms.button>
                </x-slot:actions>

                @if (!is_transactional_emails_enabled() && isInstanceAdmin())
                    <x-callout type="warning" title="{{ __('Email delivery is not configured') }}">
                        {{ __('Configure transactional email in instance settings to send invitations directly.') }}
                    </x-callout>
                @endif

                <x-creation-quota :quota="$creationQuota" class="mt-4" />

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="email" type="email" label="{{ __('Email address') }}"
                        placeholder="{{ __('teammate@example.com') }}" required />
                    <x-forms.listbox id="role" label="{{ __('Role') }}" :options="array_values(array_filter([
                        auth()->user()->role() === 'owner' ? ['value' => 'owner', 'label' => __('Owner')] : null,
                        ['value' => 'admin', 'label' => __('Admin')],
                        ['value' => 'member', 'label' => __('Member')],
                    ]))" />
                </div>
            </x-application.settings-section>
        </form>
    @endcan
</div>
