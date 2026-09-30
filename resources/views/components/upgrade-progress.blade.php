@php
    $steps = [
        1 => __('Preparing update'),
        2 => __('Fetching repository'),
        3 => __('Pulling changes'),
        4 => __('Building image'),
        5 => __('Recreating Coolify'),
        6 => __('Health check'),
    ];
@endphp

<div class="w-full">
    <ul class="flex flex-col gap-1.5">
        @foreach ($steps as $stepNumber => $label)
            <li class="flex items-center gap-2 text-[13px] leading-5"
                :class="{
                    'text-emerald-600 dark:text-emerald-400': stagePosition() > {{ $stepNumber }},
                    'text-neutral-900 dark:text-fg': stagePosition() === {{ $stepNumber }},
                    'text-neutral-500 dark:text-fg-dim': stagePosition() < {{ $stepNumber }}
                }">
                <span class="flex size-4 shrink-0 items-center justify-center">
                    <template x-if="stagePosition() > {{ $stepNumber }}">
                        <x-reicon name="check-circle" class="size-4" />
                    </template>
                    <template x-if="stagePosition() === {{ $stepNumber }}">
                        <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </template>
                    <template x-if="stagePosition() < {{ $stepNumber }}">
                        <span class="size-3 rounded-full border border-neutral-300 dark:border-white/20"></span>
                    </template>
                </span>
                <span>{{ $label }}</span>
            </li>
        @endforeach
    </ul>
</div>
