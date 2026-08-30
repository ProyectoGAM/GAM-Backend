<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditAndTraceabilityServiceProvider;
use App\Providers\FarmStructureServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\ReportingAndAnalyticsServiceProvider;
use App\Providers\SuppliersAndCatalogsServiceProvider;

return [
    AppServiceProvider::class,
    AuditAndTraceabilityServiceProvider::class,
    FarmStructureServiceProvider::class,
    HorizonServiceProvider::class,
    InventoryServiceProvider::class,
    ReportingAndAnalyticsServiceProvider::class,
    SuppliersAndCatalogsServiceProvider::class,
];
