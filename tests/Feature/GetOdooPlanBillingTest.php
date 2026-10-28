<?php

use App\Livewire\Server\Billing;
use App\Livewire\Settings\GetOdooPlans;
use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanOrder;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Support\GetOdooPlanBilling;
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

it('saves hybrid charge mode on a plan', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPlans::class)
        ->set('name', 'Hibrido')
        ->set('price', '29')
        ->set('chargeMode', 'hybrid')
        ->set('planIsRestOfWorld', true)
        ->call('savePlan')
        ->assertHasNoErrors();

    expect(GetOdooPlan::query()->where('name', 'Hibrido')->value('charge_mode'))->toBe('hybrid');
});

it('forces the first hybrid plan signup charge to once', function () {
    $plan = GetOdooPlan::query()->create([
        'name' => 'Hibrido',
        'price' => 29,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'hybrid',
        'max_projects' => 1,
        'max_environments' => 2,
        'max_members' => 1,
    ]);

    expect(GetOdooPlanBilling::chargeModeForSignup($plan))->toBe('once')
        ->and(GetOdooPlanBilling::initialPreference($plan))->toBe('once');
});

it('uses subscription for plan renewals after the client opts in', function () {
    $this->travelTo('2026-11-20 12:00:00');

    $plan = GetOdooPlan::query()->create([
        'name' => 'Hibrido',
        'price' => 29,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'hybrid',
        'max_projects' => 1,
        'max_environments' => 2,
        'max_members' => 1,
    ]);
    $team = Team::factory()->create([
        'getodoo_plan_id' => $plan->id,
        'getodoo_plan_paid_until' => '2026-11-20',
        'getodoo_plan_billing_preference' => 'recurring',
        'getodoo_plan_recurring_starts_at' => '2026-11-20',
    ]);

    expect(GetOdooPlanBilling::chargeModeForRenewal($team, $plan))->toBe('subscription');
});

it('opens an n1co subscription checkout when make recurring is used on an overdue plan invoice', function () {
    $this->travelTo('2026-11-25 12:00:00');
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
        'n1co_location_id' => 578,
        'n1co_client_id' => 'client-n1co',
        'n1co_client_secret' => 'client-secret-n1co',
    ]);
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/v3/Token') && $request->method() === 'POST') {
            return Http::response(['accessToken' => 'access-n1co', 'expiresIn' => 3600]);
        }

        if (str_contains($request->url(), '/api/v3/Plans') && $request->method() === 'POST') {
            return Http::response([
                'planId' => 2001,
                'paymentLink' => [
                    'code' => 'PLAN-SUB',
                    'linkUrl' => 'https://pay-sandbox.n1co.shop/pl/PLAN-SUB',
                ],
            ]);
        }

        return Http::response(['error' => 'unexpected '.$request->url()], 500);
    });

    $plan = GetOdooPlan::query()->create([
        'name' => 'Hibrido',
        'price' => 29,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'hybrid',
        'max_projects' => 1,
        'max_environments' => 2,
        'max_members' => 1,
    ]);
    $team = Team::factory()->create([
        'getodoo_plan_id' => $plan->id,
        'getodoo_plan_paid_until' => '2026-11-20',
        'getodoo_plan_billing_preference' => 'once',
    ]);
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin']);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    $component = Livewire::test(Billing::class)->call('enablePlanRecurring');
    $order = GetOdooPlanOrder::query()->where('purpose', 'renewal')->first();

    expect($order)->not->toBeNull();
    $component->assertRedirect(route('getodoo.plan.order.return', ['order' => $order->uuid]));

    expect($team->fresh()->prefersPlanRecurringBilling())->toBeTrue()
        ->and($order->charge_mode)->toBe('subscription')
        ->and($order->n1co_plan_id)->toBe('2001');
});

it('keeps plan make recurring charge-free while the period is still paid', function () {
    $plan = GetOdooPlan::query()->create([
        'name' => 'Hibrido',
        'price' => 29,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'hybrid',
        'max_projects' => 1,
        'max_environments' => 2,
        'max_members' => 1,
    ]);
    $team = Team::factory()->create([
        'getodoo_plan_id' => $plan->id,
        'getodoo_plan_paid_until' => '2026-12-20',
        'getodoo_plan_billing_preference' => 'once',
    ]);
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin']);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->assertSee('Hibrido')
        ->assertSee(__('Make recurring'))
        ->call('enablePlanRecurring')
        ->assertDispatched('success')
        ->assertSee(__('Cancel recurring'))
        ->assertDontSee(__('Make recurring'))
        ->assertSee(__('Recurring from :date', ['date' => now()->parse('2026-12-20')->isoFormat('D MMM YYYY')]));

    expect($team->fresh()->getodoo_plan_recurring_starts_at?->toDateString())->toBe('2026-12-20')
        ->and(GetOdooPlanOrder::query()->count())->toBe(0);
});

it('shows the purchased plan name on client billing invoice lines', function () {
    $plan = GetOdooPlan::query()->create([
        'name' => 'Oficina Pro',
        'price' => 49,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'hybrid',
        'max_projects' => 1,
        'max_environments' => 2,
        'max_members' => 1,
    ]);
    $team = Team::factory()->create([
        'getodoo_plan_id' => $plan->id,
        'getodoo_plan_paid_until' => '2026-12-20',
        'getodoo_plan_billing_preference' => 'once',
    ]);
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin']);
    GetOdooPlanOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'plan_id' => $plan->id,
        'amount' => 49,
        'status' => 'paid',
        'purpose' => 'signup',
        'payment_gateway' => 'n1co',
        'charge_mode' => 'once',
    ]);
    GetOdooPlanOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'plan_id' => $plan->id,
        'amount' => 49,
        'status' => 'paid',
        'purpose' => 'renewal',
        'payment_gateway' => 'n1co',
        'charge_mode' => 'subscription',
    ]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->assertSee('Oficina Pro')
        ->assertSee('Oficina Pro · '.__('Plan signup'))
        ->assertSee('Oficina Pro · '.__('Plan renewal'))
        ->assertSee(__('One-time'))
        ->assertSee(__('Recurring'));
});
