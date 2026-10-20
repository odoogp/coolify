<?php

use App\Livewire\GetOdoo\PlanSignup;
use App\Livewire\Settings\GetOdooPlans;
use App\Livewire\Settings\GetOdooServers;
use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooServerOffer;
use App\Models\GetOdooServerOrder;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\N1coClient;
use App\Support\GetOdooCountries;
use App\Models\GetOdooPricingArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'constants.getodoo.force_country_iso' => 'GT',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->root = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
});

it('lets the owner choose a monthly subscription or a one-time plan', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPlans::class)
        ->set('name', 'Mensual')
        ->set('price', '25')
        ->set('chargeMode', 'subscription')
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSee(__('Monthly subscription'));

    expect(GetOdooPlan::query()->where('name', 'Mensual')->value('charge_mode'))->toBe('subscription');

    Livewire::test(GetOdooPlans::class)
        ->call('editPlan', GetOdooPlan::query()->where('name', 'Mensual')->value('id'))
        ->set('chargeMode', 'once')
        ->call('savePlan')
        ->assertHasNoErrors();

    expect(GetOdooPlan::query()->where('name', 'Mensual')->value('charge_mode'))->toBe('once');
});

it('sends a subscription plan to an n1co monthly plan and a one-time plan to a single charge', function () {
    User::factory()->create();
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
        'n1co_location_id' => 578,
        'is_registration_enabled' => false,
    ]);
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/v3/Plans') && $request->method() === 'POST') {
            return Http::response([
                'planId' => 1810,
                'paymentLink' => [
                    'code' => 'SUB1',
                    'linkUrl' => 'https://pay-sandbox.n1co.shop/pl/SUB1',
                ],
            ]);
        }

        if (str_contains($request->url(), 'api-pay-sandbox.n1co.shop/api/paymentlink/checkout')) {
            return Http::response([
                'orderCode' => 'ONCE1',
                'paymentLinkUrl' => 'https://pay.n1co.shop/ONCE1',
            ]);
        }

        return Http::response(['error' => 'unexpected '.$request->url()], 500);
    });

    GetOdooPricingArea::query()->create([
        'code' => 'gt',
        'name' => GetOdooCountries::name('GT') ?? 'GT',
        'kind' => GetOdooPricingArea::KIND_COUNTRY,
        'iso_code' => 'GT',
        'extra_fixed' => 0,
        'extra_percent' => 0,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $subscription = GetOdooPlan::query()->create([
        'name' => 'Mensual',
        'price' => 25,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'subscription',
    ]);
    $once = GetOdooPlan::query()->create([
        'name' => 'Unico',
        'price' => 25,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'once',
    ]);

    Livewire::test(PlanSignup::class, ['plan' => $subscription->uuid])
        ->assertSee(__('n1co renews this plan every month until it is cancelled.'))
        ->set('name', 'Ana')
        ->set('organization', 'Acme')
        ->set('email', 'ana-sub@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->set('acceptedTerms', true)
        ->set('gateway', 'n1co')
        ->call('register')
        ->assertRedirect('https://pay-sandbox.n1co.shop/pl/SUB1');

    $signup = GetOdooPlanSignup::query()->where('email', 'ana-sub@example.com')->first();

    expect($signup->charge_mode)->toBe('subscription')
        ->and($signup->n1co_plan_id)->toBe('1810')
        ->and($signup->wompi_link_id)->toBe('SUB1');

    Http::assertSent(function ($request) use ($signup) {
        $body = $request->data();

        return $request->method() === 'POST'
            && str_contains($request->url(), 'https://api-sandbox.n1co.shop/api/v3/Plans')
            && ($body['billingCycleType'] ?? null) === 'Month'
            && (int) ($body['billingCyclesNumber'] ?? 0) === 1
            && (int) ($body['locationId'] ?? 0) === 578
            && str_contains((string) ($body['name'] ?? ''), $signup->uuid);
    });

    Livewire::test(PlanSignup::class, ['plan' => $once->uuid])
        ->set('name', 'Ana')
        ->set('organization', 'Acme')
        ->set('email', 'ana-once@example.com')
        ->set('password', 'password1')
        ->set('password_confirmation', 'password1')
        ->set('acceptedTerms', true)
        ->set('gateway', 'n1co')
        ->call('register')
        ->assertRedirect('https://pay.n1co.shop/ONCE1');

    expect(GetOdooPlanSignup::query()->where('email', 'ana-once@example.com')->value('charge_mode'))->toBe('once');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'paymentlink/checkout'));
});

