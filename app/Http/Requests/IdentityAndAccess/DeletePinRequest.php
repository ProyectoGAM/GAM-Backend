<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Foundation\Http\FormRequest;

final class DeletePinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('managePin', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
