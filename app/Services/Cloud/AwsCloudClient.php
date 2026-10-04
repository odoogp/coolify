<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class AwsCloudClient extends AdditionalCloudServerClient
{
    public function __construct(private string $accessKey, private string $secretKey) {}

    public function ping(): bool
    {
        try {
            return $this->call('us-east-1', ['Action' => 'DescribeRegions'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function regions(): array
    {
        $xml = $this->xml($this->call('us-east-1', ['Action' => 'DescribeRegions']), 'AWS regions');
        $regions = [];

        foreach ($this->xmlValues($xml, 'regionName') as $name) {
            $regions[] = ['id' => $name, 'label' => $name];
        }

        usort($regions, fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return $regions;
    }

    public function plans(string $region): array
    {
        $catalog = [
            't3.micro' => 't3.micro · 2 vCPU · 1 GB',
            't3.small' => 't3.small · 2 vCPU · 2 GB',
            't3.medium' => 't3.medium · 2 vCPU · 4 GB',
            't3.large' => 't3.large · 2 vCPU · 8 GB',
            'm6i.large' => 'm6i.large · 2 vCPU · 8 GB',
            'c6i.large' => 'c6i.large · 2 vCPU · 4 GB',
        ];

        try {
            $xml = $this->xml($this->call($region, [
                'Action' => 'DescribeInstanceTypeOfferings',
                'LocationType' => 'region',
            ]), 'AWS plans');
            $offered = $this->xmlValues($xml, 'instanceType');
            $plans = [];

            foreach ($catalog as $id => $label) {
                if (in_array($id, $offered, true)) {
                    $plans[] = ['id' => $id, 'label' => $label];
                }
            }

            if ($plans !== []) {
                return $plans;
            }
        } catch (\Throwable) {
            // The curated list still lets the user continue when the offering call fails.
        }

        return array_map(
            fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
            array_keys($catalog),
            array_values($catalog),
        );
    }

    public function images(string $region): array
    {
        $queries = [
            ['owner' => '099720109477', 'name' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*', 'label' => 'Ubuntu 24.04 LTS'],
            ['owner' => '099720109477', 'name' => 'ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-amd64-server-*', 'label' => 'Ubuntu 22.04 LTS'],
            ['owner' => '136693071363', 'name' => 'debian-12-amd64-*', 'label' => 'Debian 12'],
        ];
        $images = [];

        foreach ($queries as $query) {
            $response = $this->call($region, [
                'Action' => 'DescribeImages',
                'Owner.1' => $query['owner'],
                'Filter.1.Name' => 'name',
                'Filter.1.Value.1' => $query['name'],
                'Filter.2.Name' => 'state',
                'Filter.2.Value.1' => 'available',
            ]);

            if (! $response->successful()) {
                continue;
            }

            $xml = $this->xml($response, 'AWS images');
            $ids = $this->xmlValues($xml, 'imageId');
            $names = $this->xmlValues($xml, 'name');
            $pairs = [];

            foreach ($ids as $index => $id) {
                $pairs[] = ['id' => $id, 'name' => $names[$index] ?? ''];
            }

            usort($pairs, fn (array $left, array $right): int => strcmp($right['name'], $left['name']));

            if ($pairs === []) {
                continue;
            }

            $images[] = [
                'id' => $pairs[0]['id'],
                'label' => $query['label'],
            ];
        }

        return $this->preferOperatingSystems($images);
    }

    public function create(CloudServerRequest $request): CloudServerResult
    {
        $keyName = 'gpsh-'.substr(sha1($request->publicKey), 0, 12);
        $imported = $this->call($request->region, [
            'Action' => 'ImportKeyPair',
            'KeyName' => $keyName,
            'PublicKeyMaterial' => $request->publicKey,
        ]);

        if (! $imported->successful() && ! str_contains($imported->body(), 'InvalidKeyPair.Duplicate')) {
            $this->throwIfFailed($imported, 'AWS SSH key');
        }

        $groupId = $this->securityGroup($request->region);
        $response = $this->call($request->region, [
            'Action' => 'RunInstances',
            'ImageId' => $request->image,
            'InstanceType' => $request->plan,
            'MinCount' => '1',
            'MaxCount' => '1',
            'KeyName' => $keyName,
            'SecurityGroupId.1' => $groupId,
            'TagSpecification.1.ResourceType' => 'instance',
            'TagSpecification.1.Tag.1.Key' => 'Name',
            'TagSpecification.1.Tag.1.Value' => $request->name,
        ]);
        $xml = $this->xml($response, 'AWS create');
        $id = $this->xmlValues($xml, 'instanceId')[0] ?? '';
        $ip = $this->xmlValues($xml, 'ipAddress')[0] ?? null;

        if ($id !== '' && ! $this->usableIp($ip)) {
            $details = $this->call($request->region, [
                'Action' => 'DescribeInstances',
                'InstanceId.1' => $id,
            ]);

            if ($details->successful()) {
                $ip = $this->xmlValues($this->xml($details, 'AWS instance'), 'ipAddress')[0] ?? null;
            }
        }

        return new CloudServerResult(
            id: $id,
            status: 'pending',
            ip: $this->usableIp(is_string($ip) ? $ip : null),
            user: $this->userForImage($request->imageLabel),
        );
    }

    public function delete(string $id, ?string $region = null): void
    {
        if ($region === null || $region === '') {
            throw new RuntimeException('AWS region is required to terminate an instance.');
        }

        $this->xml($this->call($region, [
            'Action' => 'TerminateInstances',
            'InstanceId.1' => $id,
        ]), 'AWS delete');
    }

    /**
     * @param  array<string, string>  $params
     */
    public function call(string $region, array $params, ?string $amzDate = null): Response
    {
        if ($this->accessKey === '' || $this->secretKey === '') {
            throw new RuntimeException('AWS access key ID and secret access key are required.');
        }

        $params['Version'] = '2016-11-15';
        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $amzDate ??= gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);
        $host = 'ec2.'.$region.'.amazonaws.com';
        $payloadHash = hash('sha256', '');
        $canonicalHeaders = "host:{$host}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-date';
        $canonicalRequest = implode("\n", [
            'GET',
            '/',
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $scope = "{$dateStamp}/{$region}/ec2/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp, $region));
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return Http::withHeaders([
            'Authorization' => $authorization,
            'x-amz-date' => $amzDate,
        ])->timeout(25)->get("https://{$host}/?{$query}");
    }

    private function securityGroup(string $region): string
    {
        $vpcId = $this->defaultVpc($region);
        $existing = $this->call($region, [
            'Action' => 'DescribeSecurityGroups',
            'Filter.1.Name' => 'group-name',
            'Filter.1.Value.1' => 'gpsh-ssh',
            'Filter.2.Name' => 'vpc-id',
            'Filter.2.Value.1' => $vpcId,
        ]);
        $groupId = $existing->successful()
            ? ($this->xmlValues($this->xml($existing, 'AWS security groups'), 'groupId')[0] ?? '')
            : '';

        if ($groupId !== '') {
            return $groupId;
        }

        $created = $this->xml($this->call($region, [
            'Action' => 'CreateSecurityGroup',
            'GroupName' => 'gpsh-ssh',
            'GroupDescription' => 'SSH access for GPSH servers',
            'VpcId' => $vpcId,
        ]), 'AWS security group');
        $groupId = $this->xmlValues($created, 'groupId')[0] ?? '';

        if ($groupId === '') {
            throw new RuntimeException('AWS did not return a security group id.');
        }

        $this->xml($this->call($region, [
            'Action' => 'AuthorizeSecurityGroupIngress',
            'GroupId' => $groupId,
            'IpPermissions.1.IpProtocol' => 'tcp',
            'IpPermissions.1.FromPort' => '22',
            'IpPermissions.1.ToPort' => '22',
            'IpPermissions.1.IpRanges.1.CidrIp' => '0.0.0.0/0',
        ]), 'AWS security group rule');

        return $groupId;
    }

    private function defaultVpc(string $region): string
    {
        $xml = $this->xml($this->call($region, [
            'Action' => 'DescribeVpcs',
            'Filter.1.Name' => 'isDefault',
            'Filter.1.Value.1' => 'true',
        ]), 'AWS default VPC');
        $vpcId = $this->xmlValues($xml, 'vpcId')[0] ?? '';

        if ($vpcId === '') {
            throw new RuntimeException('This AWS region has no default VPC. Create one in the AWS console, then try again.');
        }

        return $vpcId;
    }

    private function signingKey(string $dateStamp, string $region): string
    {
        $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4'.$this->secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 'ec2', $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    private function xml(Response $response, string $action): SimpleXMLElement
    {
        $this->throwIfFailed($response, $action);
        $body = preg_replace('/xmlns(:\w+)?="[^"]+"/', '', $response->body()) ?? $response->body();
        $xml = simplexml_load_string($body);

        if ($xml === false) {
            throw new RuntimeException($action.' returned an unreadable response.');
        }

        return $xml;
    }

    /**
     * @return list<string>
     */
    private function xmlValues(SimpleXMLElement $xml, string $name): array
    {
        $found = [];

        if ($xml->getName() === $name) {
            $value = trim((string) $xml);

            if ($value !== '') {
                $found[] = $value;
            }
        }

        foreach ($xml->children() as $child) {
            $found = array_merge($found, $this->xmlValues($child, $name));
        }

        return $found;
    }
}
