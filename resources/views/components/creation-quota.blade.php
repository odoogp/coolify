@props(['quota' => null])

@if (is_array($quota))
    <p {{ $attributes->merge(['class' => 'text-[12px] text-neutral-500 dark:text-fg-dim']) }}>
        @foreach (['projects' => 'Projects', 'environments' => 'Environments', 'members' => 'Members'] as $key => $label)
            <span @class(['ml-2' => ! $loop->first])>{{ $label }}: {{ $quota[$key]['used'] }}@if ($quota[$key]['limit'] !== null)/{{ $quota[$key]['limit'] }}@endif</span>
        @endforeach
    </p>
@endif
