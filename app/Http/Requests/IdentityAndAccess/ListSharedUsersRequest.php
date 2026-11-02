<?php

namespace App\Http\Requests\IdentityAndAccess;

use App\Models\SharedDevice;
use Illuminate\Foundation\Http\FormRequest;

final class ListSharedUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('shared_device') instanceof SharedDevice;
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647']];
    }

    public function messages(): array
    {
        return ['per_page.integer' => 'La cantidad por página debe ser un entero.', 'per_page.min' => 'La cantidad por página debe ser positiva.', 'per_page.max' => 'La cantidad por página no puede superar 100.', 'page.integer' => 'La página debe ser un entero.', 'page.min' => 'La página debe ser positiva.', 'page.max' => 'La página supera el límite permitido.'];
    }
}
