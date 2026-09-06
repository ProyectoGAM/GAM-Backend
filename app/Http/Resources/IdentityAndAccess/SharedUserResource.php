<?php

namespace App\Http\Resources\IdentityAndAccess;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class SharedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->getKey(),
            'nombre' => $user->name,
        ];
    }
}
