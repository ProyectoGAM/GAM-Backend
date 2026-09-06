<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Foundation\Http\FormRequest;

final class GeneratePairingCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('identity.shared-devices.manage') ?? false;
    }

    public function rules(): array
    {
        return ['nombre' => ['required', 'string', 'max:100']];
    }
}
