<?php

namespace App\Http\Requests\Inventory;

final class ListEggStockTransactionsRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'status' => ['sometimes', 'in:recorded,cancelled'], 'type' => ['sometimes', 'in:collection_receipt,manual_receipt,distribution_preparation,loss'], 'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d']];
    }
}
