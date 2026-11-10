<?php

namespace App\Http\Requests\IdentityAndAccess;

use App\Models\User;

final class ListManagedUsersRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:2147483647']];
    }
}
