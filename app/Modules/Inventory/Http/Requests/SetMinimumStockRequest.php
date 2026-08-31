<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetMinimumStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $stockBalance = $this->route('stockBalance');

        return $this->user()?->can('manage', $stockBalance) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['cantidad_minima' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/']];
    }
}
