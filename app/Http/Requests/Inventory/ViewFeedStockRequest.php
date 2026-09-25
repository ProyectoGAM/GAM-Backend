<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\StockBalance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ViewFeedStockRequest extends FormRequest
{
    /** Determina si el usuario puede consultar el stock de alimentación. */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', StockBalance::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [];
    }
}
