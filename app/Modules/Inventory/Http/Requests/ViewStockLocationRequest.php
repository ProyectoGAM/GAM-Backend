<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ViewStockLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('stockLocation')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
