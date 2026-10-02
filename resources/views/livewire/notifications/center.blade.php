<div>
    <x-slot:title>
        {{ __('Notification center') }}
    </x-slot>

    <x-notification.settings-layout>
        <div class="flex flex-col gap-6">
            <form wire:submit="saveSettings" class="application-settings-form">
                <x-application.settings-section title="{{ __('Which notices to show') }}"
                    description="{{ __('Turn a kind off to hide it and to stop sending it.') }}">
                    <div class="grid gap-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="showMounted" class="rounded border-neutral-300 dark:border-white/20" />
                            {{ __('Instance is up') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="showAccessible" class="rounded border-neutral-300 dark:border-white/20" />
                            {{ __('Instance is accessible') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="showExpiration" class="rounded border-neutral-300 dark:border-white/20" />
                            {{ __('Expirations') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="showDeletion" class="rounded border-neutral-300 dark:border-white/20" />
                            {{ __('Instances to delete') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="showCustom" class="rounded border-neutral-300 dark:border-white/20" />
                            {{ __('Custom messages') }}
                        </label>
                    </div>
                    <label class="mt-4 grid w-fit gap-1.5 text-sm font-medium">
                        {{ __('Notices disappear after') }}
                        <span class="flex items-center gap-2 font-normal">
                            <input type="number" min="1" max="365" wire:model="keepDays" class="input w-24" />
                            {{ __('days') }}
                        </span>
                    </label>
                    @error('keepDays')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                    <div class="mt-4">
                        <button type="submit" class="button button-highlighted">{{ __('Save') }}</button>
                    </div>
                </x-application.settings-section>
            </form>

            <form wire:submit="send" class="application-settings-form">
                <x-application.settings-section title="{{ __('Send a notice') }}"
                    description="{{ __('Clients see notices marked for them. The owner sees every notice.') }}">
                    <div class="grid gap-4">
                        <x-forms.input id="title" label="{{ __('Title') }}" required />
                        <x-forms.textarea id="body" label="{{ __('Message') }}" required rows="4" />
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="grid gap-1.5 text-sm font-medium">
                                {{ __('Audience') }}
                                <select wire:model="audience" class="input">
                                    <option value="clients">{{ __('For clients') }}</option>
                                    <option value="owner">{{ __('For the owner') }}</option>
                                </select>
                            </label>
                            <label class="grid gap-1.5 text-sm font-medium">
                                {{ __('Kind') }}
                                <select wire:model="kind" class="input">
                                    <option value="custom">{{ __('Custom messages') }}</option>
                                    <option value="expiration">{{ __('Expirations') }}</option>
                                    <option value="deletion">{{ __('Instances to delete') }}</option>
                                </select>
                            </label>
                            <label class="grid gap-1.5 text-sm font-medium">
                                {{ __('Client') }}
                                <select wire:model="teamId" class="input">
                                    <option value="">{{ __('All clients') }}</option>
                                    @foreach ($teams as $team)
                                        <option value="{{ $team->id }}">{{ $team->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        @error('teamId')
                            <p class="text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="button button-highlighted">{{ __('Send notice') }}</button>
                    </div>
                </x-application.settings-section>
            </form>

            <x-application.settings-section title="{{ __('Notices') }}">
                <div class="divide-y divide-neutral-200 dark:divide-white/[0.06]">
                    @forelse ($notices as $notice)
                        <div class="py-3" wire:key="notice-{{ $notice->id }}">
                            <div class="flex flex-wrap items-center gap-2 text-[11px] text-neutral-500 dark:text-fg-faint">
                                <span>{{ $notice->kindLabel() }}</span>
                                <span>{{ $notice->audienceLabel() }}</span>
                                <span>{{ $notice->created_at?->diffForHumans() }}</span>
                            </div>
                            @php($noticeHref = $notice->href())
                            @if ($noticeHref)
                                <a href="{{ $noticeHref }}" @unless (str_starts_with($noticeHref, url('/'))) target="_blank" rel="noopener" @endunless
                                    class="mt-1 block text-sm font-medium hover:underline">{{ $notice->title }}</a>
                            @else
                                <p class="mt-1 text-sm font-medium">{{ $notice->title }}</p>
                            @endif
                            <p class="mt-1 text-sm text-neutral-600 dark:text-fg-dim">{{ $notice->body }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-neutral-500 dark:text-fg-faint">{{ __('No notices') }}</p>
                    @endforelse
                </div>
            </x-application.settings-section>
        </div>
    </x-notification.settings-layout>
</div>
