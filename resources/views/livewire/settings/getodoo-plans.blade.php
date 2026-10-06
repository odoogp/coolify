<div>
    <x-slot:title>
        {{ __('Plans | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <div>
                <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Plans') }}</h1>
                <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('A plan is the package a new customer buys. The link opens registration and the first payment.') }}
                </p>
            </div>

            <x-application.settings-section :title="$planId ? __('Edit plan') : __('New plan')">
                <form class="flex flex-col gap-4" wire:submit="savePlan">
                    <x-forms.input id="name" required label="{{ __('Plan name') }}" />
                    <x-forms.input id="summary" label="{{ __('Short summary') }}" />
                    <div>
                        <label class="mb-1.5 block text-sm font-medium" for="plan-description">{{ __('What is included') }}</label>
                        <textarea id="plan-description" class="input min-h-28 w-full" wire:model="description"></textarea>
                        @error('description')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                    <x-forms.input id="price" type="number" step="0.01" min="0" required
                        label="{{ __('Monthly price') }}" helper="{{ __('Use 0 for a free plan.') }}" />
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" class="rounded" wire:model="active">
                        {{ __('Active') }}
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <x-forms.button type="submit" isHighlighted>{{ __('Save plan') }}</x-forms.button>
                        @if ($planId)
                            <button type="button" class="button" wire:click="newPlan">{{ __('New plan') }}</button>
                        @endif
                    </div>
                </form>
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Signup link') }}" flush>
                @if ($plans->isEmpty())
                    <x-empty size="sm" title="{{ __('No plans yet.') }}"
                        description="{{ __('Save a plan to get the link for the website.') }}" icon-name="tags" />
                @else
                    <div class="overflow-x-auto">
                        <div class="data-table">
                            <div class="data-table-header getodoo-plans-table-grid">
                                <span>{{ __('Plan name') }}</span>
                                <span>{{ __('Monthly price') }}</span>
                                <span>{{ __('Signup link') }}</span>
                                <span></span>
                            </div>
                            @foreach ($plans as $plan)
                                <div class="data-table-row getodoo-plans-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]"
                                    wire:key="getodoo-plan-{{ $plan->id }}">
                                    <div class="min-w-0">
                                        <div class="truncate text-[13px] font-medium">{{ $plan->name }}</div>
                                        @if (! $plan->is_active)
                                            <div class="text-[11px] text-neutral-500">{{ __('Inactive') }}</div>
                                        @endif
                                    </div>
                                    <div class="text-[13px]">
                                        {{ $plan->isFree() ? __('Free') : '$'.number_format((float) $plan->price, 2) }}
                                    </div>
                                    <div class="min-w-0">
                                        <input class="input w-full font-mono text-[12px]" readonly value="{{ $plan->publicUrl() }}">
                                    </div>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" class="button" wire:click="editPlan({{ $plan->id }})">{{ __('Edit') }}</button>
                                        @if ($plan->signups_count === 0)
                                            <button type="button" class="button" wire:click="deletePlan({{ $plan->id }})"
                                                wire:confirm="{{ __('Delete this plan?') }}">{{ __('Delete') }}</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
