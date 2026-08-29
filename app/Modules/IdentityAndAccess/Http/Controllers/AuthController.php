<?php

namespace App\Modules\IdentityAndAccess\Http\Controllers;

use App\Models\User;
use App\Modules\IdentityAndAccess\Application\Actions\IssueAccessTokenAction;
use App\Modules\IdentityAndAccess\Application\Actions\LoginUserAction;
use App\Modules\IdentityAndAccess\Application\Actions\LogoutUserAction;
use App\Modules\IdentityAndAccess\Application\Actions\RegisterUserAction;
use App\Modules\IdentityAndAccess\Http\Requests\LoginRequest;
use App\Modules\IdentityAndAccess\Http\Requests\LogoutRequest;
use App\Modules\IdentityAndAccess\Http\Requests\MeRequest;
use App\Modules\IdentityAndAccess\Http\Requests\RegisterRequest;
use App\Modules\IdentityAndAccess\Http\Resources\AccessTokenResource;
use App\Modules\IdentityAndAccess\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class AuthController
{
    public function register(
        RegisterRequest $request,
        RegisterUserAction $registerUser,
        IssueAccessTokenAction $issueToken,
    ): JsonResponse {
        $data = $request->validated();
        $user = $registerUser->execute($data);
        $token = $issueToken->execute($user, $data['device_name'] ?? 'registration');

        return response()->json([
            ...(new AccessTokenResource($token))->resolve($request),
            'user' => (new UserResource($user))->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function login(
        LoginRequest $request,
        LoginUserAction $loginUser,
        IssueAccessTokenAction $issueToken,
    ): JsonResponse {
        $data = $request->validated();
        $user = $loginUser->execute([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Las credenciales proporcionadas no son correctas.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            ...(new AccessTokenResource($issueToken->execute($user, $data['device_name'] ?? 'login')))->resolve($request),
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    public function logout(LogoutRequest $request, LogoutUserAction $logoutUser): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $logoutUser->execute($user);

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'La sesión se cerró correctamente.']);
    }

    public function me(MeRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }
}
