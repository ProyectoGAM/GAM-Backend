<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\ProductionUnit;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreProductionUnitRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProductionUnit::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'localidad_id' => ['required', 'integer', 'exists:localities,id'],
            'nombre' => ['required', 'string', 'max:120'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'estado' => ['sometimes', Rule::enum(ProductionUnitStatus::class)],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['localidad_id', 'nombre'])) {
                    return;
                }

                $exists = ProductionUnit::query()
                    ->where('locality_id', $this->integer('localidad_id'))
                    ->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('nombre', 'El nombre ya está registrado en esta localidad.');
                }
            },
        ];
    }
}
