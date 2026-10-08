<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

class AdminCreationQuotaExceeded extends AuthorizationException
{
    public function __construct(string $resource, int $used, int $limit)
    {
        parent::__construct(__('To create more :resource, contact an advisor to upgrade your plan.', [
            'resource' => $resource,
        ]));
    }
}
