<?php

namespace App\Modules\Lots\Http\Requests;

final class ListFlocksRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('viewAny');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'buscar' => ['sometimes', 'string', 'max:120'],
            'galpon_id' => ['sometimes', 'integer', 'exists:poultry_houses,id'],
            'unidad_productiva_id' => ['sometimes', 'integer', 'exists:production_units,id'],
            'raza_id' => ['sometimes', 'integer', 'exists:breeds,id'],
            'proveedor_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'estado' => ['sometimes', 'in:active,quarantined,finished'],
        ];
    }
}
