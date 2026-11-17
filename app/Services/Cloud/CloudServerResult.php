<?php

namespace App\Services\Cloud;

final class CloudServerResult
{
    public function __construct(
        public string $id,
        public ?string $status,
        public ?string $ip,
        public string $user = 'root',
    ) {}
}
