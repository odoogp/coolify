<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Once;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    InstanceSettings::query()->forceCreate(['id' => 0]);
    Once::flush();
    if (User::count() === 0) {
        User::factory()->create();
    }
    $this->withoutVite();
});

test('login renders the mission scene with its own camera shot and heading', function () {
    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('auth-shell-mission', false)
        ->assertSee('data-scene="login"', false)
        ->assertSee('data-auth-mission', false)
        ->assertSee('data-mission-card', false)
        ->assertSee('brand/illustrations/space/mission-hero.jpg', false)
        ->assertSee('mission-hero-1920.jpg 1920w', false)
        ->assertSee(__('Welcome back'), false)
        ->assertDontSee('media/login.mp4', false);
});

test('a failed sign in marks the mission scene as error so the card shakes', function () {
    $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['email' => 'Invalid']))])
        ->get('/login')
        ->assertSuccessful()
        ->assertSee('data-auth-state="error"', false);
});

test('every auth page frames its own shot of the same plate', function () {
    $scenes = [
        'login' => 'login',
        'register' => 'register',
        'forgot-password' => 'forgot',
        'reset-password' => 'reset',
        'two-factor-challenge' => 'twofactor',
        'verify-email' => 'verify',
        'confirm-password' => 'confirm',
    ];

    foreach ($scenes as $view => $scene) {
        expect(file_get_contents(resource_path("views/auth/{$view}.blade.php")))
            ->toContain("scene=\"{$scene}\"")
            ->toContain('heading="{{ __(');
    }

    $script = file_get_contents(resource_path('js/auth-mission.js'));
    foreach (array_unique(array_values($scenes)) as $scene) {
        expect($script)->toMatch("/\\b{$scene}: \\{ focus:/");
    }
});

test('shells without a scene keep the current tunnel and stage', function () {
    $shell = file_get_contents(resource_path('views/components/auth/shell.blade.php'));

    expect($shell)
        ->toContain('@if (filled($scene))')
        ->toContain('<x-auth.mission :scene="$scene" />')
        ->toContain('<x-flow-tunnel />')
        ->toContain('auth-stage-mark-slot');

    expect(file_get_contents(resource_path('views/livewire/getodoo/plan-signup.blade.php')))->not->toContain('scene=');
    expect(file_get_contents(resource_path('views/livewire/getodoo/terms.blade.php')))->not->toContain('scene=');
});

test('mission styles are scoped, loaded after app css and respect reduced motion', function () {
    $styles = file_get_contents(resource_path('css/auth-mission.css'));
    $base = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $vite = file_get_contents(base_path('vite.config.js'));

    expect($styles)
        ->toContain('prefers-reduced-motion: reduce')
        ->toContain('@property --mission-r')
        ->toContain('gc-enter-failsafe')
        ->toMatch('/html\.gc-enter,\s*html\.gc-enter body\s*\{[^}]*background:\s*#000 !important;/s')
        ->toMatch('/button\[isHighlighted\]:not\(:disabled\),[^{]*\{[^}]*color:\s*#0a0c12 !important;/s')
        ->not->toMatch('/^\.auth-card\s*\{/m')
        ->not->toMatch('/^\.auth-shell\s*\{/m');

    expect($base)->toContain("'resources/css/theme-accent.css', 'resources/css/auth-mission.css'");
    expect($vite)->toContain('"resources/css/auth-mission.css"');
});

test('the app only hides itself for the sign-in entrance outside auth pages', function () {
    $base = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $script = file_get_contents(resource_path('js/auth-mission.js'));
    $app = file_get_contents(resource_path('js/app.js'));

    expect($base)
        ->toContain("sessionStorage.getItem('gc-enter') === '1'")
        ->toContain('login|register|forgot-password|reset-password|two-factor-challenge|email\/verify|user\/confirm-password');

    expect($script)
        ->toContain('export function bootAuthMission')
        ->toContain('export async function bootAppEnter')
        ->toContain('store.remove(ENTER_KEY)')
        ->toContain('setTimeout(closeIris, 220)')
        ->toContain("ENTER_KEY + '-mark'")
        ->toContain("{ opacity: 0 }, { opacity: 1 }");

    expect($app)->toContain("import { bootAppEnter, bootAuthMission } from './auth-mission.js';");
});

test('the NASA plate ships in both sizes with its credit', function () {
    expect(file_exists(public_path('brand/illustrations/space/mission-hero.jpg')))->toBeTrue();
    expect(file_exists(public_path('brand/illustrations/space/mission-hero-1920.jpg')))->toBeTrue();
    expect(file_get_contents(public_path('brand/illustrations/space/README.md')))->toContain('NASA');
});

test('mission copy is translated to spanish', function () {
    $es = json_decode(file_get_contents(lang_path('es.json')), true);

    foreach (['Welcome back', 'Start your mission', 'We will bring you back', 'Confirm it is you', 'Check your email', 'Secure area', 'Lunar surface', 'Earth from the Moon', 'Landing pad', 'EVA visor', 'Signal beacon'] as $key) {
        expect($es)->toHaveKey($key);
    }
});
