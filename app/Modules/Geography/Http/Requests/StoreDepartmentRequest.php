<?php

namespace App\Modules\Geography\Http\Requests;

use App\Models\Geography\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Department::class) ?? false;
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
                if ($validator->errors()->has('nombre')) {
                    return;
                }

                $exists = Department::query()
                    ->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('nombre', 'El nombre ya está registrado.');
                }
            },
        ];
    }
}
