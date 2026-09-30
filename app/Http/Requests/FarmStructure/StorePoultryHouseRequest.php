<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmStructure\PoultryHouseType;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StorePoultryHouseRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizePoultryHouseCollection('create');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['sometimes', Rule::enum(PoultryHouseType::class)],
            'bird_capacity' => [
                'nullable',
                'integer',
                'min:1',
                Rule::requiredIf(fn (): bool => $this->input('type', PoultryHouseType::Poultry->value) === PoultryHouseType::Poultry->value),
                Rule::prohibitedIf(fn (): bool => $this->input('type') === PoultryHouseType::Feed->value),
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $productionUnit = $this->route('productionUnit');

                if (! $productionUnit instanceof ProductionUnit) {
                    return;
                }

                if ($this->input('type') === PoultryHouseType::Feed->value && $this->exists('bird_capacity')) {
                    $validator->errors()->add('bird_capacity', 'Las plantas de ración no pueden tener capacidad de aves.');

                    return;
                }

                if ($validator->errors()->hasAny(['name', 'type', 'bird_capacity'])) {
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.enum' => 'El tipo de galpón no es válido.',
            'bird_capacity.required' => 'La capacidad de aves es obligatoria para los galpones avícolas.',
            'bird_capacity.prohibited' => 'Las plantas de ración no pueden tener capacidad de aves.',
        ];
    }
}
