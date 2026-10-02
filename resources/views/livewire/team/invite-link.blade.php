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
                    <x-forms.listbox id="role" label="{{ __('Role') }}" live :options="array_values(array_filter([
                        auth()->user()->role() === 'owner' ? ['value' => 'owner', 'label' => __('Owner')] : null,
                        ['value' => 'admin', 'label' => __('Admin')],
                        ['value' => 'member', 'label' => __('Member')],
                    ]))" />
                </div>

                @if ($canAssignPermissions && $role === 'admin')
                    <p class="mt-4 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('Leave empty for no limit.') }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <x-forms.input id="maxProjects" type="number" min="0" label="{{ __('Projects') }}" />
                        <x-forms.input id="maxEnvironments" type="number" min="0" label="{{ __('Environments') }}" />
                        <x-forms.input id="maxMembers" type="number" min="0" label="{{ __('Members') }}" />
                        <x-forms.input id="maxProductionBranches" type="number" min="0" label="{{ __('Production branches') }}" />
                        <x-forms.input id="maxStagingBranches" type="number" min="0" label="{{ __('Staging branches') }}" />
                        <x-forms.input id="maxServices" type="number" min="0" label="{{ __('Services') }}" />
                        <x-forms.listbox id="githubAppId" label="{{ __('GitHub account') }}" :options="$githubApps" />
                        <x-forms.checkbox id="canAddServers" label="{{ __('Can add servers') }}" />
                        <x-forms.checkbox id="canLaunchOnInstanceServer"
                            label="{{ __('Can launch instances on the server where GPSH is installed') }}" />
                    </div>
                @endif

                @if ($canAssignPermissions && $role === 'member')
                    <div class="mt-4 flex flex-col gap-2">
                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('Odoo abilities for this member. The owner grants them. They do not include servers or S3.') }}</p>
                        @foreach ($grantableOdooAbilities as $ability)
                            <label class="flex items-center gap-2 text-[13px]">
                                <input type="checkbox" value="{{ $ability }}" wire:model="odooAbilities">
                                <span>{{ $ability }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </form>
    @endcan
</div>
