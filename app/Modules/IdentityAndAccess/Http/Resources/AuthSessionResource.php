<?php

namespace App\Modules\IdentityAndAccess\Http\Resources;

use App\Models\IdentityAndAccess\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuthSession */
final class AuthSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AuthSession $session */
        $session = $this->resource;

        return [
            'id' => (string) $session->getKey(),
            'kind' => $session->kind,
            'auth_method' => $session->auth_method,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'idle_timeout_seconds' => $session->kind === 'shared_user'
                ? (int) config('identity.shared_idle_seconds', 120)
                : null,
        ];
    }
}
