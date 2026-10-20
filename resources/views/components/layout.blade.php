{{--
    Livewire page layout (config/livewire.php). Livewire renders this as a Blade
    @component and forwards the page into $slot; layouts.app echoes that slot
    inside the authenticated shell. Do not point config at layouts.app directly:
    that view @extends layouts.base and 500s when used as a Livewire component.
--}}
@extends('layouts.app')
