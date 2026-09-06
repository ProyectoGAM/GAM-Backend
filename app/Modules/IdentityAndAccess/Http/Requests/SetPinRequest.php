<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('identity.pins.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D'],
            'pin_confirmation' => ['required', 'same:pin'],
        ];
    }
}
