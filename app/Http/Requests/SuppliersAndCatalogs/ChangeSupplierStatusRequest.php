<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeSupplierStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStatus', $this->route('supplier')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(SupplierStatus::class)]];
    }
}
