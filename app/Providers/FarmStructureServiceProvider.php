<?php

namespace App\Providers;

use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Modules\FarmStructure\Application\PublicApi\Contracts\PoultryHouseOccupancyProvider;
use App\Modules\FarmStructure\Infrastructure\Occupancy\EmptyPoultryHouseOccupancyProvider;
use App\Policies\FarmStructure\MaintenancePolicy;
use App\Policies\FarmStructure\PoultryHousePolicy;
use App\Policies\FarmStructure\ProductionUnitPolicy;
use App\Policies\Geography\DepartmentPolicy;
use App\Policies\Geography\LocalityPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class FarmStructureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(
            PoultryHouseOccupancyProvider::class,
            EmptyPoultryHouseOccupancyProvider::class,
        );
    }

    public function boot(): void
    {
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Locality::class, LocalityPolicy::class);
        Gate::policy(ProductionUnit::class, ProductionUnitPolicy::class);
        Gate::policy(PoultryHouse::class, PoultryHousePolicy::class);
        Gate::policy(Maintenance::class, MaintenancePolicy::class);
    }
}
