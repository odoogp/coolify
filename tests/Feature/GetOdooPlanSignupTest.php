<?php

use App\Livewire\GetOdoo\PlanSignup;
use App\Livewire\Settings\GetOdooPlans;
use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\WompiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->root = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
});

function getOdooPlan(array $overrides = []): GetOdooPlan
{
    return GetOdooPlan::query()->create(array_merge([
        'name' => 'Oficina',
        'summary' => 'Para el equipo',
        'description' => "Producción\nStaging",
        'price' => 93,
        'currency' => 'USD',
        'payment_gateway' => 'wompi',
        'is_active' => true,
    ], $overrides));
}

function getOdooPlanWompi(): void
{
    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
        'is_registration_enabled' => false,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'id.wompi.sv/connect/token')) {
            return Http::response(['access_token' => 'token-1']);
        }

        if (str_contains($url, 'api.wompi.sv/EnlacePago') && $request->method() === 'POST') {
            return Http::response([
                'idEnlace' => 15,
                'urlEnlace' => 'https://lk.wompi.sv/plan',
            ]);
        }

        return Http::response(['error' => 'unexpected '.$url], 500);
    });
}

it('keeps the normal registration page', function () {
    instanceSettings()->update(['is_registration_enabled' => true]);

    $this->get(route('register'))->assertOk()->assertSee('Create account');
});

it('lets the instance owner save a plan and copy its link', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPlans::class)
        ->set('name', 'Oficina')
        ->set('summary', 'Para el equipo')
        ->set('price', '0')
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSee('Oficina')
        ->assertSee('/start/');

    $plan = GetOdooPlan::query()->first();

    expect($plan)->not->toBeNull()
        ->and((float) $plan->price)->toBe(0.0)
        ->and($plan->payment_gateway)->toBeNull()
        ->and($plan->publicUrl())->toContain('/start/'.$plan->uuid);
});

it('hides plan settings from a customer admin', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(GetOdooPlans::class)->assertRedirect(route('dashboard'));
});

it('shows the package and the admin account on the public link', function () {
    User::factory()->create();
    $plan = getOdooPlan();

    $this->get(route('getodoo.plan.start', ['plan' => $plan->uuid]))
        ->assertOk()
        ->assertSee('Oficina')
        ->assertSee('Para el equipo')
        ->assertSee('Producción')
        ->assertSee('Admin account')
        ->assertSee('$93.00')
        ->assertSee('Continue to payment');
});

it('does not open an inactive plan', function () {
    User::factory()->create();
    $plan = getOdooPlan(['is_active' => false]);

    $this->get(route('getodoo.plan.start', ['plan' => $plan->uuid]))->assertNotFound();
});

it('opens a free plan without Wompi and without using the public registration switch', function () {
    User::factory()->create();
    instanceSettings()->update(['is_registration_enabled' => false]);
    $plan = getOdooPlan([
        'price' => 0,
        'payment_gateway' => null,
    ]);

    Livewire::test(PlanSignup::class, ['plan' => $plan->uuid])
        ->set('name', 'Ana')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->set('email', 'ana@example.com')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $customer = User::query()->where('email', 'ana@example.com')->first();

    expect($customer)->not->toBeNull()
        ->and((int) $customer->id)->not->toBe(0)
        ->and((int) $customer->teams()->first()->getodoo_plan_id)->toBe($plan->id)
        ->and(GetOdooPlanSignup::query()->where('email', 'ana@example.com')->value('status'))->toBe('paid');
});

it('sends a paid plan to Wompi and creates the account only after a live charge', function () {
    User::factory()->create();
    getOdooPlanWompi();
    $plan = getOdooPlan();

    Livewire::test(PlanSignup::class, ['plan' => $plan->uuid])
        ->set('name', 'Ana')
        ->set('email', 'ana@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->call('register')
        ->assertRedirect('https://lk.wompi.sv/plan');

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();

    $signup = GetOdooPlanSignup::query()->first();
    $payload = [
        'IdTransaccion' => 'tx-plan-1',
        'Monto' => 93,
        'ResultadoTransaccion' => 'ExitosaAprobada',
        'EsProductiva' => true,
        'EnlacePago' => [
            'IdentificadorEnlaceComercio' => $signup->uuid,
        ],
    ];
    $body = json_encode($payload);
    $hash = app(WompiClient::class)->webhookHash($body);
    $headers = [
        'HTTP_WOMPI_HASH' => $hash,
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', '/webhooks/payments/wompi', [], [], [], $headers, $body)->assertOk();
    $this->call('POST', '/webhooks/payments/wompi', [], [], [], $headers, $body)->assertOk();

    $customer = User::query()->where('email', 'ana@example.com')->first();

    expect($customer)->not->toBeNull()
        ->and(User::query()->where('email', 'ana@example.com')->count())->toBe(1)
        ->and((int) $customer->teams()->first()->getodoo_plan_id)->toBe($plan->id)
        ->and($signup->fresh()->status)->toBe('paid')
        ->and($signup->fresh()->password)->toBeNull();
});

it('does not create an account from a test charge', function () {
    User::factory()->create();
    getOdooPlanWompi();
    $plan = getOdooPlan();
    $signup = $plan->signups()->create([
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'password1',
        'amount' => 93,
        'payment_gateway' => 'wompi',
        'status' => 'awaiting_payment',
    ]);
    $payload = [
        'IdTransaccion' => 'tx-plan-test',
        'Monto' => 93,
        'ResultadoTransaccion' => 'ExitosaAprobada',
        'EsProductiva' => false,
        'EnlacePago' => [
            'IdentificadorEnlaceComercio' => $signup->uuid,
        ],
    ];
    $body = json_encode($payload);

    $this->call('POST', '/webhooks/payments/wompi', [], [], [], [
        'HTTP_WOMPI_HASH' => app(WompiClient::class)->webhookHash($body),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse()
        ->and($signup->fresh()->status)->toBe('awaiting_payment');
});
