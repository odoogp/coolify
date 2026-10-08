@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="403"
            title="{{ __('You shall not pass!') }}"
            description="{{ __('Contact an advisor if you need access to this page.') }}" />
    </body>
@endsection
