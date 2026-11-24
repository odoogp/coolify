<?php

use App\Actions\Server\DeleteServer;
use App\Actions\Server\StartServerAgentEnrollment;
use App\Jobs\ValidateAndInstallServerJob;
use App\Livewire\Server\New\ByAgent;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerAgentEnrollment;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloudflare\CloudflareTunnelApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fakeCloudflare(): void
{
    Http::fake(function (HttpRequest $request) {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'POST' && str_ends_with($url, '/accounts/acc123/cfd_tunnel')) {
            return Http::response(['success' => true, 'result' => ['id' => 'tun-1', 'token' => 'eyJhIjoiYWNjMTIzIn0=']]);
        }
        if ($method === 'PUT' && str_contains($url, '/cfd_tunnel/tun-1/configurations')) {
            return Http::response(['success' => true, 'result' => []]);
        }
        if ($method === 'POST' && str_ends_with($url, '/zones/zone123/dns_records')) {
            return Http::response(['success' => true, 'result' => ['id' => 'dns-1']]);
        }
        if ($method === 'DELETE') {
            return Http::response(['success' => true, 'result' => []]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$method.' '.$url]]], 400);
    });
}

function configureCloudflare(): void
{
    $settings = InstanceSettings::query()->findOrFail(0);
    $settings->forceFill([
        'cloudflare_api_token' => 'cf-token',
        'cloudflare_account_id' => 'acc123',
        'cloudflare_zone_id' => 'zone123',
        'cloudflare_tunnel_domain' => 'nodes.example.test',
    ])->save();
}

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    Storage::fake('ssh-keys');
    Queue::fake();

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

it('creates the server, its key and a cloudflare tunnel, and shows one command', function () {
    configureCloudflare();
    fakeCloudflare();

    $component = Livewire::test(ByAgent::class)
        ->set('name', 'Oficina')
        ->call('start')
        ->assertHasNoErrors();

    $enrollment = ServerAgentEnrollment::query()->firstOrFail();
    $server = $enrollment->server;

    expect($server)->toBeInstanceOf(Server::class)
        ->and($server->team_id)->toBe($this->team->id)
        ->and($server->name)->toBe('Oficina')
        ->and($server->ip)->toBe($enrollment->hostname)
        ->and($server->ip)->toEndWith('.nodes.example.test')
        ->and($server->user)->toBe('root')
        ->and((bool) $server->settings->is_cloudflare_tunnel)->toBeTrue()
        ->and($server->privateKey->team_id)->toBe($this->team->id)
        ->and($enrollment->status)->toBe(ServerAgentEnrollment::STATUS_PENDING)
        ->and($enrollment->cloudflare_tunnel_id)->toBe('tun-1')
        ->and($enrollment->cloudflare_dns_record_id)->toBe('dns-1');

    expect($component->get('command'))->toStartWith('curl -fsSL ')
        ->and($component->get('command'))->toContain('/agent/install/')
        ->and($component->get('command'))->toEndWith('| sudo bash');

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
        && data_get($request->data(), 'config.ingress.0.service') === 'ssh://localhost:22'
        && data_get($request->data(), 'config.ingress.0.hostname') === $enrollment->hostname);
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
        && data_get($request->data(), 'type') === 'CNAME'
        && data_get($request->data(), 'content') === 'tun-1.cfargotunnel.com');

    $component->assertSee(__('Waiting for your server'));
});

