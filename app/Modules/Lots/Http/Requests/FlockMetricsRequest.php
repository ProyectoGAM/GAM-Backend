<?php

namespace App\Modules\Lots\Http\Requests;

use App\Models\Lots\EggCollection;

final class FlockMetricsRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EggCollection::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_desde' => ['sometimes', 'date_format:Y-m-d'],
            'fecha_hasta' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('fecha_desde') ? ['after_or_equal:fecha_desde'] : [])],
            'lote_id' => ['sometimes', 'ulid'],
            'galpon_id' => ['sometimes', 'integer', 'exists:poultry_houses,id'],
        ];
    }
}
