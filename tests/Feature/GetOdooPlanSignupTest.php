<?php

use App\Livewire\GetOdoo\PlanSignup;
use App\Livewire\Settings\GetOdooPlans;
use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooPricingArea;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\GetOdooAreaEntitlements;
use App\Services\GetOdoo\GetOdooPlanPricing;
use App\Services\GetOdoo\OpenGetOdooPlanAccount;
use App\Services\GetOdoo\ResolveGetOdooPlansForCountry;
use App\Services\GetOdoo\WompiClient;
use App\Support\DetectRequestCountry;
use App\Support\GetOdooCountries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'constants.getodoo.force_country_iso' => null,
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->root = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
});

function getOdooBuyerCountry(string $iso = 'GT', array $overrides = []): GetOdooPricingArea
{
    config(['constants.getodoo.force_country_iso' => strtoupper($iso)]);

    return GetOdooPricingArea::query()->create(array_merge([
        'code' => strtolower($iso),
        'name' => GetOdooCountries::name(strtoupper($iso)) ?? strtoupper($iso),
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => strtoupper($iso),
        'extra_fixed' => 0,
        'extra_percent' => 0,
        'is_active' => true,
        'sort_order' => 1,
    ], $overrides));
}

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
        'is_rest_of_world' => true,
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
        ->set('includesMigration', true)
        ->set('includesBackups', true)
        ->set('backupFrequency', 'twice_daily')
        ->set('backupRetentionDays', '14')
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSee('Oficina')
        ->assertSee('/start/');

    $plan = GetOdooPlan::query()->first();

    expect($plan)->not->toBeNull()
        ->and((float) $plan->price)->toBe(0.0)
        ->and($plan->payment_gateway)->toBeNull()
        ->and($plan->is_rest_of_world)->toBeTrue()
        ->and((int) $plan->max_projects)->toBe(1)
        ->and((int) $plan->max_environments)->toBe(2)
        ->and((int) $plan->max_members)->toBe(1)
        ->and($plan->max_services)->toBeNull()
        ->and($plan->can_add_servers)->toBeTrue()
        ->and($plan->can_launch_on_instance_server)->toBeFalse()
        ->and($plan->includes_migration)->toBeTrue()
        ->and($plan->backup_frequency)->toBe('twice_daily')
        ->and((int) $plan->backup_retention_days)->toBe(14)
        ->and(collect($plan->includedItems())->pluck('label')->all())->toContain(__('Migration help (GitHub, repository, dump + filestore)'))
        ->and($plan->publicUrl())->toContain('/start/'.$plan->uuid);
});

it('saves a plan without automatic backups when the backup option is off', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPlans::class)
        ->set('name', 'Sin backups')
        ->set('price', '0')
        ->set('includesBackups', false)
        ->call('savePlan')
        ->assertHasNoErrors();

    $plan = GetOdooPlan::query()->where('name', 'Sin backups')->first();

    expect($plan)->not->toBeNull()
        ->and($plan->backup_frequency)->toBe('none')
        ->and(collect($plan->includedItems())->pluck('label')->all())
        ->not->toContain(__('Automatic Odoo backups'));
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
    getOdooBuyerCountry('GT');

    $this->get(route('getodoo.plan.start', ['plan' => $plan->uuid]))
        ->assertOk()
        ->assertSee('Oficina')
        ->assertSee('Para el equipo')
        ->assertSee('Producción')
        ->assertSee('Admin account')
        ->assertSee('$93.00')
        ->assertSee('Projects')
        ->assertSee('Environments')
        ->assertSee(__('Members (users)'))
        ->assertDontSee(__('Services'))
        ->assertDontSee(__('Can add servers'))
        ->assertDontSee('Can launch instances on the server where GPSH is installed')
        ->assertSee('Continue to payment')
        ->assertSee('Guatemala')
        ->assertSee(__('Detected from your location. The price for this country applies.'));
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
    $country = getOdooBuyerCountry('GT');

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
        ->and($customer->country_iso)->toBe('GT')
        ->and($customer->teams()->count())->toBe(1)
        ->and((int) $team->getodoo_plan_id)->toBe($plan->id)
        ->and((int) $team->getodoo_pricing_area_id)->toBe($country->id)
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
    getOdooBuyerCountry('GT');

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

