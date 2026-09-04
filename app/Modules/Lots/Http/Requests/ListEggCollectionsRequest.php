<?php

namespace App\Modules\Lots\Http\Requests;

use App\Models\Lots\EggCollection;

final class ListEggCollectionsRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EggCollection::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'lote_id' => ['sometimes', 'ulid'],
            'galpon_id' => ['sometimes', 'integer', 'exists:poultry_houses,id'],
            'unidad_productiva_id' => ['sometimes', 'integer', 'exists:production_units,id'],
            'estado' => ['sometimes', 'in:recorded,cancelled'],
        ];
    }
}
