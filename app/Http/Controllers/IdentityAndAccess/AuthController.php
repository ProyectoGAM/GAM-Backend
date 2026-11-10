<?php

namespace App\Http\Controllers\IdentityAndAccess;

use App\Actions\IdentityAndAccess\IssueAccessTokenAction;
use App\Actions\IdentityAndAccess\LoginUserAction;
use App\Actions\IdentityAndAccess\LogoutUserAction;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Http\Requests\IdentityAndAccess\ConfirmPasswordRequest;
use App\Http\Requests\IdentityAndAccess\LoginRequest;
use App\Http\Requests\IdentityAndAccess\LogoutRequest;
use App\Http\Requests\IdentityAndAccess\MeRequest;
use App\Http\Requests\IdentityAndAccess\WebLoginRequest;
use App\Http\Resources\IdentityAndAccess\AccessTokenResource;
use App\Http\Resources\IdentityAndAccess\AuthSessionResource;
use App\Http\Resources\IdentityAndAccess\UserResource;
use App\Models\IdentityAndAccess\AuthSession;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AuthController
{
    public function login(
        LoginRequest $request,
        LoginUserAction $loginUser,
        IssueAccessTokenAction $issueToken,
        AuthSessionService $sessions,
    ): JsonResponse {
        $data = $request->validated();
        $result = $loginUser->execute([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        if (! $result->isSuccessful() || ! $result->user()->can('can_login_native')) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Las credenciales proporcionadas no son correctas.',
            ], Response::HTTP_UNAUTHORIZED)->header('Cache-Control', 'no-store');
        }

        $user = $result->user();

        [$token, $session] = DB::transaction(function () use ($user, $data, $issueToken, $sessions): array {
            $user = $this->lockLoginUser($user, $data['password'], 'can_login_native');
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
        $data = $request->validated();
        $result = $loginUser->execute(['email' => $data['email'], 'password' => $data['password']]);

        if (! $result->isSuccessful() || ! $result->user()->can('can_login_web')) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Las credenciales proporcionadas no son correctas.',
            ], Response::HTTP_UNAUTHORIZED)->header('Cache-Control', 'no-store');
        }

        $user = $result->user();
        $session = DB::transaction(function () use ($user, $data, $sessions): AuthSession {
            return $sessions->createWebPersonal($this->lockLoginUser($user, $data['password'], 'can_login_web'));
        });

        // La duración la controla la sesión del servidor; no emitimos un recaller indefinido.
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
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

        if ($user->usesDevicePin() || ! is_string($user->password) || ! Hash::check($request->string('password')->toString(), $user->password)) {
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

    public function sessions(MeRequest $request, AuthSessionService $sessions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => AuthSessionResource::collection($sessions->personalFor($user))->resolve($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function revokeSession(
        MeRequest $request,
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

    private function lockLoginUser(User $user, string $password, string $permission): User
    {
        Role::query()->where('name', 'admin')->where('guard_name', 'web')->lockForUpdate()->first();
        $user = User::query()->whereKey($user->id)->lockForUpdate()->first();
        if ($user === null || $user->usesDevicePin() || ! $user->hasAccessRole() || ! $user->can($permission)
            || ! is_string($user->password) || ! Hash::check($password, $user->password)) {
            throw new IdentityException(401, 'INVALID_CREDENTIALS', 'Las credenciales proporcionadas no son correctas.');
        }

        return $user;
    }
}
