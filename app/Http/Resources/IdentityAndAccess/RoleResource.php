<?php

namespace App\Http\Resources\IdentityAndAccess;

use App\Models\IdentityAndAccess\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
final class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'clave' => $this->name, 'nombre' => $this->label ?? $this->name,
            'descripcion' => $this->description, 'version' => $this->version,
            'protegido' => $this->isProtected(), 'editable' => $this->name !== 'admin',
            'permisos' => $this->permissions->pluck('name')->sort()->values()->all(),
            'usuarios_count' => $this->whenCounted('users'),
        ];
    }
}
