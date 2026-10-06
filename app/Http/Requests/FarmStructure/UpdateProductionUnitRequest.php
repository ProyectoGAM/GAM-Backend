<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Models\FarmStructure\ProductionUnit;
use App\Services\FarmStructure\UruguayBoundary;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProductionUnitRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizeProductionUnit('update')
            && (! $this->exists('status') || $this->authorizeProductionUnit('changeStatus'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'locality_id' => ['sometimes', 'nullable', 'integer', 'exists:localities,id'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
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

                $productionUnit = $this->route('productionUnit');

                if (! $productionUnit instanceof ProductionUnit || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->hasAny(['locality_id', 'name', 'address', 'latitude', 'longitude', 'status'])) {
                    $validator->errors()->add('request', 'Debes proporcionar al menos un campo.');

                    return;
                }

                foreach (['latitude', 'longitude'] as $coordinate) {
                    if ($this->exists($coordinate)
                        && (float) $this->input($coordinate) !== (float) $productionUnit->{$coordinate}
                        && ! $this->filled('address')) {
                        $validator->errors()->add('address', 'Debes proporcionar una dirección al cambiar la ubicación.');

                        return;
                    }
                }

                $latitude = $this->exists('latitude')
                    ? (float) $this->input('latitude')
                    : (float) $productionUnit->latitude;
                $longitude = $this->exists('longitude')
                    ? (float) $this->input('longitude')
                    : (float) $productionUnit->longitude;
                $coordinatesChanged = $latitude !== (float) $productionUnit->latitude
                    || $longitude !== (float) $productionUnit->longitude;

                if ($coordinatesChanged && ! app(UruguayBoundary::class)->contains($latitude, $longitude)) {
                    $validator->errors()->add('latitude', 'La ubicación debe encontrarse dentro de Uruguay.');

                    return;
                }

                $localityId = $this->exists('locality_id')
                    ? ($this->input('locality_id') === null ? null : $this->integer('locality_id'))
                    : $productionUnit->locality_id;
                if ($localityId === null) {
                    return;
                }

                $name = $this->has('name') ? $this->string('name')->toString() : $productionUnit->name;
                $query = ProductionUnit::query()
                    ->whereKeyNot($productionUnit->getKey())
                    ->where('locality_id', $localityId)
                    ->where('normalized_name', Str::lower(trim($name)));
                $exists = $query->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'El nombre ya está registrado en esta localidad.');
                }
            },
        ];
    }
}
