<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Models\FarmStructure\ProductionUnit;
use App\Services\FarmStructure\UruguayBoundary;
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
            'locality_id' => ['sometimes', 'nullable', 'integer', 'exists:localities,id'],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', Rule::enum(ProductionUnitStatus::class)],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['latitude', 'longitude'] as $coordinate) {
                    $value = $this->input($coordinate);

                    if (is_numeric($value) && ! is_finite((float) $value)) {
                        $validator->errors()->add($coordinate, 'La coordenada debe ser un número finito.');
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! app(UruguayBoundary::class)->contains(
                    (float) $this->input('latitude'),
                    (float) $this->input('longitude'),
                )) {
                    $validator->errors()->add('latitude', 'La ubicación debe encontrarse dentro de Uruguay.');

                    return;
                }

                $localityId = $this->filled('locality_id') ? $this->integer('locality_id') : null;
                if ($localityId === null) {
                    return;
                }

                $query = ProductionUnit::query()
                    ->where('locality_id', $localityId)
                    ->where('normalized_name', Str::lower(trim($this->string('name')->toString())));
                $exists = $query->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'El nombre ya está registrado en esta localidad.');
                }
            },
        ];
    }
}
