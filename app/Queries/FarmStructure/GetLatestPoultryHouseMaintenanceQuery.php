<?php

namespace App\Queries\FarmStructure;

use App\Enums\FarmStructure\MaintenanceStatus;
use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;

final readonly class GetLatestPoultryHouseMaintenanceQuery
{
    public function execute(PoultryHouse $poultryHouse): ?Maintenance
    {
        return Maintenance::query()->whereBelongsTo($poultryHouse)
            ->where('status', MaintenanceStatus::Completed)
            ->orderByDesc('maintenance_date')->orderByDesc('id')->first();
    }
}
