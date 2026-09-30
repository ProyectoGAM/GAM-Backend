<?php

namespace App\Http\Controllers\IdentityAndAccess;

use App\Actions\IdentityAndAccess\AuthenticateSharedPinAction;
use App\Actions\IdentityAndAccess\GeneratePairingCodeAction;
use App\Actions\IdentityAndAccess\RedeemPairingCodeAction;
use App\Actions\IdentityAndAccess\RevokeSharedDeviceAction;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Http\Requests\IdentityAndAccess\FinalizeSharedSessionRequest;
use App\Http\Requests\IdentityAndAccess\GeneratePairingCodeRequest;
use App\Http\Requests\IdentityAndAccess\RedeemPairingCodeRequest;
use App\Http\Requests\IdentityAndAccess\RevokeSharedDeviceRequest;
use App\Http\Requests\IdentityAndAccess\SharedPinLoginRequest;
use App\Http\Resources\IdentityAndAccess\AccessTokenResource;
use App\Http\Resources\IdentityAndAccess\AuthSessionResource;
use App\Http\Resources\IdentityAndAccess\SharedUserResource;
use App\Http\Resources\IdentityAndAccess\UserResource;
use App\Models\IdentityAndAccess\AuthSession;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SharedDeviceController
{
    public function generateCode(GeneratePairingCodeRequest $request, GeneratePairingCodeAction $generate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->requireRecentPassword($request);
        $result = $generate->execute($user, $request->string('name')->toString());

        return response()->json($result, Response::HTTP_CREATED)->header('Cache-Control', 'no-store');
    }

    public function redeemCode(RedeemPairingCodeRequest $request, RedeemPairingCodeAction $redeem): JsonResponse
    {
        $data = $request->validated();
        $result = $redeem->execute(
            $data['code'],
            $data['device_name'] ?? 'shared-device',
        );

        return response()->json([
            'device' => [
                'id' => $result['device']->getKey(),
                'name' => $result['device']->name,
                'expires_at' => $result['device']->credential_expires_at?->toIso8601String(),
            ],
            'device_token' => $result['token'],
        ], Response::HTTP_CREATED)->header('Cache-Control', 'no-store');
    }

    public function redeemWebCode(
        RedeemPairingCodeRequest $request,
        RedeemPairingCodeAction $redeem,
    ): JsonResponse {
        $data = $request->validated();
        $result = $redeem->execute($data['code'], $data['device_name'] ?? 'shared-web');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'device' => [
                'id' => $result['device']->getKey(),
                'name' => $result['device']->name,
                'expires_at' => $result['device']->credential_expires_at?->toIso8601String(),
            ],
        ], Response::HTTP_CREATED)
            ->withCookie(cookie(
                'gam_shared_device',
                $result['token'],
                (int) config('identity.shared_device_days', 365) * 24 * 60,
                '/',
                null,
                (bool) config('session.secure'),
                true,
                false,
                (string) config('session.same_site', 'lax'),
            ))
            ->header('Cache-Control', 'no-store');
    }

    public function status(Request $request): JsonResponse
    {
        /** @var SharedDevice $device */
        $device = $request->attributes->get('shared_device');

        return response()->json([
            'data' => [
                'id' => $device->getKey(),
                'name' => $device->name,
                'expires_at' => $device->credential_expires_at?->toIso8601String(),
                'revoked_at' => $device->revoked_at?->toIso8601String(),
                'active_session_id' => $device->sessions()->whereNull('revoked_at')->where('kind', 'shared_user')->latest('issued_at')->value('id'),
            ],
        ]);
    }

    public function managedIndex(): JsonResponse
    {
        return response()->json([
            'data' => SharedDevice::query()->latest('enrolled_at')->get()->map(fn (SharedDevice $device): array => [
                'id' => $device->getKey(),
                'name' => $device->name,
                'expires_at' => $device->credential_expires_at?->toIso8601String(),
                'revoked_at' => $device->revoked_at?->toIso8601String(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function revokeDevice(
        Request $request,
        SharedDevice $sharedDevice,
        RevokeSharedDeviceAction $revoke,
    ): JsonResponse {
        $this->requireRecentPassword($request);
        /** @var User $actor */
        $actor = $request->user();
        $revoke->execute($sharedDevice, $actor);

        return response()->json(['message' => 'El dispositivo compartido fue revocado.']);
    }

    public function revokeLocal(
        RevokeSharedDeviceRequest $request,
        RevokeSharedDeviceAction $revoke,
    ): JsonResponse {
        /** @var SharedDevice $device */
        $device = $request->attributes->get('shared_device');
        $data = $request->validated();
        $actor = User::query()->where('email', $data['email'])->first();

        if (! $actor instanceof User
            || ! Hash::check($data['password'], $actor->password)
            || ! $actor->can('identity.shared-devices.manage')) {
            throw new IdentityException(401, 'INVALID_CREDENTIALS', 'Las credenciales proporcionadas no son correctas.');
        }

        $revoke->execute($device, $actor, 'local_device_revoked');

        $response = response()->json(['message' => 'El dispositivo compartido fue revocado.']);
        if ($request->hasCookie('gam_shared_device')) {
            $response->withCookie(cookie()->forget('gam_shared_device'));
        }

        return $response;
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('pin_enabled', true)
            ->whereNull('deleted_at')
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'admin'))
            ->whereHas('roles', fn ($query) => $query->where('name', 'employee'))
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 50), 100));

        return response()->json([
            'data' => SharedUserResource::collection($users)->resolve($request),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function loginPin(
        SharedPinLoginRequest $request,
        AuthenticateSharedPinAction $authenticate,
    ): JsonResponse {
        /** @var SharedDevice $device */
        $device = $request->attributes->get('shared_device');
        $data = $request->validated();
        $result = $authenticate->execute($device, (int) $data['user_id'], $data['pin']);

        return response()->json([
            ...(new AccessTokenResource($result['token']))->resolve($request),
            'user' => (new UserResource($result['session']->user))->resolve($request),
            'session' => (new AuthSessionResource($result['session']))->resolve($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function webLoginPin(
        SharedPinLoginRequest $request,
        AuthenticateSharedPinAction $authenticate,
    ): JsonResponse {
        /** @var SharedDevice $device */
        $device = $request->attributes->get('shared_device');
        $data = $request->validated();
        $session = $authenticate->executeWeb($device, (int) $data['user_id'], $data['pin']);

        // La duración la controla la sesión del servidor; no emitimos un recaller indefinido.
        Auth::guard('web')->login($session->user, false);
        $request->session()->regenerate();
        $request->session()->put('auth_session_id', $session->getKey());

        return response()->json([
            'user' => (new UserResource($session->user))->resolve($request),
            'session' => (new AuthSessionResource($session))->resolve($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function activity(Request $request): JsonResponse
    {
        /** @var AuthSession $session */
        $session = $request->attributes->get('auth_session');
        $session->forceFill(['last_user_activity_at' => now()])->save();

        return response()->json(['expires_at' => $session->expires_at?->toIso8601String()]);
    }

    public function finalize(FinalizeSharedSessionRequest $request): JsonResponse
    {
        /** @var SharedDevice $device */
        $device = $request->attributes->get('shared_device');
        $sessionId = $request->string('session_id')->toString();

        DB::transaction(function () use ($device, $sessionId): void {
            $lockedDevice = SharedDevice::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $session = AuthSession::query()
                ->whereKey($sessionId)
                ->where('shared_device_id', $lockedDevice->getKey())
                ->where('device_generation', $lockedDevice->session_generation)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($session instanceof AuthSession) {
                $session->forceFill(['revoked_at' => now(), 'revoked_reason' => 'employee_logout'])->save();
                if ($session->personal_access_token_id !== null) {
                    $session->user?->tokens()->whereKey($session->personal_access_token_id)->delete();
                }
            }
        });

        return response()->json(['message' => 'La sesión del empleado terminó.']);
    }

    private function requireRecentPassword(Request $request): void
    {
        $session = app(AuthSessionService::class)->current($request);

        if ($session === null
            || $session->password_confirmed_at === null
            || $session->password_confirmed_at->addMinutes((int) config('identity.password_confirmation_minutes', 10))->isPast()) {
            throw new IdentityException(403, 'REAUTHENTICATION_REQUIRED', 'Confirma tu contraseña para continuar.');
        }
    }
}
