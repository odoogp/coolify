@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="429"
            title="{{ __('Woah, slow down there!') }}"
            description="{{ __('You\'re making too many requests. Please wait a few seconds before trying again.') }}" />
    </body>
@endsection
