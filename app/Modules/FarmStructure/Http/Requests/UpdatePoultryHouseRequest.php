<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\PoultryHouse;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdatePoultryHouseRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizePoultryHouse('update');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'capacidad_aves' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $poultryHouse = $this->route('poultryHouse');

                if (! $poultryHouse instanceof PoultryHouse || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->hasAny(['nombre', 'capacidad_aves'])) {
                    $validator->errors()->add('request', 'Debes proporcionar al menos un campo.');

                    return;
                }

                $nombre = $this->has('nombre') ? $this->string('nombre')->toString() : $poultryHouse->name;
                $exists = PoultryHouse::query()
                    ->whereKeyNot($poultryHouse->getKey())
                    ->where('production_unit_id', $poultryHouse->production_unit_id)
                    ->where('normalized_name', Str::lower(trim($nombre)))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('nombre', 'El nombre ya está registrado en esta unidad productiva.');
                }
            },
        ];
    }
}
