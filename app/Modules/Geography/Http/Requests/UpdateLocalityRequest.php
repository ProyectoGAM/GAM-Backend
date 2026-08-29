<?php

namespace App\Modules\Geography\Http\Requests;

use App\Models\Geography\Locality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateLocalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $locality = $this->route('locality');

        return $locality instanceof Locality
            && ($this->user()?->can('update', $locality) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $locality = $this->route('locality');

                if (! $locality instanceof Locality || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->hasAny(['department_id', 'name'])) {
                    $validator->errors()->add('request', 'Debes proporcionar al menos un campo.');

                    return;
                }

                $departmentId = $this->integer('department_id', $locality->department_id);
                $name = $this->has('name') ? $this->string('name')->toString() : $locality->name;
                $exists = Locality::query()
                    ->whereKeyNot($locality->getKey())
                    ->where('department_id', $departmentId)
                    ->where('normalized_name', Str::lower(trim($name)))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'El nombre ya está registrado en este departamento.');
                }
            },
        ];
    }
}
