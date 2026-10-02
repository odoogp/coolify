<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

class AdminCreationQuotaExceeded extends AuthorizationException
{
    public function __construct(string $resource, int $used, int $limit)
    {
        parent::__construct(__('You cannot create more :resource. Contact support and ask for a new plan.', [
            'resource' => $resource,
        ]));
    }
}
