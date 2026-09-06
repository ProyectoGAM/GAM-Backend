<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DeletePinRequest extends FormRequest
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
