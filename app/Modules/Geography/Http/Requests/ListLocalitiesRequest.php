<?php

namespace App\Modules\Geography\Http\Requests;

use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ListLocalitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = $this->route('department');
        $actor = $this->user();

        return $department instanceof Department
            && $actor instanceof User
            && $actor->can('view', $department)
            && $actor->can('viewAny', Locality::class);
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
