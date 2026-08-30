<?php

namespace App\Modules\Geography\Http\Requests;

use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class StoreLocalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $departamento = $this->route('departamento');

        return $departamento instanceof Department
            && ($this->user()?->can('create', Locality::class) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $departamento = $this->route('departamento');

                if (! $departamento instanceof Department || $validator->errors()->has('nombre')) {
                    return;
                }

                $exists = Locality::query()
                    ->whereBelongsTo($departamento)
                    ->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('nombre', 'El nombre ya está registrado en este departamento.');
                }
            },
        ];
    }
}
