<?php

namespace App\Services\Cloud;

final class CloudServerRequest
{
    public function __construct(
        public string $name,
        public string $region,
        public string $plan,
        public string $image,
        public string $publicKey,
        public string $imageLabel = '',
    ) {}
}
