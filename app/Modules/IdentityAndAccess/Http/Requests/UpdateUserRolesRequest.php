<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('identity.users.manage') ?? false;
    }

    public function rules(): array
    {
        return ['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', Rule::in(['admin', 'delivery', 'employee'])]];
    }
}
