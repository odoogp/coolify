@php
    $canUpdate = auth()->user()->can('update', $resource);
@endphp

<form wire:submit="submit" class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section id="healthcheck-configuration-section" title="{{ __('Healthcheck') }}"
        helper="{{ __('Define how Coolify determines whether this application is ready to receive traffic.') }}">
        <x-slot:actions>
            @if (!$healthCheckEnabled)
                <x-modal-confirmation :disabled="!auth()->user()->can('update', $resource)"
                    :authDisabled="!auth()->user()->can('update', $resource)"
                    title="{{ __('Enable healthcheck?') }}" buttonTitle="Enable"
                    submitAction="toggleHealthcheck" :actions="['Enable healthcheck for this resource.']"
                    warningMessage="If the healthcheck fails, your application will become inaccessible. Review the <a href='https://coolify.io/docs/knowledge-base/health-checks' target='_blank' class='underline text-white'>{{ __('Healthchecks guide') }}</a> before continuing."
                    step2ButtonText="Enable healthcheck" :confirmWithText="false"
                    :confirmWithPassword="false" isHighlightedButton />
            @else
                <x-forms.button canGate="update" :canResource="$resource" type="button"
                    wire:click="toggleHealthcheck">
                    {{ __('Disable') }}
                </x-forms.button>
            @endif
        </x-slot:actions>

        @if ($customHealthcheckFound)
            <div class="mb-4">
                <x-callout type="warning" title="{{ __('Custom healthcheck detected') }}">
                    {{ __('Enabling this configuration replaces the custom healthcheck currently defined by the application.') }}
                </x-callout>
            </div>
        @endif

        <div class="max-w-xs">
            <x-forms.listbox id="healthCheckType" label="{{ __('Check type') }}" required live :options="[
                ['value' => 'http', 'label' => __('HTTP request')],
                ['value' => 'cmd', 'label' => __('Container command')],
            ]" :disabled="! $canUpdate" />
        </div>
    </x-application.settings-section>

    @if ($healthCheckType === 'http')
        <x-application.settings-section id="healthcheck-request-section" title="{{ __('HTTP request') }}"
            helper="{{ __('Coolify sends this request from inside the container and evaluates the response.') }}">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <x-forms.listbox id="healthCheckMethod" label="{{ __('Method') }}" required :options="[
                    ['value' => 'GET', 'label' => __('GET')],
                    ['value' => 'HEAD', 'label' => __('HEAD')],
                    ['value' => 'POST', 'label' => __('POST')],
                    ['value' => 'OPTIONS', 'label' => __('OPTIONS')],
                ]" :disabled="! $canUpdate" />
                <x-forms.listbox id="healthCheckScheme" label="{{ __('Scheme') }}" required :options="[
                    ['value' => 'http', 'label' => __('HTTP')],
                    ['value' => 'https', 'label' => __('HTTPS')],
                ]" :disabled="! $canUpdate" />
                <x-forms.input canGate="update" :canResource="$resource" id="healthCheckHost"
                    placeholder="{{ __('localhost') }}" label="{{ __('Host') }}" required />
                <x-forms.input canGate="update" :canResource="$resource" type="number"
                    id="healthCheckPort" helper="{{ __('Uses the first exposed port when empty.') }}" placeholder="80"
                    label="{{ __('Port') }}" />
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_10rem]">
                <x-forms.input canGate="update" :canResource="$resource" id="healthCheckPath"
                    placeholder="/health" label="{{ __('Path') }}" required />
                <x-forms.input canGate="update" :canResource="$resource" type="number"
                    id="healthCheckReturnCode" placeholder="200" label="{{ __('Expected code') }}" required />
            </div>
            <div class="mt-4">
                <x-forms.input canGate="update" :canResource="$resource" id="healthCheckResponseText"
                    placeholder="{{ __('OK') }}" label="{{ __('Expected response text') }}"
                    helper="{{ __('Leave empty when the response body does not need to contain specific text.') }}" />
            </div>
        </x-application.settings-section>
    @else
        <x-application.settings-section id="healthcheck-command-section" title="{{ __('Container command') }}"
            helper="{{ __('The command must exit with code 0 for the container to be considered healthy.') }}">
            <x-callout type="warning" title="{{ __('Shell operators are not supported') }}">
                The command runs directly inside the container. Operators such as ;, |, &amp;, $, &gt;, and &lt;
                are blocked.
            </x-callout>
            <div class="mt-4">
                <x-forms.input canGate="update" :canResource="$resource" id="healthCheckCommand"
                    label="{{ __('Command') }}" placeholder="{{ __('pg_isready -U postgres') }}"
                    helper="{{ __('Use a single executable command without shell expansion.') }}"
                    :required="$healthCheckType === 'cmd'" />
            </div>
        </x-application.settings-section>
    @endif

    <x-application.settings-section id="healthcheck-timing-section" title="{{ __('Timing and retries') }}"
        helper="{{ __('Control how quickly healthchecks start, repeat, and fail.') }}">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-forms.input canGate="update" :canResource="$resource" min="1" type="number"
                id="healthCheckInterval" placeholder="30" label="{{ __('Interval (seconds)') }}" required />
            <x-forms.input canGate="update" :canResource="$resource" min="1" type="number"
                id="healthCheckTimeout" placeholder="30" label="{{ __('Timeout (seconds)') }}" required />
            <x-forms.input canGate="update" :canResource="$resource" min="1" type="number"
                id="healthCheckRetries" placeholder="3" label="{{ __('Retries') }}" required />
            <x-forms.input canGate="update" :canResource="$resource" min="1" type="number"
                id="healthCheckStartPeriod" placeholder="30" label="{{ __('Start period (seconds)') }}" required />
        </div>
    </x-application.settings-section>
</form>
