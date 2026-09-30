<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->environment('testing')) {
            throw new \RuntimeException('Refusing to run database tests because APP_ENV is not testing.');
        }

        if ($app['config']->get('database.default') !== 'pgsql') {
            throw new \RuntimeException('Refusing to run database tests because PostgreSQL is not configured.');
        }

        $database = $app['config']->get('database.connections.pgsql.database');

        if (! is_string($database) || ! str_ends_with($database, '_testing')) {
            throw new \RuntimeException('Refusing to run database tests because DB_DATABASE does not end with "_testing".');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Las pruebas usan transacciones; evita conservar permisos que una prueba anterior revirtió.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
