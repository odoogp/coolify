<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

class AdminCreationQuotaExceeded extends AuthorizationException
{
    public function __construct(string $resource, int $used, int $limit)
    {
        parent::__construct("Has creado {$used} de {$limit} {$resource} en este equipo.");
    }
}
