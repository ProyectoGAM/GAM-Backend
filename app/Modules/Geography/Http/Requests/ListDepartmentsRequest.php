<?php

namespace App\Modules\Geography\Http\Requests;

use App\Models\Geography\Department;
use Illuminate\Foundation\Http\FormRequest;

final class ListDepartmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Department::class) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
