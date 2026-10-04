<?php

use App\Services\Cloud\AdditionalCloudCatalog;
use App\Services\Cloud\AdditionalCloudCredentials;
use App\Services\Cloud\CloudServerRequest;
use App\Services\Cloud\ContaboCloudClient;
use App\Services\Cloud\ExoscaleCloudClient;
use App\Services\Cloud\LinodeCloudClient;
use App\Services\Cloud\ScalewayCloudClient;
use App\Services\Cloud\UpCloudClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('keeps the original providers first and adds the new ones in order', function () {
    expect(AdditionalCloudCatalog::slugs())->toBe([
        'linode',
        'upcloud',
        'scaleway',
        'contabo',
        'exoscale',
    ])->and(AdditionalCloudCatalog::providerRule())->toBe(
        'required|string|in:hetzner,digitalocean,vultr,linode,upcloud,scaleway,contabo,exoscale'
    );

    $create = file_get_contents(resource_path('views/livewire/server/create.blade.php'));

    expect($create)->toContain("['type' => 'hetzner']")
        ->and($create)->toContain("['type' => 'vultr']")
        ->and($create)->toContain("['type' => 'digital-ocean']")
        ->and($create)->toContain('by-hetzner')
        ->and($create)->toContain('by-vultr')
        ->and($create)->toContain('by-digital-ocean')
        ->and($create)->toContain('by-additional-cloud')
        ->and($create)->toContain("asset('svgs/'.\$cloud['slug'].'.svg')")
        ->and(strpos($create, 'by-digital-ocean'))->toBeLessThan(strpos($create, 'by-additional-cloud'));

    foreach (['linode', 'upcloud', 'scaleway', 'contabo', 'exoscale'] as $slug) {
        $logo = file_get_contents(public_path('svgs/'.$slug.'.svg'));

        expect($logo)->toContain('<svg')
            ->and($logo)->toContain('fill="#');
    }
});

it('stores a single secret for linode and json credentials for the others', function () {
    expect(AdditionalCloudCredentials::pack('linode', 'pat'))->toBe('pat')
        ->and(AdditionalCloudCredentials::pack('hetzner', 'hetzner-token'))->toBe('hetzner-token')
        ->and(AdditionalCloudCredentials::unpack('pat'))->toMatchArray([
            'token' => 'pat',
            'account' => null,
        ]);

    $packed = AdditionalCloudCredentials::pack('upcloud', 'secret', 'account-user');

    expect(AdditionalCloudCredentials::unpack($packed))->toMatchArray([
        'token' => 'secret',
        'account' => 'account-user',
    ]);
});

it('lists ubuntu before debian and creates a linode with the ssh key', function () {
    Http::fake([
        'https://api.linode.com/v4/images*' => Http::response([
            'data' => [
                ['id' => 'linode/debian12', 'label' => 'Debian 12', 'status' => 'available'],
                ['id' => 'linode/ubuntu24.04', 'label' => 'Ubuntu 24.04 LTS', 'status' => 'available'],
                ['id' => 'private/1', 'label' => 'Ubuntu custom', 'status' => 'available'],
            ],
            'page' => 1,
            'pages' => 1,
        ]),
        'https://api.linode.com/v4/linode/instances' => Http::response([
            'id' => 42,
            'status' => 'provisioning',
            'ipv4' => ['203.0.113.10'],
        ]),
    ]);

    $client = new LinodeCloudClient('linode-token');
    $images = $client->images('us-east');

    expect($images[0]['id'])->toBe('linode/ubuntu24.04')
        ->and(collect($images)->pluck('id'))->not->toContain('private/1');

    $created = $client->create(new CloudServerRequest(
        name: 'app-1',
        region: 'us-east',
        plan: 'g6-nanode-1',
        image: 'linode/ubuntu24.04',
        publicKey: 'ssh-ed25519 AAAA key',
    ));

    expect($created->id)->toBe('42')
        ->and($created->ip)->toBe('203.0.113.10')
        ->and($created->user)->toBe('root');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.linode.com/v4/linode/instances'
            && $request->hasHeader('Authorization', 'Bearer linode-token')
            && $request['region'] === 'us-east'
            && $request['type'] === 'g6-nanode-1'
            && $request['image'] === 'linode/ubuntu24.04'
            && $request['authorized_keys'] === ['ssh-ed25519 AAAA key'];
    });
});

