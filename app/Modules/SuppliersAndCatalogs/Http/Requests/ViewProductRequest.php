<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ViewProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('producto')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
