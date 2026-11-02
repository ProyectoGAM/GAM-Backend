<?php

namespace App\Http\Resources\IdentityAndAccess;

use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        /** @var Role|null $role */
        $role = $user->roles()->first();

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'deleted_at' => $user->deleted_at?->toIso8601String(),
            'rol' => $role === null ? null : ['id' => $role->id, 'clave' => $role->name, 'nombre' => $role->label ?? $role->name],
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
