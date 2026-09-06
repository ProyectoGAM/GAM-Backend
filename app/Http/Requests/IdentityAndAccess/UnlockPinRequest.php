<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Foundation\Http\FormRequest;

final class UnlockPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('identity.pins.manage') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
