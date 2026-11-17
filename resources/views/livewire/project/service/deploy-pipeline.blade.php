<div class="gc-pipeline" @if ($polling) wire:poll.4s @endif>
    <div class="gc-pipeline-track" role="list" aria-label="{{ __('Deploy status') }}">
        @foreach ($pipeline['stages'] as $index => $stage)
            <div class="gc-pipeline-step is-{{ $stage['state'] }}" role="listitem" wire:key="pipeline-{{ $stage['key'] }}">
                <span class="gc-pipeline-dot" aria-hidden="true">
                    @if ($stage['state'] === 'done')
                        <x-reicon name="check" class="size-3" />
                    @elseif ($stage['state'] === 'failed')
                        <x-reicon name="close" class="size-3" />
                    @endif
                </span>
                <span class="gc-pipeline-label">{{ $stage['label'] }}</span>
            </div>
            @if (! $loop->last)
                <span class="gc-pipeline-connector is-{{ $stage['state'] === 'done' ? 'done' : ($stage['state'] === 'current' ? 'current' : 'idle') }}"
                    aria-hidden="true"></span>
            @endif
        @endforeach
    </div>
    @php
        $deployment = $pipeline['deployment'] ?? null;
    @endphp
    @if ($deployment)
        <p class="gc-pipeline-meta">
            @if (filled($deployment->commit_message))
                <span>{{ $deployment->commit_message }}</span>
            @elseif (filled($deployment->commit))
                <span class="font-mono">{{ str($deployment->commit)->substr(0, 7) }}</span>
            @else
                <span>{{ __('Latest modules update') }}</span>
            @endif
            <span class="gc-pipeline-sep">·</span>
            <span>{{ $deployment->created_at?->diffForHumans() }}</span>
        </p>
    @else
        <p class="gc-pipeline-meta">{{ __('No module updates yet. Push to the linked branch to start one.') }}</p>
    @endif
</div>
