<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Las pruebas usan transacciones; evita conservar permisos que una prueba anterior revirtió.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
