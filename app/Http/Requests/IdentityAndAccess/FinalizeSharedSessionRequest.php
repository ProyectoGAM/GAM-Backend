<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Foundation\Http\FormRequest;

final class FinalizeSharedSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['session_id' => ['required', 'uuid']];
    }
}
