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
use App\Modules\IdentityAndAccess\Http\Resources\AuthResponseResource;
use App\Modules\IdentityAndAccess\Http\Resources\UserResource;
use App\Modules\IdentityAndAccess\Http\Responders\LoginResponder;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class AuthController
{
    public function register(
        RegisterRequest $request,
        RegisterUserAction $registerUser,
        IssueAccessTokenAction $issueToken,
    ): JsonResponse {
        $data = PublicInputMapper::toInternal($request->validated(), 'identity');
        $user = $registerUser->execute($data);
        $token = $issueToken->execute($user, $data['device_name'] ?? 'registration');

        return AuthResponseResource::fromTokenAndUser($token, $user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(
        LoginRequest $request,
        LoginUserAction $loginUser,
        LoginResponder $responder,
    ): JsonResponse {
        $data = PublicInputMapper::toInternal($request->validated(), 'identity');
        $user = $loginUser->execute([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return $responder->respond($result, $data['device_name'] ?? 'login');
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
