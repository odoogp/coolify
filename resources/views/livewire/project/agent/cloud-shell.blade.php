<div>
    @if ($project && $agent && ! $suppressedBySideChat)
        <div class="gc-agent-cloud" wire:key="agent-cloud-{{ $project->uuid }}">
            @if (! $open)
                <button type="button" class="gc-agent-cloud-fab" wire:click="toggle" title="{{ __('Project agent') }}">
                    <x-reicon name="feedback" class="size-4" />
                    <span>{{ $agent->name }}</span>
                </button>
            @else
                <div class="gc-agent-cloud-panel">
                    <div class="flex items-center justify-between gap-2 border-b border-white/10 px-3 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ $agent->name }}</p>
                            <p class="truncate text-[11px] text-neutral-400">{{ $project->name }}</p>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" class="button" wire:click="toggle" title="{{ __('Minimize') }}">
                                <x-reicon name="chevron-down" class="size-3.5" />
                            </button>
                            <button type="button" class="button" wire:click="stopFollowing" title="{{ __('Detach') }}">
                                <x-reicon name="x" class="size-3.5" />
                            </button>
                        </div>
                    </div>
                    <div class="p-3">
                        <livewire:project.agent.chat :project_uuid="$project->uuid" :key="'cloud-chat-'.$project->uuid" />
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
