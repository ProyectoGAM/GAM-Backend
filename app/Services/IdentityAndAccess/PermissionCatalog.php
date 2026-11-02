<?php

namespace App\Services\IdentityAndAccess;

use Illuminate\Validation\ValidationException;

final class PermissionCatalog
{
    /** @return array<string, array{module: string, label: string, description: string, requires: list<string>, admin_only: bool}> */
    public function permissions(): array
    {
        return config('permission-catalog.permissions', []);
    }

    /** @return list<array<string, mixed>> */
    public function grouped(): array
    {
        $groups = [];
        foreach (config('permission-catalog.modules', []) as $key => $label) {
            $permissions = [];
            foreach ($this->permissions() as $name => $permission) {
                if ($permission['module'] === $key) {
                    $permissions[] = [
                        'nombre' => $name, 'etiqueta' => $permission['label'],
                        'descripcion' => $permission['description'], 'requiere' => $permission['requires'],
                        'asignable' => ! $permission['admin_only'],
                    ];
                }
            }
            $groups[] = ['modulo' => $key, 'etiqueta' => $label, 'permisos' => $permissions];
        }

        return $groups;
    }

    /** @param list<string> $names */
    public function validate(array $names): void
    {
        $catalog = $this->permissions();
        foreach ($names as $name) {
            $permission = $catalog[$name] ?? null;
            if ($permission === null || $permission['admin_only']) {
                throw ValidationException::withMessages(['permisos' => 'La selección contiene permisos inexistentes o exclusivos de Admin.']);
            }
            foreach ($permission['requires'] as $required) {
                if (! in_array($required, $names, true)) {
                    throw ValidationException::withMessages(['permisos' => "El permiso {$permission['label']} requiere activar {$catalog[$required]['label']}."]);
                }
            }
        }
    }
}
