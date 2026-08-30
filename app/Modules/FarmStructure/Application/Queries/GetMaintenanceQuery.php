<?php

namespace App\Modules\FarmStructure\Application\Queries;

use App\Models\FarmStructure\Maintenance;

final readonly class GetMaintenanceQuery
{
    public function execute(int $id): Maintenance
    {
        return Maintenance::query()->findOrFail($id);
    }
}
