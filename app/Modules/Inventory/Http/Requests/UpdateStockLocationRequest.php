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
        return $this->user()?->can('update', $this->route('ubicacionStock')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'unidad_productiva_id' => ['sometimes', 'nullable', 'integer', 'exists:production_units,id'],
            'nombre' => ['sometimes', 'string', 'max:160'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $location = $this->route('ubicacionStock');
            if ($validator->errors()->has('nombre') || ! $location instanceof StockLocation || ! $this->filled('nombre')) {
                return;
            }
            if (StockLocation::query()->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))->whereKeyNot($location->getKey())->exists()) {
                $validator->errors()->add('nombre', 'El nombre de la ubicación ya está registrado.');
            }
        }];
    }
}
