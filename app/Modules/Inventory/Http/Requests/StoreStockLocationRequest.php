<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\Inventory\StockLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class StoreStockLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StockLocation::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'production_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:production_units,id'],
            'name' => ['required', 'string', 'max:160'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('name')) {
                return;
            }
            if (StockLocation::query()->where('normalized_name', Str::lower(trim($this->string('name')->toString())))->exists()) {
                $validator->errors()->add('name', 'El nombre de la ubicación ya está registrado.');
            }
        }];
    }
}
