<?php

namespace App\Http\Requests\IdentityAndAccess;

final class ChangeOwnCredentialRequest extends RoleRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->hasAccessRole()
            && ($this->route('credential') === 'access_pin' ? $user->usesDevicePin() : ! $user->usesDevicePin());
    }

    public function rules(): array
    {
        if ($this->route('credential') === 'password') {
            return ['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'confirmed'], 'password_confirmation' => ['required', 'string']];
        }
        $current = $this->route('credential') === 'access_pin' ? 'current_pin' : 'current_password';

        return [$current => $current === 'current_pin' ? ['required', 'string', 'regex:/\A[0-9]{4}\z/D'] : ['required', 'string'],
            'pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D', 'confirmed'], 'pin_confirmation' => ['required', 'string']];
    }
}
