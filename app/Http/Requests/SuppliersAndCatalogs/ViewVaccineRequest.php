<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use Illuminate\Foundation\Http\FormRequest;

final class ViewVaccineRequest extends FormRequest
{
    /**
     * Determina si el usuario puede consultar la vacuna.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('vaccine')) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
