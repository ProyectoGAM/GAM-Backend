<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Foundation\Http\FormRequest;

final class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('can_view_admin_dashboard') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
