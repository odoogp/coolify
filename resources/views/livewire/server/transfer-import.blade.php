<div class="w-full">
    <x-slot:title>
        {{ __('Import server transfer | Coolify') }}
    </x-slot>
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-2">
            <h1>Import server transfer <x-status-badge label="{{ __('Dev') }}" /></h1>
            <a href="{{ route('server.index') }}" {{ wireNavigate() }}>
                <x-forms.button>{{ __('Back to servers') }}</x-forms.button>
            </a>
        </div>
        <div class="subtitle">
            {{ __('Paste or upload a transfer bundle exported from another Coolify instance. This creates the server and its') }}
            resources under the current team and <strong>claims</strong> the host for this instance
            (control-plane only — host data stays on the machine).
        </div>

        <div class="flex flex-col gap-4 rounded-lg border border-neutral-200 p-4 dark:border-coolgray-200">
            <x-forms.input type="file" id="bundleFile" label="{{ __('Bundle file (.json)') }}" accept=".json,application/json" />
            <x-forms.textarea id="bundleJson" label="{{ __('Or paste bundle JSON') }}" rows="14" placeholder='{"schema_version":1,...}' />
            <x-forms.input id="passphrase" type="password" label="{{ __('Passphrase (if encrypted)') }}" placeholder="{{ __('Optional') }}" />
            <div class="flex flex-col gap-2">
                <x-forms.checkbox id="preserveUuids" label="{{ __('Preserve UUIDs from the source instance') }}" />
                <x-forms.checkbox id="adoptMode" label="{{ __('Adopt mode (keep statuses; do not force exited redeploy)') }}" />
                <x-forms.checkbox id="writeRemote" label="{{ __('Also write ownership file on the host via SSH (optional)') }}" />
            </div>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Import a server that you downloaded from another Coolify instance. This instance then manages it.
            </p>
        </div>
        <a href="{{ route('server.index') }}" class="button w-fit shrink-0" {{ wireNavigate() }}>
            Back to servers
        </a>
    </div>

    <div class="application-settings-form flex flex-col gap-6">
        <x-application.settings-section id="server-import-file-section" title="Transfer file"
            helper="On the source instance, open the server, go to Transfer, and click Download JSON in Manual transfer. The server data on the machine does not change.">
            <x-slot:actions>
                <x-forms.button wire:click="dryRun" wire:loading.attr="disabled" wire:target="dryRun,importBundle">
                    <span wire:loading.remove wire:target="dryRun">{{ __('Dry run') }}</span>
                    <span wire:loading wire:target="dryRun">{{ __('Checking…') }}</span>
                </x-forms.button>
                <x-forms.button wire:click="importBundle" wire:loading.attr="disabled"
                    wire:target="dryRun,importBundle"
                    wire:confirm="Import this server into the current team?">
                    <span wire:loading.remove wire:target="importBundle">{{ __('Import server') }}</span>
                    <span wire:loading wire:target="importBundle">{{ __('Importing…') }}</span>
                </x-forms.button>
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="server-import-options-section" title="Options">
            <div class="flex flex-col gap-1 md:max-w-xl">
                <x-forms.checkbox id="preserveUuids" label="Keep UUIDs from the source instance"
                    helper="Resources keep the same UUIDs, so their names, containers, and webhook URLs stay the same." />
                <x-forms.checkbox id="adoptMode" label="Adopt running resources"
                    helper="Keep the current status of each resource and do not redeploy stopped resources." />
            </div>
        </x-application.settings-section>

        @if (count($lastWarnings) > 0)
            <div class="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                <div class="mb-1 font-semibold text-warning">{{ __('Warnings') }}</div>
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($lastWarnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-callout>
        @endif

        @if ($lastResult)
            <div class="rounded-lg border border-neutral-200 p-4 dark:border-coolgray-200">
                <div class="mb-2 font-semibold">
                    {{ data_get($lastResult, 'dry_run') ? __('Dry-run result') : __('Import result') }}
                </div>
                @if ($importedServerUuid)
                    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                        <span>{{ __('Server UUID:') }} <code class="font-mono">{{ $importedServerUuid }}</code></span>
                        @if (data_get($lastResult, 'claimed'))
                            <span class="text-success">{{ __('Claimed') }}</span>
                        @endif
                        <a href="{{ route('server.show', ['server_uuid' => $importedServerUuid]) }}" {{ wireNavigate() }}>
                            <x-forms.button>{{ __('Open server') }}</x-forms.button>
                        </a>
                        <a href="{{ route('server.transfer', ['server_uuid' => $importedServerUuid]) }}" {{ wireNavigate() }}>
                            <x-forms.button>{{ __('Transfer details') }}</x-forms.button>
                        </a>
                    </div>
                @endif
                <pre class="max-h-80 overflow-auto rounded-lg bg-neutral-100 p-3 text-xs dark:bg-coolgray-100">{{ json_encode($lastResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endif
    </div>
</div>
