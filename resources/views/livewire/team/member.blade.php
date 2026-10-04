<div wire:key="team-member-row-{{ $member->id }}"
    x-cloak x-show="isMemberVisible({{ $member->id }})"
    x-bind:style="{ order: memberOrder({{ $member->id }}) }">
<div @class([
    'data-table-row team-members-table-grid border-b border-neutral-200 dark:border-white/[0.07]',
    'last:border-b-0' => ! $canEditLimits,
    'team-members-table-grid-2fa' => auth()->user()?->can('manageMembers', currentTeam()),
])>
    <div>
        <div class="flex items-center gap-2">
            <div
                class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-[11px] font-semibold text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                {{ Str::upper(Str::substr($member->name ?: $member->email, 0, 1)) }}
            </div>
            <span class="truncate text-[13px] font-medium text-black dark:text-fg">{{ $member->name }}</span>
            @if ($member->id === Auth::id())
                <span
                    class="rounded-full bg-coollabs/10 px-1.5 py-0.5 text-[10px] font-medium text-coollabs dark:bg-warning/15 dark:text-warning">
                    {{ __('You') }}
                </span>
            @endif
        </div>
    </div>
    <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $member->email }}</div>
    <div>
        <span
            class="inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium capitalize text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
            {{ data_get($member, 'pivot.role') }}
        </span>
    </div>
    @can('manageMembers', currentTeam())
        <div class="flex items-center">
            <x-two-factor-badge :enabled="filled($member->two_factor_confirmed_at)" />
        </div>
    @endcan
    <div class="flex justify-end">
        @can('manageMembers', currentTeam())
            @if ($member->id !== Auth::id())
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false"
                    @click.outside="open = false">
                    <button type="button" class="button h-7! px-2.5! text-[11px]!" @click="open = !open"
                        aria-haspopup="menu" :aria-expanded="open">
                        {{ __('Manage') }}
                    </button>
                    <div x-show="open" x-cloak role="menu"
                        class="listbox-panel top-full! right-0! left-auto! mt-1! w-36! min-w-0!">
                        @if (Auth::user()->isOwner())
                            @if (data_get($member, 'pivot.role') !== 'owner')
                                <button type="button" class="listbox-option justify-start!" wire:click="makeOwner"
                                    @click="open = false">
                                    {{ __('Make owner') }}
                                </button>
                            @endif
                            @if (data_get($member, 'pivot.role') !== 'admin')
                                <button type="button" class="listbox-option justify-start!" wire:click="makeAdmin"
                                    @click="open = false">
                                    {{ __('Make admin') }}
                                </button>
                            @endif
                            @if (data_get($member, 'pivot.role') !== 'member')
                                <button type="button" class="listbox-option justify-start!"
                                    wire:click="makeReadonly" @click="open = false">
                                    {{ __('Make member') }}
                                </button>
                            @endif
                        @elseif (Auth::user()->isAdmin())
                            @if (data_get($member, 'pivot.role') === 'admin')
                                <button type="button" class="listbox-option justify-start!"
                                    wire:click="makeReadonly" @click="open = false">
                                    {{ __('Make member') }}
                                </button>
                            @elseif (data_get($member, 'pivot.role') === 'member')
                                <button type="button" class="listbox-option justify-start!" wire:click="makeAdmin"
                                    @click="open = false">
                                    {{ __('Make admin') }}
                                </button>
                            @endif
                        @endif
                        <div class="my-1 border-t border-neutral-200 dark:border-white/[0.08]"></div>
                        <button type="button" class="listbox-option justify-start! text-error! hover:text-error!"
                            wire:click="remove" @click="open = false">
                            {{ __('Remove member') }}
                        </button>
                    </div>
                </div>
            @endif
        @endcan
    </div>
</div>
@if ($canEditLimits)
    <form wire:submit="saveCreationLimits"
        class="flex flex-col gap-3 border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.07]">
        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
            {{ __('These limits belong to this admin. Leave a field empty for no limit. A member never receives servers or S3.') }}
        </p>
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <x-forms.input id="maxProjects" type="number" min="0" label="{{ __('Projects') }}" />
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('In use: :count. How many projects this admin can create.', ['count' => $usage['projects']]) }}</p>
            </div>
            <div>
                <x-forms.input id="maxEnvironments" type="number" min="0" label="{{ __('Environments') }}" />
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('In use: :count. Production and staging together.', ['count' => $usage['environments']]) }}</p>
            </div>
            <div>
                <x-forms.input id="maxMembers" type="number" min="0" label="{{ __('Members') }}" />
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('In use: :count. People this admin can invite.', ['count' => $usage['members']]) }}</p>
            </div>
            <div>
                <x-forms.input id="maxProductionBranches" type="number" min="0" label="{{ __('Production branches') }}" />
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('In use: :count. One production Odoo per project.', ['count' => $usage['production_branches']]) }}</p>
            </div>
            <div>
                <x-forms.input id="maxStagingBranches" type="number" min="0" label="{{ __('Staging branches') }}" />
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('In use: :count. Staging uses the same Odoo version as production.', ['count' => $usage['staging_branches']]) }}</p>
            </div>
            <div>
                <x-forms.input id="maxServices" type="number" min="0" label="{{ __('Services') }}" />
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('In use: :count. Services this admin can create.', ['count' => $usage['services']]) }}</p>
            </div>
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
            <div class="flex items-end">
                <x-forms.button type="submit" defaultClass="button button-highlighted">
                    {{ __('Save limits') }}
                </x-forms.button>
            </div>
        </div>
    </form>
@endif
@if ($canGrantOdoo)
    <form wire:submit="saveOdooAbilities" class="flex flex-col gap-2 px-4 py-3">
        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('What this member can do in Odoo. Servers and S3 stay with the owner.') }}</p>
        @foreach ($grantableOdooAbilities as $ability)
            <label class="flex items-center gap-2 text-[13px]">
                <input type="checkbox" value="{{ $ability }}" wire:model="odooAbilities">
                <span>{{ \App\Domain\Odoo\OdooAbilities::label($ability) }}</span>
            </label>
        @endforeach
        <x-forms.button type="submit" defaultClass="button button-highlighted">{{ __('Save Odoo abilities') }}</x-forms.button>
    </form>
@endif
</div>
