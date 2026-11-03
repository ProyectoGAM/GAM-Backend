<?php

namespace App\Http\Resources\IdentityAndAccess;

use App\Models\IdentityAndAccess\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin AuthSession */
final class AuthSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AuthSession $session */
        $session = $this->resource;

        /** @var Carbon|null $expiresAt */
        $expiresAt = $session->expires_at;

        return [
            'id' => (string) $session->getKey(),
            'kind' => $session->kind,
            'auth_method' => $session->auth_method,
            'up_id' => $session->production_unit_id,
            'expires_at' => $expiresAt?->toIso8601String(),
            'idle_timeout_seconds' => $session->kind === 'shared_user'
                ? (int) config('identity.shared_idle_seconds', 120)
                : null,
        ];
    }
}
