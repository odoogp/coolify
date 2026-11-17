{{--
    Livewire page layout (config/livewire.php) and <x-layout> wrapper.

    Livewire renders this as @component and puts the page into $slot. A bare
    @extends('layouts.app') does not reliably forward that slot into the
    authenticated shell (blank Keys & Tokens: no <main>, body.innerText === 0).
    Forward it through an explicit @section that layouts.app yields inside <main>.
--}}
@extends('layouts.app')

@section('page')
    {{ $slot }}
@endsection
