<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Validation\Rule;

final class GeneratePairingCodeRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') && $this->user()->can('can_manage_shared_devices');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'up_id' => ['required', 'integer', Rule::exists('production_units', 'id')->where('status', 'active')]];
    }
}
