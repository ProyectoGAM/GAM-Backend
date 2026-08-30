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
            'nombre' => ['required', 'string', 'max:120'],
            'capacidad_aves' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unidadProductiva = $this->route('unidadProductiva');

                if (! $unidadProductiva instanceof ProductionUnit || $validator->errors()->has('nombre')) {
                    return;
                }

                $exists = PoultryHouse::query()
                    ->whereBelongsTo($unidadProductiva)
                    ->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('nombre', 'El nombre ya está registrado en esta unidad productiva.');
                }
            },
        ];
    }
}
