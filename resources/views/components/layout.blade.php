{{--
    Back-compat alias. Prefer layouts.app (has {{ $slot }} in the authenticated shell).
    Livewire page components should use ->layout('layouts.app') or the config default.
--}}
@extends('layouts.app')
