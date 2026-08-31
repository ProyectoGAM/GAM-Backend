<?php

namespace App\Modules\IdentityAndAccess\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * Representa la respuesta plana compartida por registro e inicio de sesión.
 */
final class AuthResponseResource extends JsonResource
{
    public static $wrap = null;

    public static function fromTokenAndUser(NewAccessToken $token, User $user): self
    {
        return new self([
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{token: NewAccessToken, user: User} $resources */
        $resources = $this->resource;

        return [
            ...(new AccessTokenResource($resources['token']))->resolve($request),
            'user' => (new UserResource($resources['user']))->resolve($request),
        ];
    }
}
