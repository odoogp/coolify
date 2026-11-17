<?php

use App\Services\Cloud\AdditionalCloudCatalog;
use App\Services\Cloud\AdditionalCloudCredentials;
use App\Services\Cloud\AwsCloudClient;
use App\Services\Cloud\AzureCloudClient;
use App\Services\Cloud\CloudServerRequest;
use App\Services\Cloud\ContaboCloudClient;
use App\Services\Cloud\ExoscaleCloudClient;
use App\Services\Cloud\GoogleCloudClient;
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
        'aws',
        'google',
        'azure',
    ])->and(AdditionalCloudCatalog::providerRule())->toBe(
        'required|string|in:hetzner,digitalocean,vultr,linode,upcloud,scaleway,contabo,exoscale,aws,google,azure'
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

    foreach (['linode', 'upcloud', 'scaleway', 'contabo', 'exoscale', 'aws', 'google', 'azure'] as $slug) {
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

    $serviceAccount = '{"type":"service_account","project_id":"gpsh","private_key":"secret"}';

    expect(AdditionalCloudCredentials::pack('google', $serviceAccount))->toBe($serviceAccount)
        ->and(AdditionalCloudCredentials::unpack($serviceAccount)['token'])->toBe($serviceAccount)
        ->and(AdditionalCloudCredentials::unpack(AdditionalCloudCredentials::pack('aws', 'secret-key', 'AKIAEXAMPLE'))['account'])->toBe('AKIAEXAMPLE');
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

it('signs aws requests and launches an instance with the ssh key', function () {
    Http::fake(function ($request) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $xml = match ($query['Action'] ?? '') {
            'DescribeVpcs' => '<DescribeVpcsResponse><vpcSet><item><vpcId>vpc-1</vpcId></item></vpcSet></DescribeVpcsResponse>',
            'DescribeSecurityGroups' => '<DescribeSecurityGroupsResponse><securityGroupInfo></securityGroupInfo></DescribeSecurityGroupsResponse>',
            'CreateSecurityGroup' => '<CreateSecurityGroupResponse><groupId>sg-1</groupId></CreateSecurityGroupResponse>',
            'AuthorizeSecurityGroupIngress' => '<AuthorizeSecurityGroupIngressResponse><return>true</return></AuthorizeSecurityGroupIngressResponse>',
            'ImportKeyPair' => '<ImportKeyPairResponse><keyName>gpsh-key</keyName></ImportKeyPairResponse>',
            'RunInstances' => '<RunInstancesResponse><instancesSet><item><instanceId>i-abc</instanceId><ipAddress>203.0.113.60</ipAddress></item></instancesSet></RunInstancesResponse>',
            default => null,
        };

        return $xml === null
            ? Http::response('unexpected', 500)
            : Http::response($xml, 200, ['Content-Type' => 'text/xml']);
    });

    $client = new AwsCloudClient('AKIAEXAMPLE', 'secret-key');
    $client->call('us-east-1', ['Action' => 'DescribeRegions'], '20261003T120000Z');

    Http::assertSent(function ($request) {
        $header = $request->header('Authorization')[0] ?? '';
        $query = 'Action=DescribeRegions&Version=2016-11-15';
        $canonical = implode("\n", [
            'GET',
            '/',
            $query,
            "host:ec2.us-east-1.amazonaws.com\nx-amz-date:20261003T120000Z\n",
            'host;x-amz-date',
            hash('sha256', ''),
        ]);
        $scope = '20261003/us-east-1/ec2/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            '20261003T120000Z',
            $scope,
            hash('sha256', $canonical),
        ]);
        $dateKey = hash_hmac('sha256', '20261003', 'AWS4secret-key', true);
        $regionKey = hash_hmac('sha256', 'us-east-1', $dateKey, true);
        $serviceKey = hash_hmac('sha256', 'ec2', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return str_contains($request->url(), 'Action=DescribeRegions')
            && $header === 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/'.$scope.', SignedHeaders=host;x-amz-date, Signature='.$signature;
    });

    $created = $client->create(new CloudServerRequest(
        name: 'app-1',
        region: 'us-east-1',
        plan: 't3.micro',
        image: 'ami-ubuntu',
        publicKey: 'ssh-ed25519 AAAA key',
        imageLabel: 'Ubuntu 24.04 LTS',
    ));

    expect($created->id)->toBe('i-abc')
        ->and($created->ip)->toBe('203.0.113.60')
        ->and($created->user)->toBe('ubuntu');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'Action=ImportKeyPair')
            && str_contains(urldecode($request->url()), 'ssh-ed25519 AAAA key');
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'Action=RunInstances')
            && str_contains($request->url(), 'ImageId=ami-ubuntu')
            && str_contains($request->url(), 'InstanceType=t3.micro')
            && str_contains($request->url(), 'SecurityGroupId.1=sg-1');
    });
});

