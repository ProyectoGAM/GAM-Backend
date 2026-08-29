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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'bird_capacity' => ['sometimes', 'required', 'integer', 'min:1'],
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

                if (! $this->hasAny(['name', 'bird_capacity'])) {
                    $validator->errors()->add('request', 'Debes proporcionar al menos un campo.');

                    return;
                }

                $name = $this->has('name') ? $this->string('name')->toString() : $poultryHouse->name;
                $exists = PoultryHouse::query()
                    ->whereKeyNot($poultryHouse->getKey())
                    ->where('production_unit_id', $poultryHouse->production_unit_id)
                    ->where('normalized_name', Str::lower(trim($name)))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'El nombre ya está registrado en esta unidad productiva.');
                }
            },
        ];
    }
}
