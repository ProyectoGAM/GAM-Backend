<?php

namespace App\Http\Controllers\IdentityAndAccess;

use App\Actions\IdentityAndAccess\IssueAccessTokenAction;
use App\Actions\IdentityAndAccess\LoginUserAction;
use App\Actions\IdentityAndAccess\LogoutUserAction;
use App\Actions\IdentityAndAccess\RegisterUserAction;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Http\Requests\IdentityAndAccess\ConfirmPasswordRequest;
use App\Http\Requests\IdentityAndAccess\LoginRequest;
use App\Http\Requests\IdentityAndAccess\LogoutRequest;
use App\Http\Requests\IdentityAndAccess\MeRequest;
use App\Http\Requests\IdentityAndAccess\RegisterRequest;
use App\Http\Requests\IdentityAndAccess\WebLoginRequest;
use App\Http\Resources\IdentityAndAccess\AccessTokenResource;
use App\Http\Resources\IdentityAndAccess\AuthResponseResource;
use App\Http\Resources\IdentityAndAccess\AuthSessionResource;
use App\Http\Resources\IdentityAndAccess\UserResource;
use App\Models\IdentityAndAccess\AuthSession;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
        IssueAccessTokenAction $issueToken,
        AuthSessionService $sessions,
    ): JsonResponse {
        $data = PublicInputMapper::toInternal($request->validated(), 'identity');
        $result = $loginUser->execute([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        if (! $result->isSuccessful() || ! $result->user()->can('identity.personal.login')) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Las credenciales proporcionadas no son correctas.',
            ], Response::HTTP_UNAUTHORIZED)->header('Cache-Control', 'no-store');
        }

        $user = $result->user();

        [$token, $session] = DB::transaction(function () use ($user, $data, $issueToken, $sessions): array {
            $token = $issueToken->execute($user, $data['device_name'] ?? 'login');

            return [$token, $sessions->createPersonal($user, $token)];
        });

        return response()->json([
            ...(new AccessTokenResource($token))->resolve($request),
            'user' => (new UserResource($user))->resolve($request),
            'session' => (new AuthSessionResource($session))->resolve($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function webLogin(
        WebLoginRequest $request,
        LoginUserAction $loginUser,
        AuthSessionService $sessions,
    ): JsonResponse {
        $data = PublicInputMapper::toInternal($request->validated(), 'identity');
        $result = $loginUser->execute(['email' => $data['email'], 'password' => $data['password']]);

        if (! $result->isSuccessful() || ! $result->user()->can('identity.web.login')) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Las credenciales proporcionadas no son correctas.',
            ], Response::HTTP_UNAUTHORIZED)->header('Cache-Control', 'no-store');
        }

        $user = $result->user();

        // La duración la controla la sesión del servidor; no emitimos un recaller indefinido.
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $session = $sessions->createWebPersonal($user);
        $request->session()->put('auth_session_id', $session->getKey());

        return response()->json([
            'user' => (new UserResource($user))->resolve($request),
            'session' => (new AuthSessionResource($session))->resolve($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function logout(LogoutRequest $request, LogoutUserAction $logoutUser): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $logoutUser->execute($user);

        app(AuthSessionService::class)->revoke(app(AuthSessionService::class)->current($request));

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'La sesión se cerró correctamente.']);
    }

    public function webLogout(LogoutRequest $request, AuthSessionService $sessions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $sessions->revoke($sessions->current($request));
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'La sesión se cerró correctamente.']);
    }

    public function confirmPassword(ConfirmPasswordRequest $request, AuthSessionService $sessions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            throw new IdentityException(401, 'INVALID_CREDENTIALS', 'Las credenciales proporcionadas no son correctas.');
        }

        $session = $sessions->current($request);

        if ($session === null) {
            throw new IdentityException(401, 'SESSION_REVOKED', 'La sesión no es válida.');
        }

        $session->forceFill(['password_confirmed_at' => now()])->save();

        return response()->json(['message' => 'La contraseña fue confirmada.']);
    }

    public function me(MeRequest $request, AuthSessionService $sessions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = $sessions->current($request);

        return response()->json([
            'data' => (new UserResource($user))->resolve($request),
            'session' => $session === null ? null : (new AuthSessionResource($session))->resolve($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function sessions(Request $request, AuthSessionService $sessions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => AuthSessionResource::collection($sessions->personalFor($user))->resolve($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function revokeSession(
        Request $request,
        AuthSession $session,
        AuthSessionService $sessions,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $session->user_id === $user->getKey() && $session->kind === 'personal',
            Response::HTTP_NOT_FOUND,
        );

        $sessions->revoke($session, 'self_revoked');

        return response()->json(['message' => 'La sesión fue revocada.']);
    }
}