it('creates a google cloud instance with the service account key', function () {
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $pem);
    $credentials = json_encode([
        'type' => 'service_account',
        'project_id' => 'gpsh-project',
        'client_email' => 'gpsh@gpsh-project.iam.gserviceaccount.com',
        'private_key' => $pem,
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token']),
        'https://compute.googleapis.com/compute/v1/projects/gpsh-project/zones/us-central1-a/instances/app-1' => Http::response([
            'status' => 'PROVISIONING',
            'networkInterfaces' => [[
                'accessConfigs' => [['natIP' => '203.0.113.70']],
            ]],
        ]),
        'https://compute.googleapis.com/compute/v1/projects/gpsh-project/zones/us-central1-a/instances' => Http::response([
            'name' => 'app-1',
        ], 200),
    ]);

    $created = (new GoogleCloudClient($credentials))->create(new CloudServerRequest(
        name: 'app-1',
        region: 'us-central1-a',
        plan: 'e2-micro',
        image: 'projects/ubuntu-os-cloud/global/images/family/ubuntu-2404-lts',
        publicKey: 'ssh-ed25519 AAAA key',
        imageLabel: 'Ubuntu 24.04 LTS',
    ));

    expect($created->id)->toBe('app-1')
        ->and($created->ip)->toBe('203.0.113.70')
        ->and($created->user)->toBe('ubuntu');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/zones/us-central1-a/instances')
            && $request->hasHeader('Authorization', 'Bearer google-token')
            && $request['machineType'] === 'zones/us-central1-a/machineTypes/e2-micro'
            && $request['metadata']['items'][0]['value'] === 'ubuntu:ssh-ed25519 AAAA key';
    });
});

it('creates an azure virtual machine with the ssh key', function () {
    Http::fake([
        'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response(['access_token' => 'azure-token']),
        'https://management.azure.com/subscriptions/sub-1/*' => function ($request) {
            $url = $request->url();

            if (str_contains($url, '/virtualMachines/')) {
                return Http::response([
                    'id' => '/subscriptions/sub-1/resourceGroups/gpsh/providers/Microsoft.Compute/virtualMachines/app-1',
                    'properties' => ['provisioningState' => 'Creating'],
                ]);
            }

            if (str_contains($url, '/publicIPAddresses/app-1-ip')) {
                return Http::response([
                    'id' => '/subscriptions/sub-1/resourceGroups/gpsh/providers/Microsoft.Network/publicIPAddresses/app-1-ip',
                    'properties' => ['ipAddress' => '203.0.113.80'],
                ]);
            }

            if (str_contains($url, '/networkSecurityGroups/')) {
                return Http::response([
                    'id' => '/subscriptions/sub-1/resourceGroups/gpsh/providers/Microsoft.Network/networkSecurityGroups/app-1-nsg',
                ]);
            }

            if (str_contains($url, '/networkInterfaces/')) {
                return Http::response([
                    'id' => '/subscriptions/sub-1/resourceGroups/gpsh/providers/Microsoft.Network/networkInterfaces/app-1-nic',
                ]);
            }

            return Http::response(['id' => 'created']);
        },
    ]);

    $created = (new AzureCloudClient('client-1', 'client-secret', 'tenant-1', 'sub-1'))->create(new CloudServerRequest(
        name: 'app-1',
        region: 'eastus',
        plan: 'Standard_B1s',
        image: 'Canonical|ubuntu-24_04-lts|server',
        publicKey: 'ssh-ed25519 AAAA key',
        imageLabel: 'Ubuntu 24.04 LTS',
    ));

    expect($created->ip)->toBe('203.0.113.80')
        ->and($created->user)->toBe('azureuser')
        ->and($created->status)->toBe('Creating');

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/virtualMachines/app-1')
            && $request->hasHeader('Authorization', 'Bearer azure-token')
            && $request['properties']['osProfile']['adminUsername'] === 'azureuser'
            && $request['properties']['osProfile']['linuxConfiguration']['ssh']['publicKeys'][0]['keyData'] === 'ssh-ed25519 AAAA key'
            && $request['properties']['storageProfile']['imageReference']['offer'] === 'ubuntu-24_04-lts';
    });
});
