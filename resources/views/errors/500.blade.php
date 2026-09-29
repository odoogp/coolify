@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="500"
            title="{{ __('Wait, this is not cool...') }}"
            description="{{ __('There has been an error with the following error message:') }}"
            tone="danger">
            @if ($exception->getMessage() !== '')
                <div class="error-message">
                    {!! Purify::clean($exception->getMessage()) !!}
                </div>
            @endif
        </x-error-page>
    </body>
@endsection