it('serves an install script with the key, the tunnel token and the ready url', function () {
    configureCloudflare();
    fakeCloudflare();

    $result = StartServerAgentEnrollment::run($this->owner, $this->team, 'Oficina');
    $publicKey = trim($result['enrollment']->server->privateKey->getPublicKey());

    $response = $this->get(route('agent.install', ['token' => $result['token']]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/x-shellscript')
        ->and($response->getContent())->toContain($publicKey)
        ->and($response->getContent())->toContain('eyJhIjoiYWNjMTIzIn0=')
        ->and($response->getContent())->toContain(route('agent.ready', ['token' => $result['token']]))
        ->and($response->getContent())->toContain('docker run -d --name getcloud-agent')
        ->and($response->getContent())->not->toContain('{!!');
});

it('marks the enrollment reported, saves the public ip and starts validation when the server is ready', function () {
    configureCloudflare();
    fakeCloudflare();

    $result = StartServerAgentEnrollment::run($this->owner, $this->team, 'Oficina');

    $this->post(route('agent.ready', ['token' => $result['token']]), ['public_ip' => '8.8.8.8'])
        ->assertOk();

    $enrollment = $result['enrollment']->fresh();
    expect($enrollment->status)->toBe(ServerAgentEnrollment::STATUS_REPORTED)
        ->and($enrollment->server->public_ip)->toBe('8.8.8.8')
        ->and(sslip($enrollment->server->fresh()))->toBe('http://8.8.8.8.sslip.io');

    Queue::assertPushed(ValidateAndInstallServerJob::class, fn ($job) => $job->server->id === $enrollment->server_id);
});

it('ignores a private ip reported by the script', function () {
    configureCloudflare();
    fakeCloudflare();

    $result = StartServerAgentEnrollment::run($this->owner, $this->team, 'Oficina');

    $this->post(route('agent.ready', ['token' => $result['token']]), ['public_ip' => '10.0.0.5'])->assertOk();

    expect($result['enrollment']->server->fresh()->public_ip)->toBeNull();
});

it('rejects expired, connected and unknown tokens', function () {
    configureCloudflare();
    fakeCloudflare();

    $result = StartServerAgentEnrollment::run($this->owner, $this->team, 'Oficina');
    $token = $result['token'];

    $result['enrollment']->update(['expires_at' => now()->subMinute()]);
    $this->get(route('agent.install', ['token' => $token]))->assertNotFound();

    $result['enrollment']->update(['expires_at' => now()->addHour(), 'status' => ServerAgentEnrollment::STATUS_CONNECTED]);
    $this->get(route('agent.install', ['token' => $token]))->assertNotFound();
    $this->post(route('agent.ready', ['token' => $token]))->assertNotFound();

    $this->get('/agent/install/'.str_repeat('a', 48))->assertNotFound();
    Queue::assertNotPushed(ValidateAndInstallServerJob::class);
});

it('is not offered when cloudflare is not configured', function () {
    Http::fake();

    Livewire::test(ByAgent::class)
        ->assertSee(__('This option is not available yet. Use IP address or domain, or ask your advisor.'))
        ->set('name', 'Oficina')
        ->call('start')
        ->assertDispatched('error');

    expect(ServerAgentEnrollment::query()->count())->toBe(0)
        ->and(Server::query()->where('team_id', $this->team->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('respects the platform switch for client servers', function () {
    configureCloudflare();
    fakeCloudflare();
    InstanceSettings::query()->whereKey(0)->update(['clients_can_connect_servers' => false]);

    Livewire::test(ByAgent::class)
        ->set('name', 'Oficina')
        ->call('start')
        ->assertDispatched('error');

    expect(ServerAgentEnrollment::query()->count())->toBe(0);
});

it('does not let a team member connect servers', function () {
    configureCloudflare();
    fakeCloudflare();
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ByAgent::class)
        ->set('name', 'Oficina')
        ->call('start')
        ->assertDispatched('error');

    expect(ServerAgentEnrollment::query()->count())->toBe(0);
});

it('removes the tunnel when the connection cannot be saved', function () {
    configureCloudflare();
    Http::fake(function (HttpRequest $request) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/cfd_tunnel')) {
            return Http::response(['success' => true, 'result' => ['id' => 'tun-1', 'token' => 'abc=']]);
        }
        if ($request->method() === 'PUT') {
            return Http::response(['success' => false, 'errors' => [['message' => 'no permission']]], 403);
        }

        return Http::response(['success' => true, 'result' => []]);
    });

    expect(fn () => StartServerAgentEnrollment::run($this->owner, $this->team, 'Oficina'))
        ->toThrow(RuntimeException::class);

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/cfd_tunnel/tun-1'));
    expect(Server::query()->where('team_id', $this->team->id)->count())->toBe(0);
});

it('deletes the tunnel and dns record when the server is deleted', function () {
    configureCloudflare();
    fakeCloudflare();

    $result = StartServerAgentEnrollment::run($this->owner, $this->team, 'Oficina');

    DeleteServer::run($result['enrollment']->server_id);

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/dns_records/dns-1'));
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/cfd_tunnel/tun-1'));
    expect(ServerAgentEnrollment::query()->first()->status)->toBe(ServerAgentEnrollment::STATUS_EXPIRED);
});

it('only lets the platform owner save the cloudflare settings', function () {
    expect(CloudflareTunnelApi::isConfigured())->toBeFalse();

    Livewire::test(\App\Livewire\Settings\GetOdooServers::class)
        ->set('cloudflareApiToken', 'cf-token')
        ->set('cloudflareAccountId', 'acc123')
        ->set('cloudflareZoneId', 'zone123')
        ->set('cloudflareTunnelDomain', 'nodes.example.test')
        ->call('saveCloudflare');

    expect(CloudflareTunnelApi::isConfigured())->toBeFalse();
});
