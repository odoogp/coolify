<div class="flex flex-col gap-6">
    <x-application.settings-section id="tag-assignment-section" title="{{ __('Tags') }}"
        helper="{{ __('Organize this resource with reusable team tags. Separate multiple tag names with spaces.') }}">
        @can('update', $resource)
            <form wire:submit="submit"
                class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                <x-forms.input id="newTags" label="{{ __('Tag names') }}"
                    helper="{{ __('Existing tags are assigned automatically. New names create team tags.') }}"
                    placeholder="{{ __('production api customer-a') }}" />
                <x-forms.button type="submit"
                    class="button-highlighted">
                    <x-reicon name="plus" class="size-3.5" />
                    {{ __('Add tags') }}
                </x-forms.button>
            </form>
        @else
            <x-callout type="info" title="{{ __('Need access?') }}">
                {{ __('Contact an advisor if you need access to this feature.') }}
            </x-callout>
        @endcan
    </x-application.settings-section>

    <x-application.settings-section id="assigned-tags-section" title="{{ __('Assigned tags') }}"
        helper="{{ __('Tags currently attached to this resource.') }}" flush>
        @forelse (data_get($this->resource, 'tags', []) as $tag)
            <div wire:key="assigned-tag-{{ $tag->id }}"
                class="flex min-h-12 items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.07]">
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                    <x-reicon name="tags" class="size-4" />
                </div>
                <span class="min-w-0 flex-1 truncate text-sm font-medium text-black dark:text-fg">
                    {{ $tag->name }}
                </span>
                @can('update', $resource)
                    <x-forms.button wire:click="deleteTag('{{ $tag->id }}')"
                        class="h-7! text-neutral-500 dark:text-fg-dim">
                        {{ __('Remove') }}
                    </x-forms.button>
                @endcan
            </div>
        @empty
            <x-empty size="sm" title="{{ __('No tags assigned') }}"
                description="{{ __('Add a tag above or select one from your team\'s available tags.') }}"
                icon-name="tags" />
        @endforelse
    </x-application.settings-section>

    @can('update', $resource)
        @if (count($filteredTags) > 0)
            <x-application.settings-section id="available-tags-section" title="{{ __('Available tags') }}"
                helper="{{ __('Assign an existing team tag with one click.') }}">
                <div class="flex flex-wrap gap-2">
                    @foreach ($filteredTags as $tag)
                        <x-forms.button wire:key="available-tag-{{ $tag->id }}"
                            wire:click="addTag('{{ $tag->id }}', '{{ $tag->name }}')">
                            <x-reicon name="plus" class="size-3.5 text-coollabs dark:text-warning" />
                            {{ $tag->name }}
                        </x-forms.button>
                    @endforeach
                </div>
            </x-application.settings-section>
        @endif
    @endcan
</div>
