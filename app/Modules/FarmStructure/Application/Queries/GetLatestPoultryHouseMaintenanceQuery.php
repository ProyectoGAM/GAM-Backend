<?php

namespace App\Modules\FarmStructure\Application\Queries;

use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Modules\FarmStructure\Domain\Enums\MaintenanceStatus;

final readonly class GetLatestPoultryHouseMaintenanceQuery
{
    public function execute(PoultryHouse $poultryHouse): ?Maintenance
    {
        return Maintenance::query()->whereBelongsTo($poultryHouse)
            ->where('status', MaintenanceStatus::Completed)
            ->orderByDesc('maintenance_date')->orderByDesc('id')->first();
    }
}
