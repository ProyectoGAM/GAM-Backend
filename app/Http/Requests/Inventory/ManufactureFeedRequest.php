<?php

namespace App\Http\Requests\Inventory;

final class ManufactureFeedRequest extends FeedOperationRequest
{
    protected string $permissionAbility = 'manufacture';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'receta_id' => $this->identifierRules(true), 'planta_id' => $this->identifierRules(true),
            'cantidad_g' => $this->stockGramRules(), 'idempotency_key' => ['required', 'uuid'],
            'confirmacion_faltante' => ['sometimes', 'bail', 'required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'confirmacion_faltante.*' => 'La confirmación del faltante debe ser el código devuelto por el sistema.',
        ];
    }
}
