<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class StorePoultryHouseRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizePoultryHouseCollection('create');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'bird_capacity' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $productionUnit = $this->route('productionUnit');

                if (! $productionUnit instanceof ProductionUnit || $validator->errors()->has('name')) {
                    return;
                }

                $exists = PoultryHouse::query()
                    ->whereBelongsTo($productionUnit)
                    ->where('normalized_name', Str::lower(trim($this->string('name')->toString())))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'El nombre ya está registrado en esta unidad productiva.');
                }
            },
        ];
    }
}
