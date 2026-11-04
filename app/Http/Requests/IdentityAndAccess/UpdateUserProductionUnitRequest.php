<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Validation\Rule;

final class UpdateUserProductionUnitRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['up_id' => ['required', 'integer', Rule::exists('production_units', 'id')->where('status', 'active')]];
    }
}
