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
            'locality_id' => ['required', 'integer', 'exists:localities,id'],
            'name' => ['required', 'string', 'max:120'],
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
                if ($validator->errors()->hasAny(['locality_id', 'name'])) {
                    return;
                }

                $exists = ProductionUnit::query()
                    ->where('locality_id', $this->integer('locality_id'))
                    ->where('normalized_name', Str::lower(trim($this->string('name')->toString())))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'El nombre ya está registrado en esta localidad.');
                }
            },
        ];
    }
}
