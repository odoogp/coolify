@if ($availableS3Storages->isEmpty())
    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>{{ __('S3 storage') }}</h2>
                <p>{{ __('Send backup archives to a validated object storage destination.') }}</p>
            </div>
        </div>
        <div class="application-settings-section-body is-flush">
            <x-empty title="{{ __('No validated S3 storage') }}"
                description="{{ __('Add and validate an S3 storage destination before enabling remote backups.') }}"
                icon-name="storages">
                <x-slot:contents>
                    <a class="button" {{ wireNavigate() }} href="{{ route('storage.index') }}">{{ __('Open S3 storage') }}</a>
                </x-slot:contents>
            </x-empty>
        </div>
    </section>
@else
    <form wire:submit="submit">
        <x-unsaved-bar action="submit" />

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('S3 storage') }}</h2>
                    <p>{{ __('Choose where remote backups are stored and whether local copies are retained.') }}</p>
                </div>
                @if (! $saveS3)
                    <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                        wire:target="toggleS3" isHighlighted>{{ __('Enable S3') }}</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                        wire:target="toggleS3">{{ __('Disable S3') }}</x-forms.button>
                @endif
            </div>
            <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                <x-forms.listbox id="s3StorageId" label="{{ __('S3 storage') }}" :required="$saveS3"
                    :options="$availableS3Storages->map(fn ($s3) => [
                        'value' => $s3->id,
                        'label' => $s3->name,
                    ])->values()->all()" />
                <x-forms.listbox id="disableLocalBackup" label="{{ __('Local copy') }}" :disabled="! $saveS3"
                    :options="[
                        ['value' => false, 'label' => __('Keep local backup')],
                        ['value' => true, 'label' => __('Delete after S3 upload')],
                    ]" />
            </div>
        </section>
    </form>
@endif
