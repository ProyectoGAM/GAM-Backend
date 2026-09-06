<?php

namespace App\Queries\FarmStructure;

use App\Models\FarmStructure\Maintenance;

final readonly class GetMaintenanceQuery
{
    public function execute(int $id): Maintenance
    {
        return Maintenance::query()->findOrFail($id);
    }
}