it('creates an upcloud server with the account ssh key', function () {
    Http::fake([
        'https://api.upcloud.com/1.3/plan' => Http::response([
            'plans' => ['plan' => [[
                'name' => '1xCPU-1GB',
                'storage_size' => 25,
            ]]],
        ]),
        'https://api.upcloud.com/1.3/server' => Http::response([
            'server' => [
                'uuid' => 'server-uuid',
                'state' => 'started',
                'ip_addresses' => [
                    'ip_address' => [[
                        'access' => 'public',
                        'family' => 'IPv4',
                        'address' => '203.0.113.20',
                    ]],
                ],
            ],
        ]),
    ]);

    $created = (new UpCloudClient('up-user', 'up-pass'))->create(new CloudServerRequest(
        name: 'app-1',
        region: 'fi-hel1',
        plan: '1xCPU-1GB',
        image: 'template-uuid',
        publicKey: 'ssh-ed25519 AAAA key',
    ));

    expect($created->id)->toBe('server-uuid')
        ->and($created->ip)->toBe('203.0.113.20')
        ->and($created->user)->toBe('root');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.upcloud.com/1.3/server'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('up-user:up-pass'))
            && $request['server']['zone'] === 'fi-hel1'
            && $request['server']['plan'] === '1xCPU-1GB'
            && $request['server']['login_user']['ssh_keys']['ssh_key'] === ['ssh-ed25519 AAAA key'];
    });
});

it('creates a scaleway instance and powers it on', function () {
    Http::fake([
        'https://api.scaleway.com/iam/v1alpha1/ssh-keys' => Http::response(['id' => 'key-1']),
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers' => Http::response([
            'server' => [
                'id' => 'srv-1',
                'state' => 'stopped',
                'public_ip' => ['address' => '203.0.113.30'],
            ],
        ]),
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers/srv-1/action' => Http::response(['task' => 'ok']),
    ]);

    $created = (new ScalewayCloudClient('scw-secret', 'project-1'))->create(new CloudServerRequest(
        name: 'app-1',
        region: 'fr-par-1',
        plan: 'DEV1-S',
        image: 'image-1',
        publicKey: 'ssh-ed25519 AAAA key',
        imageLabel: 'Ubuntu 24.04',
    ));

    expect($created->id)->toBe('srv-1')
        ->and($created->user)->toBe('ubuntu');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers'
            && $request->hasHeader('X-Auth-Token', 'scw-secret')
            && $request['project'] === 'project-1'
            && $request['commercial_type'] === 'DEV1-S';
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers/srv-1/action'
            && $request['action'] === 'poweron';
    });
});

it('creates a contabo instance after exchanging the client credentials', function () {
    Http::fake([
        'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token' => Http::response([
            'access_token' => 'contabo-access',
        ]),
        'https://api.contabo.com/v1/secrets' => Http::response([
            'data' => [['secretId' => 77]],
        ]),
        'https://api.contabo.com/v1/compute/instances' => Http::response([
            'data' => [[
                'instanceId' => 900,
                'status' => 'provisioning',
                'ipConfig' => ['v4' => ['ip' => '203.0.113.40']],
            ]],
        ]),
    ]);

    $created = (new ContaboCloudClient('client-id', 'client-secret', 'api-user', 'api-pass'))->create(new CloudServerRequest(
        name: 'app-1',
        region: 'EU',
        plan: 'V92',
        image: 'image-ubuntu',
        publicKey: 'ssh-ed25519 AAAA key',
    ));

    expect($created->id)->toBe('900')
        ->and($created->ip)->toBe('203.0.113.40')
        ->and($created->user)->toBe('root');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.contabo.com/v1/compute/instances'
            && $request->hasHeader('Authorization', 'Bearer contabo-access')
            && $request['productId'] === 'V92'
            && $request['region'] === 'EU'
            && $request['sshKeys'] === [77]
            && $request['defaultUser'] === 'root';
    });
});

it('signs exoscale requests and creates an instance in the selected zone', function () {
    Http::fake([
        'https://api-ch-gva-2.exoscale.com/v2/zone' => Http::response(['zones' => []]),
        'https://api-ch-gva-2.exoscale.com/v2/ssh-key' => Http::response(['name' => 'gpsh-key']),
        'https://api-ch-gva-2.exoscale.com/v2/instance' => Http::response([
            'id' => 'exo-1',
            'state' => 'starting',
            'public-ip' => '203.0.113.50',
        ]),
    ]);

    $client = new ExoscaleCloudClient('exo-key', 'exo-secret');
    $client->request('GET', 'ch-gva-2', '/v2/zone', null, 1599140767);

    Http::assertSent(function ($request) {
        $header = $request->header('Authorization')[0] ?? '';
        $signature = base64_encode(hash_hmac('sha256', "GET /v2/zone\n\n\n\n1599140767", 'exo-secret', true));

        return $request->url() === 'https://api-ch-gva-2.exoscale.com/v2/zone'
            && $header === 'EXO2-HMAC-SHA256 credential=exo-key,expires=1599140767,signature='.$signature;
    });

    $created = $client->create(new CloudServerRequest(
        name: 'app-1',
        region: 'ch-gva-2',
        plan: 'type-1',
        image: 'template-1',
        publicKey: 'ssh-ed25519 AAAA key',
        imageLabel: 'Linux Ubuntu 24.04',
    ));

    expect($created->id)->toBe('exo-1')
        ->and($created->ip)->toBe('203.0.113.50')
        ->and($created->user)->toBe('ubuntu');
});
