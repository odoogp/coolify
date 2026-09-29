@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="419"
            title="{{ __('This page is definitely old, not like you!') }}"
            description="{{ __('Your session has expired. Please log in again to continue.') }}"
            :show-go-back="false"
            :show-dashboard="false"
            primary-href="/login"
            primary-label="{{ __('Back to login') }}">
            <x-forms.collapsible title="{{ __('Using a reverse proxy or Cloudflare Tunnel?') }}" class="error-proxy-help">
                <ul>
                    <li>{{ __('Set your domain in') }} <strong>{{ __('Settings &rarr; FQDN') }}</strong> to match the URL you use to access Coolify.</li>
                    <li>{{ __('Cloudflare users: disable') }} <strong>{{ __('Browser Integrity Check') }}</strong> and <strong>{{ __('Under Attack Mode') }}</strong> for your Coolify domain, as these can interrupt login sessions.</li>
                    <li>{{ __('If you can still access Coolify via') }} <code>localhost</code>, log in there first to configure your FQDN.</li>
                </ul>
            </x-forms.collapsible>
        </x-error-page>
        @livewireScripts
    </body>
@endsection
