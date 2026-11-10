<?php

namespace App\Http\Requests\IdentityAndAccess;

final class ListRolesRequest extends RoleRequest
{
    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'string', 'max:120'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
