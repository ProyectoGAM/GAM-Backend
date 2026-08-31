<?php

namespace App\Modules\Geography\Http\Requests;

use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ListLocalitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $departamento = $this->route('departamento');
        $actor = $this->user();

        return $departamento instanceof Department
            && $actor instanceof User
            && $actor->can('view', $departamento)
            && $actor->can('viewAny', Locality::class);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'por_pagina' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
