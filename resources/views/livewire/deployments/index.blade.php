<div @class(['gc-deploys', 'gc-deploys--compact' => $compact, 'w-full' => ! $compact])
    @if ($polling) wire:poll.4s @endif>
    @unless ($compact || $lockedProjectUuid)
        <x-slot:title>
            {{ __('Deploys') }} | {{ product_name() }}
        </x-slot>

        <header class="gc-deploys-head">
            <div class="min-w-0">
                <h1 class="gc-deploys-title">{{ __('Deploys') }}</h1>
                <p class="gc-deploys-lede">
                    @if ($counts['active'] > 0)
                        {{ trans_choice('{1} :count deploy is running right now.|[2,*] :count deploys are running right now.', $counts['active'], ['count' => $counts['active']]) }}
                    @else
                        {{ __('Every push, restart and manual deploy across your projects.') }}
                    @endif
                </p>
            </div>
        </header>
    @endunless

    @unless ($compact)
    <div class="gc-deploys-toolbar">
        <div class="gc-segmented" role="tablist" aria-label="{{ __('Filter deploys') }}">
            @foreach (['all' => __('All'), 'active' => __('Running'), 'failed' => __('Failed'), 'finished' => __('Deployed')] as $value => $label)
                <button type="button" role="tab" wire:click="setFilter('{{ $value }}')"
                    aria-selected="{{ $filter === $value ? 'true' : 'false' }}"
                    @class(['gc-segmented-item', 'is-active' => $filter === $value])>
                    {{ $label }}
                    @if ($counts[$value] > 0 && $value !== 'all')
                        <span @class(['gc-segmented-count', 'is-alert' => $value === 'failed'])>{{ $counts[$value] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="gc-deploys-filters">
                <label class="gc-deploys-search">
                    <x-reicon name="search" class="size-3.5" />
                    <span class="sr-only">{{ __('Search deploys') }}</span>
                    <input type="search" wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by app, commit or message') }}">
                </label>
                @if (is_null($lockedProjectUuid) && $projects->count() > 1)
                    <div class="gc-deploys-select">
                        <x-forms.listbox id="projectUuid" live
                            :options="collect([['value' => '', 'label' => __('All projects')]])
                                ->concat($projects->map(fn ($option) => ['value' => $option->uuid, 'label' => $option->name]))
                                ->all()" />
                    </div>
                @endif
        </div>
    </div>
    @endunless

    @if ($groups->isEmpty())
        <div class="gc-deploys-empty">
            <span class="gc-deploys-empty-mark"><x-reicon name="upload" class="size-5" /></span>
            <p class="gc-deploys-empty-title">
                {{ match ($filter) {
                    'active' => __('Nothing is deploying right now'),
                    'failed' => __('No failed deploys'),
                    'finished' => __('No completed deploys yet'),
                    default => trim($search) !== '' ? __('No deploys match your search') : __('No deploys yet'),
                } }}
            </p>
            <p class="gc-deploys-empty-copy">
                {{ $filter === 'all' && trim($search) === ''
                    ? __('A deploy appears here when you push to a linked branch or start one from an application.')
                    : __('Try another filter.') }}
            </p>
        </div>
    @else
        <div class="gc-deploys-days">
            @foreach ($groups as $day => $rows)
                <section class="gc-deploys-day" wire:key="deploys-day-{{ md5($day) }}">
                    <h2 class="gc-deploys-day-title">{{ $day }}</h2>
                    <ol class="gc-timeline">
                        @foreach ($rows as $row)
                            <li wire:key="deploy-{{ $row['key'] }}" class="gc-timeline-item" data-status="{{ $row['status'] }}">
                                <a @if ($row['url']) href="{{ $row['url'] }}" {{ wireNavigate() }} @endif
                                    class="gc-timeline-link">
                                    <time class="gc-timeline-time" datetime="{{ $row['time'] }}" title="{{ $row['ago'] }}">{{ $row['time'] }}</time>
                                    <span class="gc-timeline-node" aria-hidden="true">
                                        @switch($row['status'])
                                            @case('finished')
                                                <x-reicon name="check" class="size-2.5" />
                                            @break
                                            @case('failed')
                                                <x-reicon name="x" class="size-2.5" />
                                            @break
                                        @endswitch
                                    </span>
                                    <span class="gc-timeline-body">
                                        <span class="gc-timeline-line">
                                            <span class="gc-timeline-name">{{ $row['name'] }}</span>
                                            @if ($row['environment'])
                                                <span @class(['gc-env-tag', 'is-production' => $row['production']])>{{ $row['environment'] }}</span>
                                            @endif
                                        </span>
                                        <span class="gc-timeline-meta">
                                            @if ($row['message'] !== '')
                                                <span class="gc-timeline-message">{{ $row['message'] }}</span>
                                            @elseif ($row['pullRequest'])
                                                <span class="gc-timeline-message">{{ __('Pull request #:number', ['number' => $row['pullRequest']]) }}</span>
                                            @else
                                                <span class="gc-timeline-message">{{ $row['trigger'] }}</span>
                                            @endif
                                            @if ($row['commit'])
                                                <code class="gc-timeline-commit">{{ $row['commit'] }}</code>
                                            @endif
                                            @if (! $compact && is_null($lockedProjectUuid) && $row['project'])
                                                <span class="gc-timeline-project">{{ $row['project'] }}</span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="gc-timeline-status">
                                        <span class="gc-timeline-status-label">{{ $row['statusLabel'] }}</span>
                                        @if ($row['duration'])
                                            <span class="gc-timeline-duration">{{ $row['duration'] }}</span>
                                        @endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach
        </div>

        @if ($hasMore && ! $compact)
            <button type="button" wire:click="loadMore" wire:loading.attr="disabled" class="gc-deploys-more">
                <span wire:loading.remove wire:target="loadMore">{{ __('Show older deploys') }}</span>
                <span wire:loading wire:target="loadMore">{{ __('Loading…') }}</span>
            </button>
        @endif
    @endif
</div>
