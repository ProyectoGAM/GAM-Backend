<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\Inventory\StockLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateStockLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('stockLocation')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'production_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:production_units,id'],
            'name' => ['sometimes', 'string', 'max:160'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $location = $this->route('stockLocation');
            if ($validator->errors()->has('name') || ! $location instanceof StockLocation || ! $this->filled('name')) {
                return;
            }
            if (StockLocation::query()->where('normalized_name', Str::lower(trim($this->string('name')->toString())))->whereKeyNot($location->getKey())->exists()) {
                $validator->errors()->add('name', 'El nombre de la ubicación ya está registrado.');
            }
        }];
    }
}
