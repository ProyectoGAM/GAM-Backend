<?php

namespace App\Modules\IdentityAndAccess\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/** @mixin NewAccessToken */
final class AccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var NewAccessToken $token */
        $token = $this->resource;
        $expiresAt = $token->accessToken->getAttribute('expires_at');
        $abilities = $token->accessToken->getAttribute('abilities');

        return [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt instanceof CarbonInterface ? $expiresAt->toIso8601String() : null,
            'abilities' => is_array($abilities) ? $abilities : [],
        ];
    }
}
