<?php

namespace App\Http\Requests\ManagementPlans;

use Illuminate\Foundation\Http\FormRequest;

final class ListFeedSuppliesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('can_view_management_plans') ?? false) || ($this->user()?->can('can_execute_management_plans') ?? false);
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'search' => ['sometimes', 'string', 'max:160']];
    }
}
