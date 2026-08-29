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
        $department = $this->route('department');

        return $department instanceof Department
            && ($this->user()?->can('create', Locality::class) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $department = $this->route('department');

                if (! $department instanceof Department || $validator->errors()->has('name')) {
                    return;
                }

                $exists = Locality::query()
                    ->whereBelongsTo($department)
                    ->where('normalized_name', Str::lower(trim($this->string('name')->toString())))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'El nombre ya está registrado en este departamento.');
                }
            },
        ];
    }
}
