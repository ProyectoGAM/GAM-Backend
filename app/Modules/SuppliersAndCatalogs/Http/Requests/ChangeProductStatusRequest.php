<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeProductStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStatus', $this->route('producto')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['estado' => ['required', Rule::enum(ProductStatus::class)]];
    }
}
