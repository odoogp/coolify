<?php

use App\Services\HetznerService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('getServers returns list of servers from Hetzner API', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'name' => 'test-server-1',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '123.45.67.89'],
                        'ipv6' => ['ip' => '2a01:4f8::/64'],
                    ],
                ],
                [
                    'id' => 67890,
                    'name' => 'test-server-2',
                    'status' => 'off',
                    'public_net' => [
                        'ipv4' => ['ip' => '98.76.54.32'],
                        'ipv6' => ['ip' => '2a01:4f9::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $servers = $service->getServers();

    expect($servers)->toBeArray()
        ->and(count($servers))->toBe(2)
        ->and($servers[0]['id'])->toBe(12345)
        ->and($servers[1]['id'])->toBe(67890);
});

it('findServerByIp returns matching server by IPv4', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'name' => 'test-server',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '123.45.67.89'],
                        'ipv6' => ['ip' => '2a01:4f8::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('123.45.67.89');

    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe(12345)
        ->and($result['name'])->toBe('test-server');
});

it('findServerByIp returns null when no match', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 12345,
                    'name' => 'test-server',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '123.45.67.89'],
                        'ipv6' => ['ip' => '2a01:4f8::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('1.2.3.4');

    expect($result)->toBeNull();
});

it('findServerByIp returns null when server list is empty', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('123.45.67.89');

    expect($result)->toBeNull();
});

it('findServerByIp matches correct server among multiple', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response([
            'servers' => [
                [
                    'id' => 11111,
                    'name' => 'server-a',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '10.0.0.1'],
                        'ipv6' => ['ip' => '2a01:4f8::/64'],
                    ],
                ],
                [
                    'id' => 22222,
                    'name' => 'server-b',
                    'status' => 'running',
                    'public_net' => [
                        'ipv4' => ['ip' => '10.0.0.2'],
                        'ipv6' => ['ip' => '2a01:4f9::/64'],
                    ],
                ],
                [
                    'id' => 33333,
                    'name' => 'server-c',
                    'status' => 'off',
                    'public_net' => [
                        'ipv4' => ['ip' => '10.0.0.3'],
                        'ipv6' => ['ip' => '2a01:4fa::/64'],
                    ],
                ],
            ],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    $service = new HetznerService('fake-token');
    $result = $service->findServerByIp('10.0.0.2');

    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe(22222)
        ->and($result['name'])->toBe('server-b');
});

it('loads ubuntu images that are missing from the paged system list', function () {
    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $name = $query['name'] ?? null;
        $architecture = $query['architecture'] ?? 'x86';

        if ($name === 'ubuntu-24.04') {
            return Http::response([
                'images' => [[
                    'id' => $architecture === 'arm' ? 161547270 : 161547269,
                    'name' => 'ubuntu-24.04',
                    'description' => 'Ubuntu 24.04',
                    'type' => 'system',
                    'status' => 'available',
                    'architecture' => $architecture,
                    'os_flavor' => 'ubuntu',
                    'os_version' => '24.04',
                    'deprecated' => null,
                ]],
                'meta' => ['pagination' => ['page' => 1, 'next_page' => null, 'last_page' => 1]],
            ]);
        }

        if ($name !== null) {
            return Http::response([
                'images' => [],
                'meta' => ['pagination' => ['page' => 1, 'next_page' => null, 'last_page' => 1]],
            ]);
        }

        $page = (int) ($query['page'] ?? 1);

        if ($page === 1) {
            return Http::response([
                'images' => [[
                    'id' => $architecture === 'arm' ? 11 : 10,
                    'name' => 'debian-13',
                    'description' => 'Debian 13',
                    'type' => 'system',
                    'status' => 'available',
                    'architecture' => $architecture,
                    'os_flavor' => 'debian',
                    'os_version' => '13',
                    'deprecated' => null,
                ]],
                'meta' => ['pagination' => ['page' => 1, 'next_page' => null, 'last_page' => 2]],
            ]);
        }

        return Http::response([
            'images' => [[
                'id' => $architecture === 'arm' ? 21 : 20,
                'name' => 'fedora-43',
                'description' => 'Fedora 43',
                'type' => 'system',
                'status' => 'available',
                'architecture' => $architecture,
                'os_flavor' => 'fedora',
                'os_version' => '43',
                'deprecated' => null,
            ]],
            'meta' => ['pagination' => ['page' => 2, 'next_page' => null, 'last_page' => 2]],
        ]);
    });

    $names = collect((new HetznerService('fake-token'))->getImages())->pluck('name')->unique()->values()->all();

    expect($names)->toContain('ubuntu-24.04', 'debian-13', 'fedora-43');
});

it('hides system images that can no longer be ordered', function () {
    expect(HetznerService::imageIsOrderable([
        'type' => 'system',
        'status' => 'available',
        'deprecated' => '2020-01-01T00:00:00Z',
    ]))->toBeFalse()
        ->and(HetznerService::imageIsOrderable([
            'type' => 'system',
            'status' => 'available',
            'deprecated' => true,
        ]))->toBeFalse()
        ->and(HetznerService::imageIsOrderable([
            'type' => 'snapshot',
            'status' => 'available',
            'deprecated' => null,
        ]))->toBeFalse()
        ->and(HetznerService::imageIsOrderable([
            'type' => 'system',
            'status' => 'available',
            'deprecated' => null,
            'os_flavor' => 'ubuntu',
        ]))->toBeTrue();
});