it('applies only country extras on the plan price', function () {
    $plan = getOdooPlan(['price' => 100]);
    $country = GetOdooPricingArea::query()->create([
        'code' => 'sv',
        'name' => 'El Salvador',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => 'SV',
        'extra_fixed' => 2,
        'extra_percent' => 5,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $quote = GetOdooPlanPricing::quote($plan, $country);

    // 100 * 1.05 + 2 = 107
    expect($quote['extra_percent'])->toBe(5.0)
        ->and($quote['extra_fixed'])->toBe(2.0)
        ->and($quote['amount'])->toBe(107.0);
});

it('defines countries inside the plan with optional promo prices', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPlans::class)
        ->set('name', 'SV only')
        ->set('price', '100')
        ->set('planIsRestOfWorld', false)
        ->set('pendingCountry', 'El Salvador')
        ->call('addPlanCountry')
        ->set('planCountryPromos.el-salvador', '49')
        ->set('pendingCountry', 'Guatemala')
        ->call('addPlanCountry')
        ->call('savePlan')
        ->assertHasNoErrors();

    $plan = GetOdooPlan::query()->where('name', 'SV only')->first();
    expect($plan)->not->toBeNull()
        ->and($plan->is_rest_of_world)->toBeFalse()
        ->and($plan->pricingAreas)->toHaveCount(2)
        ->and((float) $plan->pricingAreas->firstWhere('iso_code', 'SV')?->pivot?->promo_price)->toBe(49.0);
});

