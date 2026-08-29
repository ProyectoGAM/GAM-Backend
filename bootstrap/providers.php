<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditAndTraceabilityServiceProvider;
use App\Providers\FarmStructureServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\SuppliersAndCatalogsServiceProvider;

return [
    AppServiceProvider::class,
    AuditAndTraceabilityServiceProvider::class,
    FarmStructureServiceProvider::class,
    SuppliersAndCatalogsServiceProvider::class,
    InventoryServiceProvider::class,
];
