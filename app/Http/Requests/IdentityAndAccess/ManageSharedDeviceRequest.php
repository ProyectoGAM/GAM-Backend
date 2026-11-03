<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Foundation\Http\FormRequest;

final class ManageSharedDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('can_manage_shared_devices') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