it('limits a plan to specific countries and uses promo price on signup', function () {
    User::factory()->create();
    getOdooPlanWompi();
    $plan = getOdooPlan(['price' => 100, 'is_rest_of_world' => false]);
    $sv = getOdooBuyerCountry('SV');
    GetOdooPricingArea::query()->create([
        'code' => 'gt',
        'name' => 'Guatemala',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => 'GT',
        'extra_fixed' => 5,
        'extra_percent' => 0,
        'is_active' => true,
        'sort_order' => 2,
    ]);
    $plan->pricingAreas()->sync([$sv->id => ['promo_price' => 49.00]]);

    $choices = GetOdooPricingArea::countryChoicesForPlan($plan->fresh('pricingAreas'));
    expect($choices)->toHaveCount(1)
        ->and($choices[0]['value'])->toBe((string) $sv->id)
        ->and($choices[0]['label'])->toBe('El Salvador');

    expect(GetOdooPricingArea::countryChoicesForPlan($plan->fresh('pricingAreas'), 'SV'))
        ->toHaveCount(1)
        ->and(GetOdooPricingArea::countryChoicesForPlan($plan->fresh('pricingAreas'), 'GT'))
        ->toHaveCount(0);

    $quote = GetOdooPlanPricing::quote($plan->fresh('pricingAreas'), $sv);
    expect($quote['promo_price'])->toBe(49.0)->and($quote['amount'])->toBe(49.0);

    config(['constants.getodoo.force_country_iso' => 'GT']);
    Livewire::test(PlanSignup::class, ['plan' => $plan->uuid])
        ->assertSee('$100.00')
        ->assertSee(__('This is the standard monthly plan price. This step pays the first charge with Wompi.'))
        ->set('name', 'Ana')
        ->set('email', 'ana-gt@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->call('register')
        ->assertRedirect('https://lk.wompi.sv/plan');

    expect((float) GetOdooPlanSignup::query()->where('email', 'ana-gt@example.com')->value('amount'))->toBe(100.0)
        ->and(GetOdooPlanSignup::query()->where('email', 'ana-gt@example.com')->value('pricing_area_id'))->toBeNull();

    config(['constants.getodoo.force_country_iso' => 'SV']);
    Livewire::test(PlanSignup::class, ['plan' => $plan->uuid])
        ->set('name', 'Ana')
        ->set('email', 'ana@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->call('register')
        ->assertRedirect('https://lk.wompi.sv/plan');

    expect((float) GetOdooPlanSignup::query()->where('email', 'ana@example.com')->value('amount'))->toBe(49.0);
});

it('prefers a country plan over a region plan and falls back to rest of the world', function () {
    $latam = GetOdooPricingArea::query()->create([
        'code' => 'latam',
        'name' => 'LatAm',
        'kind' => GetOdooPricingArea::KIND_REGION,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $gt = GetOdooPricingArea::query()->create([
        'code' => 'gt',
        'name' => 'Guatemala',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => 'GT',
        'parent_id' => $latam->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $mx = GetOdooPricingArea::query()->create([
        'code' => 'mx',
        'name' => 'Mexico',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => 'MX',
        'parent_id' => $latam->id,
        'is_active' => true,
        'sort_order' => 2,
    ]);
    $jp = GetOdooPricingArea::query()->create([
        'code' => 'jp',
        'name' => 'Japan',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => 'JP',
        'parent_id' => null,
        'is_active' => true,
        'sort_order' => 3,
    ]);

    $countryPlan = getOdooPlan(['name' => 'GT Pro', 'price' => 80, 'is_rest_of_world' => false]);
    $countryPlan->pricingAreas()->sync([$gt->id => ['promo_price' => 70]]);

    $regionPlan = getOdooPlan(['name' => 'LatAm', 'price' => 60, 'is_rest_of_world' => false]);
    $regionPlan->pricingAreas()->sync([$latam->id => ['promo_price' => 55]]);

    $restPlan = getOdooPlan(['name' => 'Global', 'price' => 90, 'is_rest_of_world' => true]);

    expect(ResolveGetOdooPlansForCountry::forCountry($gt)->pluck('id')->all())
        ->toBe([$countryPlan->id])
        ->and(ResolveGetOdooPlansForCountry::forCountry($mx)->pluck('id')->all())
        ->toBe([$regionPlan->id])
        ->and(ResolveGetOdooPlansForCountry::forCountry($jp)->pluck('id')->all())
        ->toBe([$restPlan->id]);

    $regionQuote = GetOdooPlanPricing::quote($regionPlan->fresh('pricingAreas'), $mx);
    expect($regionQuote['promo_price'])->toBe(55.0)->and($regionQuote['amount'])->toBe(55.0);

    expect(GetOdooPricingArea::countryChoicesForPlan($countryPlan->fresh('pricingAreas')))
        ->toHaveCount(1)
        ->and(collect(GetOdooPricingArea::countryChoicesForPlan($regionPlan->fresh('pricingAreas')))->pluck('value')->all())
        ->toBe([(string) $mx->id])
        ->and(collect(GetOdooPricingArea::countryChoicesForPlan($restPlan->fresh('pricingAreas')))->pluck('value')->all())
        ->toBe([(string) $jp->id]);

    // IP in Mexico → country belongs to LatAm → regional plan price applies automatically.
    config(['constants.getodoo.force_country_iso' => 'MX']);
    Livewire::test(PlanSignup::class, ['plan' => $regionPlan->uuid])
        ->assertSee('$55.00')
        ->assertSee(__(':country · :region', ['country' => 'Mexico', 'region' => 'LatAm']))
        ->assertDontSee(__('This is the standard monthly plan price. This step pays the first charge with Wompi.'));
});

it('locks signup to the buyer ip country and charges the quoted amount', function () {
    User::factory()->create();
    getOdooPlanWompi();
    $plan = getOdooPlan(['price' => 100]);
    $country = getOdooBuyerCountry('GT', [
        'extra_fixed' => 5,
        'extra_percent' => 10,
    ]);

    Livewire::withHeaders(['CF-IPCountry' => 'GT'])
        ->test(PlanSignup::class, ['plan' => $plan->uuid])
        ->assertSet('pricingAreaId', (string) $country->id)
        ->assertSee('Guatemala')
        ->assertDontSee(__('Search countries'))
        ->set('name', 'Ana')
        ->set('email', 'ana@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->call('register')
        ->assertRedirect('https://lk.wompi.sv/plan');

    $signup = GetOdooPlanSignup::query()->first();

    // 100 * 1.10 + 5 = 115
    expect((float) $signup->amount)->toBe(115.0)
        ->and((int) $signup->pricing_area_id)->toBe($country->id);
});

it('uses the standard plan price when the buyer location cannot be determined', function () {
    User::factory()->create();
    getOdooPlanWompi();
    $plan = getOdooPlan(['price' => 100]);
    GetOdooPricingArea::query()->create([
        'code' => 'gt',
        'name' => 'Guatemala',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => 'GT',
        'extra_fixed' => 5,
        'extra_percent' => 10,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    Livewire::test(PlanSignup::class, ['plan' => $plan->uuid])
        ->assertSee('$100.00')
        ->assertSee(__('This is the standard monthly plan price. This step pays the first charge with Wompi.'))
        ->assertDontSee(__('Search countries'))
        ->assertDontSee(__('Detected from your location. The price for this country applies.'))
        ->set('name', 'Ana')
        ->set('email', 'ana@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->call('register')
        ->assertRedirect('https://lk.wompi.sv/plan');

    $signup = GetOdooPlanSignup::query()->first();
    expect((float) $signup->amount)->toBe(100.0)
        ->and($signup->pricing_area_id)->toBeNull();
});

it('reads the buyer country from cloudflare headers and public ip lookup', function () {
    $request = Request::create('/');
    $request->headers->set('CF-IPCountry', 'SV');

    expect(DetectRequestCountry::iso($request))->toBe('SV')
        ->and(DetectRequestCountry::normalize('XX'))->toBeNull()
        ->and(DetectRequestCountry::normalize('gt'))->toBe('GT');

    Http::fake([
        'ip-api.com/*' => Http::response(['status' => 'success', 'countryCode' => 'HN']),
    ]);

    $public = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '8.8.8.8']);
    expect(DetectRequestCountry::iso($public))->toBe('HN')
        ->and(DetectRequestCountry::clientIp($public))->toBe('8.8.8.8')
        ->and(DetectRequestCountry::clientIp(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'])))->toBeNull();
});

it('applies country service and project limits to the new team', function () {
    User::factory()->create();
    $plan = getOdooPlan([
        'price' => 0,
        'payment_gateway' => null,
        'max_projects' => null,
    ]);
    $country = GetOdooPricingArea::query()->create([
        'code' => 'hn',
        'name' => 'Honduras',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => 'HN',
        'allow_multiple_projects' => false,
        'allow_all_services' => false,
        'allowed_services' => ['odoo', 'redis'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $user = app(OpenGetOdooPlanAccount::class)->open('Ana', 'ana@example.com', 'password1', $plan, $country);
    $team = $user->teams()->first();
    $entitlements = GetOdooAreaEntitlements::forTeam($team);

    expect((int) $team->getodoo_pricing_area_id)->toBe($country->id)
        ->and($user->country_iso)->toBe('HN')
        ->and((int) $team->pivot->max_projects)->toBe(1)
        ->and($entitlements['allow_multiple_projects'])->toBeFalse()
        ->and($entitlements['allow_all_services'])->toBeFalse()
        ->and($entitlements['allowed_services'])->toBe(['odoo', 'redis'])
        ->and(GetOdooAreaEntitlements::allowsService($team, 'odoo'))->toBeTrue()
        ->and(GetOdooAreaEntitlements::allowsService($team, 'n8n'))->toBeFalse();
});
