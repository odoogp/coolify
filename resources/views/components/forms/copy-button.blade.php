{{-- Alias kept for call sites that still use forms.copy-button (renamed to copy-input). --}}
@props(['text', 'label' => null])

<x-forms.copy-input :text="$text" :label="$label" {{ $attributes }} />
