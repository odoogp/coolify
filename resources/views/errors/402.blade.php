@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="402"
            title="{{ __('Payment required') }}"
            description="{{ __('A valid subscription or payment is required to continue.') }}" />
    </body>
@endsection
