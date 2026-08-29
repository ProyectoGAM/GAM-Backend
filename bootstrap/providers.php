<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditAndTraceabilityServiceProvider;
use App\Providers\FarmStructureServiceProvider;

return [
    AppServiceProvider::class,
    AuditAndTraceabilityServiceProvider::class,
    FarmStructureServiceProvider::class,
];
