<?php

namespace App\Http\Requests\Inventory;

final class ListFeedBalancesRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'receta_id' => $this->identifierRules(), 'up_id' => $this->identifierRules(),
            'requiere_atencion' => ['sometimes', 'boolean'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'requiere_atencion.boolean' => 'El filtro requiere_atencion debe ser 1 o 0.'];
    }
}
