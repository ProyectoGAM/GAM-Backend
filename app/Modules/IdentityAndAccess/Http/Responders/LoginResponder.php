<?php

namespace App\Modules\IdentityAndAccess\Http\Responders;

use App\Modules\IdentityAndAccess\Application\Actions\IssueAccessTokenAction;
use App\Modules\IdentityAndAccess\Application\Data\LoginResult;
use App\Modules\IdentityAndAccess\Http\Resources\AuthResponseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final readonly class LoginResponder
{
    public function __construct(private IssueAccessTokenAction $issueToken) {}

    public function respond(LoginResult $result, string $deviceName): JsonResponse
    {
        if (! $result->isSuccessful()) {
            return response()->json([
                'message' => 'Las credenciales proporcionadas no son correctas.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = $result->user();
        $token = $this->issueToken->execute($user, $deviceName);

        return AuthResponseResource::fromTokenAndUser($token, $user)->response();
    }
}
