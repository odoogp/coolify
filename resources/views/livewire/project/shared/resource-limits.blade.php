<form wire:submit="submit" class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section id="cpu-limits-section" title="{{ __('CPU') }}"
        helper="{{ __('Limit CPU capacity, affinity, and scheduling priority for this container.') }}">
        <x-slot:actions>
            <a class="button" target="_blank" rel="noopener noreferrer"
                href="https://docs.docker.com/engine/containers/resource_constraints/#cpu">
                {{ __('Docker CPU constraints') }}
                <x-reicon name="external-link" class="size-3.5" />
            </a>
        </x-slot:actions>
        <div class="grid gap-4 md:grid-cols-3">
            <x-forms.input canGate="update" :canResource="$resource" placeholder="1.5"
                helper="{{ __('Set to 0 to use all available CPUs. Decimal values such as 0.5 are supported.') }}"
                label="{{ __('CPU limit') }}" id="limitsCpus" />
            <x-forms.input canGate="update" :canResource="$resource" placeholder="0-2"
                helper="{{ __('Restrict execution to specific cores, for example 0-2 or 0,1,3. Leave empty for all cores.') }}"
                label="{{ __('CPU set') }}" id="limitsCpuset" />
            <x-forms.input canGate="update" :canResource="$resource" placeholder="1024"
                helper="{{ __('Relative CPU scheduling weight. Docker uses 1024 by default.') }}"
                label="{{ __('CPU weight') }}" id="limitsCpuShares" />
        </div>
    </x-application.settings-section>

    <x-application.settings-section id="memory-limits-section" title="{{ __('Memory') }}"
        helper="{{ __('Set hard and soft memory limits, swap allowance, and swappiness.') }}">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$resource"
                helper="{{ __('Soft reservation used when the host is under memory pressure. Use values such as 256m or 1g.') }}"
                label="{{ __('Memory reservation') }}" id="limitsMemoryReservation" />
            <x-forms.input canGate="update" :canResource="$resource"
                helper="{{ __('Maximum memory available to the container. Set to 0 for unlimited.') }}"
                label="{{ __('Memory limit') }}" id="limitsMemory" />
            <x-forms.input canGate="update" :canResource="$resource"
                helper="{{ __('Combined memory and swap allowance. Use values such as 512m or 2g.') }}"
                label="{{ __('Memory and swap limit') }}" id="limitsMemorySwap" />
            <x-forms.input canGate="update" :canResource="$resource"
                helper="{{ __('Controls how aggressively anonymous memory is swapped. Enter a value from 0 to 100.') }}"
                type="number" min="0" max="100" label="{{ __('Swappiness') }}" id="limitsMemorySwappiness" />
        </div>
        <p class="mt-4 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
            Accepted units are <code class="font-mono">b</code>, <code class="font-mono">k</code>,
            <code class="font-mono">m</code>, and <code class="font-mono">g</code>.
        </p>
    </x-application.settings-section>
</form>
