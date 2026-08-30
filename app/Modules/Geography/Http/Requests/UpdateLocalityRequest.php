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
        $localidad = $this->route('localidad');

        return $localidad instanceof Locality
            && ($this->user()?->can('update', $localidad) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'departamento_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'],
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $localidad = $this->route('localidad');

                if (! $localidad instanceof Locality || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->hasAny(['departamento_id', 'nombre'])) {
                    $validator->errors()->add('request', 'Debes proporcionar al menos un campo.');

                    return;
                }

                $departmentId = $this->integer('departamento_id', $localidad->department_id);
                $nombre = $this->has('nombre') ? $this->string('nombre')->toString() : $localidad->name;
                $exists = Locality::query()
                    ->whereKeyNot($localidad->getKey())
                    ->where('department_id', $departmentId)
                    ->where('normalized_name', Str::lower(trim($nombre)))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('nombre', 'El nombre ya está registrado en este departamento.');
                }
            },
        ];
    }
}
