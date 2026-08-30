<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use App\Modules\SuppliersAndCatalogs\Domain\Enums\SupplierStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeSupplierStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStatus', $this->route('proveedor')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['estado' => ['required', Rule::enum(SupplierStatus::class)]];
    }
}
