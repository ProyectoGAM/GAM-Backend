<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class ViewStockLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('ubicacionStock')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
