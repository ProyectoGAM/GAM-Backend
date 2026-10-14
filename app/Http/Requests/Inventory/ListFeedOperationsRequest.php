<?php

namespace App\Http\Requests\Inventory;

final class ListFeedOperationsRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'receta_id' => $this->identifierRules(), 'up_id' => $this->identifierRules(),
            'planta_id' => $this->identifierRules(),
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
        if ($this->routeIs('api.v1.feed-recipes.manufactures.index')) {
            $rules['estado'] = ['sometimes', 'in:elaborada,cancelada'];
        } else {
            $rules['producto_id'] = $this->identifierRules();
            $rules['tipo'] = ['sometimes', 'in:ingrediente,alimento'];
        }

        return $rules;
    }
}
