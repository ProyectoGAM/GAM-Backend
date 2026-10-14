<?php

namespace App\Http\Requests\Inventory;

final class ListFeedBalancesRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'receta_id' => $this->identifierRules(), 'up_id' => $this->identifierRules(),
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
