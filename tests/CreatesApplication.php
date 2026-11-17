<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ($app['config']->get('database.default') !== 'testing') {
            throw new RuntimeException('Tests must use the "testing" database connection, got "'.$app['config']->get('database.default').'". Refusing to run against a real database.');
        }

        return $app;
    }
}
