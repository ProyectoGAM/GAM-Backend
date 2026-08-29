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
            'locality_id' => ['sometimes', 'required', 'integer', 'exists:localities,id'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $productionUnit = $this->route('productionUnit');

                if (! $productionUnit instanceof ProductionUnit || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->hasAny(['locality_id', 'name', 'latitude', 'longitude'])) {
                    $validator->errors()->add('request', 'Debes proporcionar al menos un campo.');

                    return;
                }

                $localityId = $this->integer('locality_id', $productionUnit->locality_id);
                $name = $this->has('name') ? $this->string('name')->toString() : $productionUnit->name;
                $exists = ProductionUnit::query()
                    ->whereKeyNot($productionUnit->getKey())
                    ->where('locality_id', $localityId)
                    ->where('normalized_name', Str::lower(trim($name)))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'El nombre ya está registrado en esta localidad.');
                }
            },
        ];
    }
}
