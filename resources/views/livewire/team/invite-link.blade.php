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

                @if ($canAssignPermissions && in_array($role, ['admin', 'member'], true))
                    <p class="mt-4 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('These limits are saved when the user is created, before the sign-in link. Leave a field empty for no limit. A member never receives servers or S3.') }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <x-forms.input id="maxProjects" type="number" min="0" label="{{ __('Projects') }}" />
                            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('How many projects this person can create.') }}</p>
                        </div>
                        <div>
                            <x-forms.input id="maxEnvironments" type="number" min="0" label="{{ __('Environments') }}" />
                            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('Production and staging together.') }}</p>
                        </div>
                        <div>
                            <x-forms.input id="maxMembers" type="number" min="0" label="{{ __('Members') }}" />
                            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('People this admin can invite.') }}</p>
                        </div>
                        <div>
                            <x-forms.input id="maxProductionBranches" type="number" min="0" label="{{ __('Production branches') }}" />
                            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('One production Odoo per project.') }}</p>
                        </div>
                        <div>
                            <x-forms.input id="maxStagingBranches" type="number" min="0" label="{{ __('Staging branches') }}" />
                            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('Staging uses the same Odoo version as production.') }}</p>
                        </div>
                        <div>
                            <x-forms.input id="maxServices" type="number" min="0" label="{{ __('Services') }}" />
                            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('Services this person can create.') }}</p>
                        </div>
                        @if ($role === 'admin')
                            <div>
                                <x-forms.listbox id="githubAppId" label="{{ __('GitHub account') }}" :options="$githubApps" />
                                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('The GitHub account this admin uses when launching Odoo.') }}</p>
                            </div>
                            <div class="sm:col-span-2 flex flex-col gap-2">
                                <x-forms.checkbox id="canAddServers" label="{{ __('Can add servers') }}" />
                                <x-forms.checkbox id="canLaunchOnInstanceServer"
                                    label="{{ __('Can launch instances on the server where GPSH is installed') }}" />
                                <p class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('Both stay off unless you turn them on. A member cannot receive either one.') }}</p>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($canAssignPermissions && $role === 'member')
                    <div class="mt-4 flex flex-col gap-2">
                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('What this member can do in Odoo. Servers and S3 stay with the owner.') }}</p>
                        @foreach ($grantableOdooAbilities as $ability)
                            <label class="flex items-center gap-2 text-[13px]">
                                <input type="checkbox" value="{{ $ability }}" wire:model="odooAbilities">
                                <span>{{ \App\Domain\Odoo\OdooAbilities::label($ability) }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </form>
    @endcan
</div>
