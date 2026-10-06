<?php

namespace App\Http\Requests\FarmStructure;

use App\Models\FarmStructure\ProductionUnit;
use App\Services\FarmStructure\UruguayBoundary;
use Illuminate\Validation\Validator;

final class ValidateProductionUnitLocationRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProductionUnit::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
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
            }
        }];
    }
}
