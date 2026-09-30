<?php

namespace App\Http\Requests\Inventory;

use App\Enums\Inventory\StockLocationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeStockLocationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStatus', $this->route('stockLocation')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(StockLocationStatus::class)]];
    }
}
