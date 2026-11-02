<?php

namespace App\Http\Requests\Inventory;

final class ViewEggStockRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('can_view_egg_stock') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
