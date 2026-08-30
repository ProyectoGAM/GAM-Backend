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
            'estado' => ['sometimes', Rule::enum(MaintenanceStatus::class)],
            'fecha_desde' => ['sometimes', 'date_format:Y-m-d'],
            'fecha_hasta' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('fecha_desde'), 'after_or_equal:fecha_desde')],
            'por_pagina' => ['sometimes', 'integer', 'between:1,100'],
            'pagina' => ['sometimes', 'integer', 'between:1,100000'],
        ];
    }
}
