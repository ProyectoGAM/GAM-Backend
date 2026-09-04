<?php

namespace App\Modules\Inventory\Http\Requests;

final class ListEggStockTransactionsRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['pagina' => ['sometimes', 'integer', 'min:1'], 'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'], 'estado' => ['sometimes', 'in:recorded,cancelled'], 'tipo' => ['sometimes', 'in:collection_receipt,manual_receipt,distribution_preparation,loss'], 'fecha_desde' => ['sometimes', 'date_format:Y-m-d'], 'fecha_hasta' => ['sometimes', 'date_format:Y-m-d']];
    }
}
