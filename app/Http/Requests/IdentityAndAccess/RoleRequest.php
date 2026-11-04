<?php

namespace App\Http\Requests\IdentityAndAccess;

use App\Models\IdentityAndAccess\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class RoleRequest extends FormRequest
{
    protected string $ability = 'viewAny';

    public function authorize(): bool
    {
        return $this->user()?->can($this->ability, $this->route('role') ?? Role::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $this->rules())) {
                    $validator->errors()->add($key, 'El campo no está permitido en esta operación.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Debes indicar el nombre del rol.',
            'nombre.string' => 'El nombre del rol debe ser un texto.',
            'nombre.max' => 'El nombre del rol no puede superar los 120 caracteres.',
            'descripcion.string' => 'La descripción debe ser un texto.',
            'descripcion.max' => 'La descripción no puede superar los 500 caracteres.',
            'permisos.present' => 'Debes indicar la selección de permisos, aunque esté vacía.',
            'permisos.array' => 'Los permisos deben ser una lista.',
            'permisos.list' => 'Los permisos deben ser una lista consecutiva.',
            'permisos.max' => 'La selección supera el tamaño del catálogo de permisos.',
            'permisos.*.string' => 'Cada permiso debe ser un identificador de texto.',
            'permisos.*.distinct' => 'Cada permiso debe aparecer una sola vez.',
            'permisos.*.in' => 'El permiso no existe en el catálogo.',
            'version.required' => 'Debes indicar la versión del rol consultada.',
            'version.integer' => 'La versión debe ser un número entero.',
            'version.min' => 'La versión debe ser positiva.',
            'version.max' => 'La versión supera el límite permitido.',
            'buscar.string' => 'La búsqueda debe ser un texto.',
            'buscar.max' => 'La búsqueda no puede superar los 120 caracteres.',
            'por_pagina.integer' => 'La cantidad por página debe ser un entero.',
            'por_pagina.min' => 'La cantidad por página debe ser positiva.',
            'por_pagina.max' => 'La cantidad por página no puede superar 100.',
            'page.integer' => 'La página debe ser un entero.',
            'page.min' => 'La página debe ser positiva.',
            'page.max' => 'La página supera el límite permitido.',
        ];
    }
}
