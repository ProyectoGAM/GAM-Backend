<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateProductionUnitRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizeProductionUnit('update');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'localidad_id' => ['sometimes', 'required', 'integer', 'exists:localities,id'],
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'latitud' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitud' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unidadProductiva = $this->route('unidadProductiva');

                if (! $unidadProductiva instanceof ProductionUnit || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->hasAny(['localidad_id', 'nombre', 'latitud', 'longitud'])) {
                    $validator->errors()->add('request', 'Debes proporcionar al menos un campo.');

                    return;
                }

                $localityId = $this->integer('localidad_id', $unidadProductiva->locality_id);
                $nombre = $this->has('nombre') ? $this->string('nombre')->toString() : $unidadProductiva->name;
                $exists = ProductionUnit::query()
                    ->whereKeyNot($unidadProductiva->getKey())
                    ->where('locality_id', $localityId)
                    ->where('normalized_name', Str::lower(trim($nombre)))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('nombre', 'El nombre ya está registrado en esta localidad.');
                }
            },
        ];
    }
}
