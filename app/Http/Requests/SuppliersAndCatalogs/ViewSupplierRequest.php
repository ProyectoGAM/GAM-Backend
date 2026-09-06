<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use Illuminate\Foundation\Http\FormRequest;

final class ViewSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('proveedor')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