it('opens the account on the first subscription notice and does not open another on the renewal', function () {
    User::factory()->create();
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
    ]);
    $plan = GetOdooPlan::query()->create([
        'name' => 'Mensual',
        'price' => 25,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'subscription',
        'max_projects' => 1,
    ]);
    $signup = $plan->signups()->create([
        'name' => 'Ana',
        'organization' => 'Acme',
        'email' => 'ana-renew@example.com',
        'password' => 'password1',
        'amount' => 25,
        'payment_gateway' => 'n1co',
        'charge_mode' => 'subscription',
        'status' => 'awaiting_payment',
        'n1co_plan_id' => '1810',
    ]);

    $notice = function (string $type, string $eventId) use ($signup) {
        $body = json_encode([
            'type' => $type,
            'subscriptionId' => '242',
            'metadata' => [
                'SubscriptionId' => '242',
                'SubscriptionName' => 'Mensual '.$signup->uuid,
                'SubscriptionPrice' => '25.00',
                'OrderReference' => $signup->uuid,
            ],
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/webhooks/payments/n1co', [], [], [], [
            'HTTP_X_H4B_HMAC_SHA256' => app(N1coClient::class)->webhookHash($body),
            'HTTP_X_H4B_EVENT_ID' => $eventId,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    };

    $notice('SubscriptionConfirmation', 'sub-1')->assertOk();
    $notice('SubscriptionPayment', 'sub-2')->assertOk();

    expect(User::query()->where('email', 'ana-renew@example.com')->count())->toBe(1)
        ->and($signup->fresh()->status)->toBe('paid')
        ->and($signup->fresh()->n1co_subscription_id)->toBe('242')
        ->and($signup->fresh()->n1co_subscription_status)->toBe('active');
});

it('renews a subscribed server without creating a second one', function () {
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
        'n1co_server_charge_mode' => 'subscription',
        'n1co_location_id' => 578,
    ]);
    $team = Team::factory()->create();
    $offer = GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 22,
        'name' => 'cx22',
        'description' => 'CX22',
        'cores' => 2,
        'memory' => 4,
        'disk' => 40,
        'architecture' => 'x86',
        'monthly_price' => 10,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 10]],
        'available_for_admins' => true,
        'in_stock' => true,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'name' => 'cliente-sub',
        'getodoo_offer_id' => $offer->id,
        'getodoo_paid_until' => now()->startOfDay()->toDateString(),
    ]);
    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => User::factory()->create()->id,
        'offer_id' => $offer->id,
        'server_id' => $server->id,
        'server_name' => 'cliente-sub',
        'location' => 'fsn1',
        'amount' => 10,
        'status' => 'provisioned',
        'purpose' => 'launch',
        'payment_gateway' => 'n1co',
        'charge_mode' => 'subscription',
        'n1co_plan_id' => '1810',
        'n1co_subscription_id' => '242',
    ]);
    $paidUntil = $server->getodoo_paid_until->toDateString();
    $body = json_encode([
        'type' => 'SubscriptionPayment',
        'metadata' => [
            'SubscriptionId' => '242',
            'SubscriptionPrice' => '10.00',
            'OrderReference' => $order->uuid,
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', '/webhooks/payments/n1co', [], [], [], [
        'HTTP_X_H4B_HMAC_SHA256' => app(N1coClient::class)->webhookHash($body),
        'HTTP_X_H4B_EVENT_ID' => 'server-renew-1',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(Server::query()->where('name', 'cliente-sub')->count())->toBe(1)
        ->and($server->fresh()->getodoo_paid_until->toDateString())->not->toBe($paidUntil)
        ->and($order->fresh()->n1co_subscription_status)->toBe('active');
});

it('requires the n1co branch id before servers are sold as a subscription', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooServers::class)
        ->set('n1coSecret', 'secret-n1co')
        ->set('n1coWebhookSecret', 'whsec-n1co')
        ->set('n1coCharges', true)
        ->set('n1coServerChargeMode', 'subscription')
        ->set('n1coLocationId', '')
        ->call('saveN1co')
        ->assertHasErrors(['n1coLocationId']);
});
