<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AzureCloudClient extends AdditionalCloudServerClient
{
    private ?string $accessToken = null;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $tenantId,
        private string $subscriptionId,
    ) {}

    public function ping(): bool
    {
        try {
            return $this->http()->get($this->subscriptionUrl().'/locations?api-version=2022-12-01')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function regions(): array
    {
        $response = $this->http()->get($this->subscriptionUrl().'/locations?api-version=2022-12-01');
        $this->throwIfFailed($response, 'Azure regions');
        $regions = [];

        foreach ($response->json('value') ?? [] as $location) {
            $id = (string) ($location['name'] ?? '');

            if ($id === '') {
                continue;
            }

            $regions[] = [
                'id' => $id,
                'label' => (string) ($location['displayName'] ?? $id),
            ];
        }

        return $regions;
    }

    public function plans(string $region): array
    {
        $catalog = [
            'Standard_B1s' => 'Standard_B1s · 1 vCPU · 1 GB',
            'Standard_B2s' => 'Standard_B2s · 2 vCPU · 4 GB',
            'Standard_B2ms' => 'Standard_B2ms · 2 vCPU · 8 GB',
            'Standard_D2s_v5' => 'Standard_D2s_v5 · 2 vCPU · 8 GB',
        ];
        $response = $this->http()->get(
            $this->subscriptionUrl().'/providers/Microsoft.Compute/locations/'.$region.'/vmSizes?api-version=2024-03-01'
        );

        if ($response->successful()) {
            $offered = collect($response->json('value') ?? [])->pluck('name')->all();
            $plans = [];

            foreach ($catalog as $id => $label) {
                if (in_array($id, $offered, true)) {
                    $plans[] = ['id' => $id, 'label' => $label];
                }
            }

            if ($plans !== []) {
                return $plans;
            }
        }

        return array_map(
            fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
            array_keys($catalog),
            array_values($catalog),
        );
    }

    public function images(string $region): array
    {
        return $this->preferOperatingSystems([
            ['id' => 'Canonical|ubuntu-24_04-lts|server', 'label' => 'Ubuntu 24.04 LTS'],
            ['id' => 'Canonical|0001-com-ubuntu-server-jammy|22_04-lts-gen2', 'label' => 'Ubuntu 22.04 LTS'],
            ['id' => 'Debian|debian-12|12-gen2', 'label' => 'Debian 12'],
        ]);
    }

    public function create(CloudServerRequest $request): CloudServerResult
    {
        $name = $this->resourceName($request->name);
        $group = 'gpsh';
        $location = $request->region;
        $this->put('/resourceGroups/'.$group.'?api-version=2021-04-01', [
            'location' => $location,
        ], 'Azure resource group');

        $networkName = 'gpsh-'.$location;
        $this->put('/resourceGroups/'.$group.'/providers/Microsoft.Network/virtualNetworks/'.$networkName.'?api-version=2023-11-01', [
            'location' => $location,
            'properties' => [
                'addressSpace' => ['addressPrefixes' => ['10.42.0.0/16']],
                'subnets' => [[
                    'name' => 'gpsh',
                    'properties' => ['addressPrefix' => '10.42.0.0/24'],
                ]],
            ],
        ], 'Azure network');

        $subnetId = $this->subscriptionUrl().'/resourceGroups/'.$group.'/providers/Microsoft.Network/virtualNetworks/'.$networkName.'/subnets/gpsh';
        $nsgName = $name.'-nsg';
        $nsg = $this->put('/resourceGroups/'.$group.'/providers/Microsoft.Network/networkSecurityGroups/'.$nsgName.'?api-version=2023-11-01', [
            'location' => $location,
            'properties' => [
                'securityRules' => [[
                    'name' => 'ssh',
                    'properties' => [
                        'priority' => 1000,
                        'access' => 'Allow',
                        'direction' => 'Inbound',
                        'protocol' => 'Tcp',
                        'sourcePortRange' => '*',
                        'destinationPortRange' => '22',
                        'sourceAddressPrefix' => '*',
                        'destinationAddressPrefix' => '*',
                    ],
                ]],
            ],
        ], 'Azure firewall');

        $ipName = $name.'-ip';
        $publicIp = $this->put('/resourceGroups/'.$group.'/providers/Microsoft.Network/publicIPAddresses/'.$ipName.'?api-version=2023-11-01', [
            'location' => $location,
            'sku' => ['name' => 'Standard'],
            'properties' => ['publicIPAllocationMethod' => 'Static'],
        ], 'Azure public IP');

        $nic = $this->put('/resourceGroups/'.$group.'/providers/Microsoft.Network/networkInterfaces/'.$name.'-nic?api-version=2023-11-01', [
            'location' => $location,
            'properties' => [
                'networkSecurityGroup' => ['id' => $nsg->json('id')],
                'ipConfigurations' => [[
                    'name' => 'ipconfig',
                    'properties' => [
                        'subnet' => ['id' => $subnetId],
                        'publicIPAddress' => ['id' => $publicIp->json('id')],
                    ],
                ]],
            ],
        ], 'Azure network interface');

        [$publisher, $offer, $sku] = array_pad(explode('|', $request->image), 3, '');
        $vm = $this->put('/resourceGroups/'.$group.'/providers/Microsoft.Compute/virtualMachines/'.$name.'?api-version=2024-03-01', [
            'location' => $location,
            'properties' => [
                'hardwareProfile' => ['vmSize' => $request->plan],
                'storageProfile' => [
                    'imageReference' => [
                        'publisher' => $publisher,
                        'offer' => $offer,
                        'sku' => $sku,
                        'version' => 'latest',
                    ],
                    'osDisk' => [
                        'createOption' => 'FromImage',
                        'managedDisk' => ['storageAccountType' => 'Standard_LRS'],
                    ],
                ],
                'osProfile' => [
                    'computerName' => rtrim(substr($name, 0, 15), '-'),
                    'adminUsername' => 'azureuser',
                    'linuxConfiguration' => [
                        'disablePasswordAuthentication' => true,
                        'ssh' => [
                            'publicKeys' => [[
                                'path' => '/home/azureuser/.ssh/authorized_keys',
                                'keyData' => $request->publicKey,
                            ]],
                        ],
                    ],
                ],
                'networkProfile' => [
                    'networkInterfaces' => [[
                        'id' => $nic->json('id'),
                        'properties' => ['primary' => true],
                    ]],
                ],
            ],
        ], 'Azure create');

        $address = $publicIp->json('properties.ipAddress');

        if (! is_string($address) || $this->usableIp($address) === null) {
            $read = $this->http()->get($this->subscriptionUrl().'/resourceGroups/'.$group.'/providers/Microsoft.Network/publicIPAddresses/'.$ipName.'?api-version=2023-11-01');
            $address = $read->successful() ? $read->json('properties.ipAddress') : null;
        }

        return new CloudServerResult(
            id: (string) ($vm->json('id') ?? $name),
            status: (string) ($vm->json('properties.provisioningState') ?? 'Creating'),
            ip: $this->usableIp(is_string($address) ? $address : null),
            user: 'azureuser',
        );
    }

    public function delete(string $id, ?string $region = null): void
    {
        if (str_starts_with($id, 'https://')) {
            $url = $id;
        } elseif (str_starts_with($id, '/subscriptions/')) {
            $url = 'https://management.azure.com'.$id;
        } else {
            $url = $this->subscriptionUrl().'/resourceGroups/gpsh/providers/Microsoft.Compute/virtualMachines/'.$id;
        }

        $response = $this->http()->delete($url.(str_contains($url, '?') ? '&' : '?').'api-version=2024-03-01');
        $this->throwIfFailed($response, 'Azure delete');
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function put(string $path, array $body, string $action): \Illuminate\Http\Client\Response
    {
        $response = $this->http()->put($this->subscriptionUrl().$path, $body);
        $this->throwIfFailed($response, $action);

        return $response;
    }

    private function accessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if ($this->clientId === '' || $this->clientSecret === '' || $this->tenantId === '' || $this->subscriptionId === '') {
            throw new RuntimeException('Azure needs the application ID, tenant ID, subscription ID, and client secret.');
        }

        $response = Http::asForm()->timeout(20)->post(
            'https://login.microsoftonline.com/'.$this->tenantId.'/oauth2/v2.0/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://management.azure.com/.default',
            ]
        );
        $this->throwIfFailed($response, 'Azure authentication');
        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new RuntimeException('Azure authentication failed.');
        }

        return $this->accessToken = $token;
    }

    private function subscriptionUrl(): string
    {
        return 'https://management.azure.com/subscriptions/'.$this->subscriptionId;
    }

    private function resourceName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]+/', '-', $name) ?? $name;
        $name = trim($name, '-');

        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = 'vm'.$name;
        }

        return substr($name, 0, 40);
    }
}
