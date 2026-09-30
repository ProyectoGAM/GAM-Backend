<?php

namespace App\Http\Controllers\IdentityAndAccess;

use App\Actions\IdentityAndAccess\RegisterUserAction;
use App\Actions\IdentityAndAccess\ResetUserPasswordAction;
use App\Actions\IdentityAndAccess\RevokeUserSessionsAction;
use App\Actions\IdentityAndAccess\SetUserPinAction;
use App\Http\Requests\IdentityAndAccess\CreateManagedUserRequest;
use App\Http\Requests\IdentityAndAccess\DeletePinRequest;
use App\Http\Requests\IdentityAndAccess\SetPinRequest;
use App\Http\Requests\IdentityAndAccess\UnlockPinRequest;
use App\Http\Requests\IdentityAndAccess\UpdateUserPasswordRequest;
use App\Http\Requests\IdentityAndAccess\UpdateUserRolesRequest;
use App\Http\Requests\IdentityAndAccess\UpdateUserStatusRequest;
use App\Http\Resources\IdentityAndAccess\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Permission\Models\Role;

final class UserManagementController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => UserResource::collection(User::query()->withTrashed()->paginate(50))]);
    }

    public function store(CreateManagedUserRequest $request, RegisterUserAction $register): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();
        $user = $register->execute($data, $actor);
        $user->assignRole(Role::findOrCreate($data['role'], 'web'));

        return response()->json(['data' => new UserResource($user->refresh())], Response::HTTP_CREATED);
    }

    public function status(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        if ($request->boolean('enabled')) {
            $user->restore();
        } else {
            $user->authSessions()->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => 'user_disabled']);
            $user->tokens()->delete();
            $user->delete();
        }

        return response()->json(['data' => new UserResource($user->refresh())]);
    }

    public function roles(UpdateUserRolesRequest $request, User $user): JsonResponse
    {
        $user->syncRoles($request->validated('roles'));

        if ($user->hasRole('admin')) {
            $user->authSessions()->where('kind', 'shared_user')->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'revoked_reason' => 'role_changed',
            ]);
            $user->forceFill([
                'pin_hash' => null,
                'pin_enabled' => false,
                'pin_pepper_version' => null,
            ])->save();
        }

        return response()->json(['data' => new UserResource($user->refresh())]);
    }

    public function setPin(SetPinRequest $request, User $user, SetUserPinAction $setPin): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $setPin->execute($actor, $user, $request->string('pin')->toString());

        return response()->json(['data' => new UserResource($user->refresh())]);
    }

    public function deletePin(DeletePinRequest $request, User $user, SetUserPinAction $setPin): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $setPin->disable($actor, $user);

        return response()->json(['message' => 'El PIN fue deshabilitado.']);
    }

    public function unlockPin(UnlockPinRequest $request, User $user, SetUserPinAction $setPin): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $setPin->unlock($actor, $user);

        return response()->json(['message' => 'El PIN fue desbloqueado.']);
    }

    public function password(
        UpdateUserPasswordRequest $request,
        User $user,
        ResetUserPasswordAction $resetPassword,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $resetPassword->execute($actor, $user, $request->string('password')->toString());

        return response()->json(['message' => 'La contraseña fue restablecida.']);
    }

    public function sessions(
        User $user,
        RevokeUserSessionsAction $revokeSessions,
        Request $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $revokeSessions->execute($actor, $user);

        return response()->json(['message' => 'Las sesiones fueron revocadas.']);
    }
}
