@props([
    'id',
    'label' => null,
    'helper' => null,
    'disabled' => false,
])

<div {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'flex w-full min-w-0 flex-col gap-1.5']) }}>
    @if ($label)
        <div class="flex h-4 items-center gap-1.5">
            <label class="text-sm font-medium leading-4" for="{{ $id }}-surface">{{ $label }}</label>
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </div>
    @endif

    <div x-data="htmlEditor" x-modelable="html" {{ $attributes->wire('model') }}
        @class([
            'overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-white/[0.08] dark:bg-raised',
            'pointer-events-none opacity-60' => $disabled,
        ])>
        <div
            class="flex flex-wrap items-center gap-1 border-b border-neutral-200 bg-neutral-50 px-2 py-1.5 dark:border-white/[0.08] dark:bg-white/[0.03]">
            <button type="button" class="button px-2 py-1 text-[12px] font-semibold" @click="command('bold')"
                title="{{ __('Bold') }}">B</button>
            <button type="button" class="button px-2 py-1 text-[12px] italic" @click="command('italic')"
                title="{{ __('Italic') }}">I</button>
            <button type="button" class="button px-2 py-1 text-[12px] underline" @click="command('underline')"
                title="{{ __('Underline') }}">U</button>
            <span class="mx-1 h-4 w-px bg-neutral-200 dark:bg-white/10"></span>
            <button type="button" class="button px-2 py-1 text-[12px]" @click="block('H1')">H1</button>
            <button type="button" class="button px-2 py-1 text-[12px]" @click="block('H2')">H2</button>
            <button type="button" class="button px-2 py-1 text-[12px]" @click="block('H3')">H3</button>
            <button type="button" class="button px-2 py-1 text-[12px]" @click="block('P')">{{ __('Paragraph') }}</button>
            <span class="mx-1 h-4 w-px bg-neutral-200 dark:bg-white/10"></span>
            <button type="button" class="button px-2 py-1 text-[12px]" @click="command('insertUnorderedList')"
                title="{{ __('Bullet list') }}">•</button>
            <button type="button" class="button px-2 py-1 text-[12px]" @click="command('insertOrderedList')"
                title="{{ __('Numbered list') }}">1.</button>
            <button type="button" class="button px-2 py-1 text-[12px]" @click="createLink()"
                title="{{ __('Link') }}">{{ __('Link') }}</button>
            <span class="mx-1 h-4 w-px bg-neutral-200 dark:bg-white/10"></span>
            <label class="flex items-center gap-1 px-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                <span>{{ __('Color') }}</span>
                <input type="color" class="h-6 w-8 cursor-pointer rounded border-0 bg-transparent p-0"
                    x-model="color" @change="applyColor()" @click.stop>
            </label>
        </div>
        <div id="{{ $id }}-surface" x-ref="surface" contenteditable="{{ $disabled ? 'false' : 'true' }}"
            class="html-editor-surface prose prose-sm max-w-none min-h-56 px-3 py-3 text-[14px] leading-6 text-neutral-800 outline-none dark:prose-invert dark:text-fg"
            @input="sync()" @blur="sync()"></div>
    </div>
</div>
