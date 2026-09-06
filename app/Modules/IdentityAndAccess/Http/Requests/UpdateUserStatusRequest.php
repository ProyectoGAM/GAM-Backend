<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('identity.users.manage') ?? false;
    }

    public function rules(): array
    {
        return ['habilitado' => ['required', 'boolean']];
    }
}
