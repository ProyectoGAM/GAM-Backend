<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\Maintenance;
use App\Modules\FarmStructure\Domain\Enums\MaintenanceStatus;
use Illuminate\Validation\Rule;

final class ListMaintenancesRequest extends MaintenanceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Maintenance::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(MaintenanceStatus::class)],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), 'after_or_equal:date_from')],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
        ];
    }
}
