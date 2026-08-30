<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\Maintenance;

final class LatestMaintenanceRequest extends MaintenanceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Maintenance::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
