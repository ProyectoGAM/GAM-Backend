<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
