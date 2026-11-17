<?php

use App\Models\OauthSetting;

it('uses per-provider enable buttons with browser validation', function () {
    $view = file_get_contents(resource_path('views/livewire/settings-oauth.blade.php'));

    expect($view)
        ->toContain("{{ \$oauth_setting['enabled'] ? 'Disable' : 'Enable' }}")
        ->toContain('x-data="{ enabled: @js((bool) $oauth_setting[\'enabled\']), provider: @js($provider) }"')
        ->toContain('invalidField.reportValidity()')
        ->toContain('$wire.toggleProvider(provider)')
        ->toContain('label="{{ __(\'Client ID\') }}" required')
        ->toContain('autocomplete="new-password" required')
        ->not->toContain('label="Provider status"');
});

it('requires the provider-specific fields used by oauth enablement', function () {
    $component = new SettingsOauth;
    $method = new ReflectionMethod($component, 'providerRules');

    expect($method->invoke($component, 'github'))->toBe([
        'oauth_settings_map.github.client_id' => 'required',
        'oauth_settings_map.github.client_secret' => 'required',
    ])->and($method->invoke($component, 'azure'))->toHaveKeys([
        'oauth_settings_map.azure.client_id',
        'oauth_settings_map.azure.client_secret',
        'oauth_settings_map.azure.tenant',
    ])->and($method->invoke($component, 'authentik'))->toHaveKeys([
        'oauth_settings_map.authentik.client_id',
        'oauth_settings_map.authentik.client_secret',
        'oauth_settings_map.authentik.base_url',
    ]);
});

it('uses monochrome brand logos for each oauth provider in the sidebar', function () {
    $view = file_get_contents(resource_path('views/livewire/settings-oauth.blade.php'));

    $providers = [
        'authentik',
        'azure',
        'bitbucket',
        'clerk',
        'discord',
        'github',
        'gitlab',
        'google',
        'infomaniak',
        'zitadel',
    ];

    expect((new OauthSetting($complete))->couldBeEnabled())->toBeTrue();

    foreach ($requiredFields as $field) {
        expect((new OauthSetting([...$complete, $field => null]))->couldBeEnabled())
            ->toBeFalse("{$provider} must require {$field}");
    }
})->with([
    'github' => ['github', ['client_id', 'client_secret']],
    'azure' => ['azure', ['client_id', 'client_secret', 'tenant']],
    'authentik' => ['authentik', ['client_id', 'client_secret', 'base_url']],
    'clerk' => ['clerk', ['client_id', 'client_secret', 'base_url']],
]);
