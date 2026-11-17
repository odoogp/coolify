                            <div class="application-settings-section-body flex flex-col gap-4">
                                <div>
                                    <p class="text-sm font-medium">{{ __('GitHub') }}</p>
                                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                                        {{ __('The environment name and the GitHub branch are stored separately. A push updates the addon folder.') }}
                                    </p>
                                </div>
                                @php
                                    $githubBranch = (string) ($environment->odooBranch?->git_branch ?? '');
                                    $linkedRepository = \App\Support\OdooGit::environmentGitRepository($environment);
                                    $odooGithubLinked = $githubBranch !== '' && filled($linkedRepository);
                                    $odooGithubUrl = $odooGithubLinked
                                        ? \App\Support\OdooGit::repositoryUrl((string) $linkedRepository, $githubBranch)
                                        : '';
                                @endphp
                                @if ($odooGithubLinked && ! $odooAccountChanged)
                                    <div class="flex flex-col gap-3 rounded-xl border border-neutral-200 bg-neutral-50/80 p-4 dark:border-white/[0.08] dark:bg-white/[0.025]">
                                        <div>
                                            <p class="auth-plan-kicker mb-1">{{ __('Repository') }}</p>
                                            <p class="font-mono text-[13px]">{{ $linkedRepository }}</p>
                                        </div>
                                        <p class="text-[13px]">{{ __('Environment :name.', ['name' => $environment->name]) }}</p>
                                        <p class="text-[13px]">{{ __('GitHub branch :branch.', ['branch' => $githubBranch]) }}</p>
                                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                            {{ __('This environment is linked. Repository and branch stay as set at association.') }}
                                        </p>
                                        @if ($odooGithubUrl !== '')
                                            <a class="button w-fit" href="{{ $odooGithubUrl }}" target="_blank" rel="noopener noreferrer">
                                                {{ __('Open on GitHub') }}
                                            </a>
                                        @endif
                                    </div>
                                    <div class="flex flex-col gap-3 rounded-xl border border-neutral-200 bg-neutral-50/80 p-4 dark:border-white/[0.08] dark:bg-white/[0.025]">
                                        <div>
                                            <p class="auth-plan-kicker mb-1">{{ __('Collaborators') }}</p>
                                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                                {{ __('Invite a GitHub username to this repository. Removing someone asks for confirmation.') }}
                                            </p>
                                        </div>
                                        <form wire:submit="inviteGithubUser" class="flex max-w-xl flex-col gap-3 sm:flex-row sm:items-end">
                                            <div class="min-w-0 flex-1">
                                                <x-forms.input canGate="update" :canResource="$service" id="githubLogin" label="{{ __('GitHub username') }}"
                                                    placeholder="ronaldleongg" />
                                            </div>
                                            <x-forms.button type="submit" canGate="update" :canResource="$service" isHighlighted>
                                                {{ __('Invite') }}
                                            </x-forms.button>
                                        </form>
                                        <ul class="max-w-xl divide-y divide-neutral-200 dark:divide-white/10">
                                            @forelse ($odooCollaborators as $collaborator)
                                                <li class="flex items-center justify-between gap-3 py-2 text-sm" wire:key="github-collaborator-{{ $collaborator['login'] }}">
                                                    <span class="font-mono">{{ $collaborator['login'] }}</span>
                                                    <button type="button" class="text-red-500"
                                                        wire:click="removeGithubUser('{{ $collaborator['login'] }}')"
                                                        wire:confirm="{{ __('Remove :login from this GitHub repository?', ['login' => $collaborator['login']]) }}">
                                                        {{ __('Remove') }}
                                                    </button>
                                                </li>
                                            @empty
                                                <li class="py-2 text-sm text-neutral-500 dark:text-fg-dim">{{ __('No collaborators yet.') }}</li>
                                            @endforelse
                                        </ul>
                                    </div>
                                @endif
                                @if (! $odooGithubConnected)
                                    <div>
                                        <x-forms.button type="button" wire:click="connectOdooGithub" canGate="update" :canResource="$service">
                                            {{ __('Connect GitHub') }}
                                        </x-forms.button>
                                    </div>
                                @else
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <p class="text-[13px]">
                                            {{ __('GitHub account :login, linked to :user.', [
                                                'login' => $odooGithubLogin !== '' ? $odooGithubLogin : __('this account'),
                                                'user' => auth()->user()->email,
                                            ]) }}
                                        </p>
                                        <x-forms.button type="button" wire:click="changeOdooGithubAccount" canGate="update" :canResource="$service">
                                            {{ __('Change account') }}
                                        </x-forms.button>
                                    </div>
                                    @if (! $odooGithubLinked || $odooAccountChanged)
                                    @if ($odooAccountChanged)
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            {{ __('This GitHub account is not the one linked to the repository. Associate a new repository, or choose an existing repository and a branch.') }}
                                        </p>
                                    @elseif ($associatingWithoutGithub)
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            {{ $odooGithubIsPersonal
                                                ? __('This project started without GitHub. On a personal GitHub account, create the repository yourself, then associate it here so custom addons can be pushed.')
                                                : __('This project started without GitHub. Create a repository and push the custom addons that are already on the instance.') }}
                                        </p>
                                    @else
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            {{ $awaitingRepositoryChoice
                                                ? __('GitHub is connected. Launch production on a new repository, or search an existing one.')
                                                : __('Choose the GitHub repository and branch for this environment. You only set this once.') }}
                                        </p>
                                    @endif
                                    @if ($awaitingRepositoryChoice && filled($service->environment?->odooBranch?->odoo_version ?: $project->odooProfile?->odoo_version))
                                        <p class="rounded-lg border border-amber-200/80 bg-amber-50 px-3 py-2.5 text-[12px] text-amber-950 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-100">
                                            {{ __('This environment installs Odoo :version. Launch another environment from the project if you need a different Compose.', ['version' => $service->environment?->odooBranch?->odoo_version ?: $project->odooProfile->odoo_version]) }}
                                        </p>
                                    @endif
                                    @php
                                        // Create is a separate path (org + no project repo yet). Personal / already-linked → existing only.
                                        $canCreateOdooRepository = ! $odooGithubIsPersonal && blank($project->odooProfile?->git_repository);
                                        $showCreateOdooRepository = $canCreateOdooRepository && $odooRepoMode === 'new';
                                        $takenGithubBranches = $this->takenOdooGithubBranches();
                                    @endphp
                                    @if ($canCreateOdooRepository)
                                        <div class="flex flex-wrap gap-4 text-sm">
                                            <label class="inline-flex items-center gap-2">
                                                <input type="radio" wire:model.live="odooRepoMode" value="new" class="rounded-full">
                                                {{ __('Create a new repository') }}
                                            </label>
                                            <label class="inline-flex items-center gap-2">
                                                <input type="radio" wire:model.live="odooRepoMode" value="existing" class="rounded-full">
                                                {{ __('Use an existing repository') }}
                                            </label>
                                        </div>
                                    @else
                                        <p class="text-[13px] font-medium">{{ __('Associate an existing repository') }}</p>
                                    @endif
                                    @if ($showCreateOdooRepository)
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            @if ($environment->isProduction())
                                                {{ __('The new repository is named :name. This environment tracks its default branch.', ['name' => \App\Support\OdooGit::repositoryName($project)]) }}
                                            @else
                                                {{ __('The new repository is named :name. This environment gets the branch :branch.', ['name' => \App\Support\OdooGit::repositoryName($project), 'branch' => $environment->name]) }}
                                            @endif
                                        </p>
                                    @else
                                        <div class="max-w-sm space-y-2">
                                            <div class="flex items-end gap-2">
                                                <div class="min-w-0 flex-1">
                                                    <label class="mb-1.5 block text-sm font-medium" for="odoo-repository-query">{{ __('Repository') }}</label>
                                                    <input id="odoo-repository-query" type="search" wire:model.live.debounce.200ms="odooRepositoryQuery"
                                                        placeholder="{{ __('Search repositories') }}" class="input">
                                                </div>
                                                <x-forms.button type="button" wire:click="reloadOdooRepositories" canGate="update" :canResource="$service">
                                                    {{ __('Refresh repositories') }}
                                                </x-forms.button>
                                            </div>
                                            <p wire:loading wire:target="reloadOdooRepositories,odooRepoMode" class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                                {{ __('Loading repositories…') }}
                                            </p>
                                            @php
                                                $repositoryQuery = strtolower($odooRepositoryQuery);
                                                $repositoryMatches = collect($odooRepositories)
                                                    ->filter(fn (array $repository): bool => $repositoryQuery === '' || str_contains(strtolower($repository['full_name']), $repositoryQuery))
                                                    ->take(20);
                                            @endphp
                                            @if ($repositoryMatches->isNotEmpty())
                                                <ul class="overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                                                    @foreach ($repositoryMatches as $repository)
                                                        <li>
                                                            <button type="button" wire:click="pickOdooRepository({{ $repository['id'] }})"
                                                                class="flex w-full items-center px-3 py-2 text-left font-mono text-[13px] hover:bg-neutral-100 dark:hover:bg-white/[0.06] {{ (int) $odooRepositoryId === (int) $repository['id'] ? 'bg-neutral-100 dark:bg-white/[0.06]' : '' }}">
                                                                {{ $repository['full_name'] }}
                                                            </button>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @elseif ($odooRepositoriesLoaded)
                                                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('No matching repositories.') }}</p>
                                            @endif
                                            @if ($odooGithubBranches !== [])
                                                <x-forms.searchable-listbox id="odooBranch" label="{{ __('GitHub branch') }}"
                                                    searchPlaceholder="{{ __('Search branches') }}"
                                                    emptyText="{{ __('No matching branch') }}"
                                                    :options="collect($odooGithubBranches)->map(function (string $branch) use ($takenGithubBranches): array {
                                                        $taken = in_array($branch, $takenGithubBranches, true);

                                                        return [
                                                            'value' => $branch,
                                                            'label' => $taken ? $branch.' ('.__('in use').')' : $branch,
                                                            'disabled' => $taken,
                                                        ];
                                                    })->all()" />
                                                @if ($takenGithubBranches !== [])
                                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                                        {{ __('Each environment needs its own tip on the same repository. The same branch name on another repository is fine.') }}
                                                    </p>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                    @if ($associateError)
                                        <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-[13px] text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100">
                                            <p>{{ __($associateError) }}</p>
                                            <button type="button" class="mt-2 text-[12px] underline" wire:click="dismissAssociateError">
                                                {{ __('Dismiss') }}
                                            </button>
                                        </div>
                                    @endif
                                    <div class="flex flex-wrap items-center gap-3"
                                        @if ($associateRunning) wire:poll.2s="refreshAssociateProgress" @endif>
                                        <x-forms.button type="button" wire:click="associateOdooRepository" canGate="update" :canResource="$service" isHighlighted
                                            :disabled="$associateRunning || ($showCreateOdooRepository && $odooGithubIsPersonal)">
                                            {{ $showCreateOdooRepository
                                                ? ($associatingWithoutGithub ? __('Create repository') : __('Create repository and launch'))
                                                : __('Associate repository') }}
                                        </x-forms.button>
                                        @unless ($associatingWithoutGithub || ($needsEnvironmentGithubLink && filled($project->odooProfile?->git_repository)))
                                        <x-forms.button type="button" wire:click="launchWithoutGithub" canGate="update" :canResource="$service">
                                            {{ __('Launch without GitHub') }}
                                        </x-forms.button>
                                        @endunless
                                        @if ($associateRunning)
                                            <p class="inline-flex items-center gap-1.5 text-[13px]">
                                                <span class="size-1.5 animate-pulse rounded-full bg-sky-500"></span>
                                                {{ $showCreateOdooRepository ? __('Creating the repository…') : __('Linking repository…') }}
                                            </p>
                                        @else
                                            <p wire:loading wire:target="associateOdooRepository" class="text-[13px]">
                                                {{ $showCreateOdooRepository ? __('Creating the repository…') : __('Linking repository…') }}
                                            </p>
                                        @endif
                                    </div>
                                    @endif
                                @endif
                            </div>
