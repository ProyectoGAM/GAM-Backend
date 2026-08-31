<?php

namespace App\Modules\Lots\Http\Requests;

final class StoreFlockRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(false),
            'id' => ['sometimes', 'ulid'],
            'codigo' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'raza_id' => ['required', 'integer', 'min:1'],
            'proveedor_id' => ['nullable', 'integer', 'min:1', 'required_without:origen'],
            'origen' => ['nullable', 'string', 'max:255', 'required_without:proveedor_id'],
            'galpon_id' => ['required', 'integer', 'min:1'],
            'cantidad_inicial' => $this->quantityRules(),
            'fecha_ingreso' => ['required', 'date_format:Y-m-d'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
