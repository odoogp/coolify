@props(['privateKey' => null, 'keys' => null, 'selected' => null])

@php
    $key = $privateKey;
    if (! $key instanceof \App\Models\PrivateKey && $selected !== null && $selected !== '') {
        $key = collect($keys)->first(fn ($row) => (int) data_get($row, 'id') === (int) $selected);
    }
@endphp

@if ($key instanceof \App\Models\PrivateKey)
    @can('update', $key)
        <a {{ $attributes->merge([
            'class' => 'button w-fit',
            'href' => route('security.private-key.download', ['private_key_uuid' => $key->uuid]),
        ]) }}>
            {{ __('Download key') }}
        </a>
    @endcan
@endif
