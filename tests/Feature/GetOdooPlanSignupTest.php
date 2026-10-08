<?php

use App\Livewire\GetOdoo\PlanSignup;
use App\Livewire\Settings\GetOdooPlans;
use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooPricingArea;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\GetOdooPlanPricing;
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
        'max_projects' => 1,
        'max_environments' => 2,
        'max_members' => 1,
        'max_production_branches' => 1,
        'max_staging_branches' => 1,
        'max_services' => 2,
        'can_add_servers' => true,
        'can_launch_on_instance_server' => false,
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
        ->set('maxProjects', '1')
        ->set('maxEnvironments', '2')
        ->set('maxMembers', '1')
        ->set('canAddServers', true)
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSee('Oficina')
        ->assertSee('/start/');

    $plan = GetOdooPlan::query()->first();

    expect($plan)->not->toBeNull()
        ->and((float) $plan->price)->toBe(0.0)
        ->and($plan->payment_gateway)->toBeNull()
        ->and((int) $plan->max_projects)->toBe(1)
        ->and((int) $plan->max_environments)->toBe(2)
        ->and((int) $plan->max_members)->toBe(1)
        ->and($plan->max_services)->toBeNull()
        ->and($plan->can_add_servers)->toBeTrue()
        ->and($plan->can_launch_on_instance_server)->toBeFalse()
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
        ->assertSee('Projects')
        ->assertSee('Environments')
        ->assertSee('Can add servers')
        ->assertDontSee('Can launch instances on the server where GPSH is installed')
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
    $team = $customer?->teams()->first();

    expect($customer)->not->toBeNull()
        ->and((int) $customer->id)->not->toBe(0)
        ->and($customer->teams()->count())->toBe(1)
        ->and((int) $team->getodoo_plan_id)->toBe($plan->id)
        ->and($team->pivot->role)->toBe('admin')
        ->and((int) $team->pivot->max_projects)->toBe(1)
        ->and((int) $team->pivot->max_environments)->toBe(2)
        ->and((bool) $team->pivot->can_add_servers)->toBeTrue()
        ->and((bool) $team->pivot->can_launch_on_instance_server)->toBeFalse()
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
    $team = $customer?->teams()->first();

    expect($customer)->not->toBeNull()
        ->and(User::query()->where('email', 'ana@example.com')->count())->toBe(1)
        ->and($team->pivot->role)->toBe('admin')
        ->and((int) $team->pivot->max_services)->toBe(2)
        ->and((int) $team->getodoo_plan_id)->toBe($plan->id)
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

it('stacks region and country extras on the plan price', function () {
    $plan = getOdooPlan(['price' => 100]);
    $region = GetOdooPricingArea::query()->create([
        'code' => 'latam',
        'name' => 'LATAM',
        'kind' => GetOdooPricingArea::KIND_REGION,
        'extra_fixed' => 5,
        'extra_percent' => 10,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $country = GetOdooPricingArea::query()->create([
        'code' => 'sv',
        'name' => 'El Salvador',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'parent_id' => $region->id,
        'iso_code' => 'SV',
        'extra_fixed' => 2,
        'extra_percent' => 5,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $quote = GetOdooPlanPricing::quote($plan, $country);

    // 100 * 1.15 + 5 + 2 = 122
    expect($quote['extra_percent'])->toBe(15.0)
        ->and($quote['extra_fixed'])->toBe(7.0)
        ->and($quote['amount'])->toBe(122.0);
});

it('lets the owner save a country under a region', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPlans::class)
        ->set('areaKind', 'region')
        ->set('areaName', 'LATAM')
        ->set('areaCode', 'latam')
        ->set('areaExtraPercent', '10')
        ->set('areaExtraFixed', '5')
        ->call('saveArea')
        ->assertHasNoErrors();

    $region = GetOdooPricingArea::query()->where('code', 'latam')->first();
    expect($region)->not->toBeNull()->and($region->isRegion())->toBeTrue();

    Livewire::test(GetOdooPlans::class)
        ->set('areaKind', 'country')
        ->set('areaName', 'El Salvador')
        ->set('areaCode', 'sv')
        ->set('areaIso', 'SV')
        ->set('areaParentId', (string) $region->id)
        ->set('areaExtraPercent', '0')
        ->set('areaExtraFixed', '3')
        ->call('saveArea')
        ->assertHasNoErrors()
        ->assertSee('El Salvador');

    expect(GetOdooPricingArea::countryChoices())->toHaveCount(1)
        ->and(GetOdooPricingArea::countryChoices()[0]['label'])->toContain('LATAM');
});

it('requires a country on signup when countries are configured and charges the quoted amount', function () {
    User::factory()->create();
    getOdooPlanWompi();
    $plan = getOdooPlan(['price' => 100]);
    $region = GetOdooPricingArea::query()->create([
        'code' => 'latam',
        'name' => 'LATAM',
        'kind' => GetOdooPricingArea::KIND_REGION,
        'extra_fixed' => 0,
        'extra_percent' => 10,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $country = GetOdooPricingArea::query()->create([
        'code' => 'gt',
        'name' => 'Guatemala',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'parent_id' => $region->id,
        'iso_code' => 'GT',
        'extra_fixed' => 5,
        'extra_percent' => 0,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    Livewire::test(PlanSignup::class, ['plan' => $plan->uuid])
        ->set('name', 'Ana')
        ->set('email', 'ana@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->call('register')
        ->assertHasErrors(['pricingAreaId']);

    Livewire::test(PlanSignup::class, ['plan' => $plan->uuid])
        ->set('name', 'Ana')
        ->set('email', 'ana@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->set('pricingAreaId', (string) $country->id)
        ->call('register')
        ->assertRedirect('https://lk.wompi.sv/plan');

    $signup = GetOdooPlanSignup::query()->first();

    expect((float) $signup->amount)->toBe(115.0)
        ->and((int) $signup->pricing_area_id)->toBe($country->id);
});
