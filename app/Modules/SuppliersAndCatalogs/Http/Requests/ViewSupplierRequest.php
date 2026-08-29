<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ViewSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('supplier')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
