<?php

namespace App\Modules\IdentityAndAccess\Http\Controllers;

use App\Models\User;
use App\Modules\IdentityAndAccess\Application\Actions\RegisterUserAction;
use App\Modules\IdentityAndAccess\Application\Actions\ResetUserPasswordAction;
use App\Modules\IdentityAndAccess\Application\Actions\RevokeUserSessionsAction;
use App\Modules\IdentityAndAccess\Application\Actions\SetUserPinAction;
use App\Modules\IdentityAndAccess\Http\Requests\CreateManagedUserRequest;
use App\Modules\IdentityAndAccess\Http\Requests\DeletePinRequest;
use App\Modules\IdentityAndAccess\Http\Requests\SetPinRequest;
use App\Modules\IdentityAndAccess\Http\Requests\UnlockPinRequest;
use App\Modules\IdentityAndAccess\Http\Requests\UpdateUserPasswordRequest;
use App\Modules\IdentityAndAccess\Http\Requests\UpdateUserRolesRequest;
use App\Modules\IdentityAndAccess\Http\Requests\UpdateUserStatusRequest;
use App\Modules\IdentityAndAccess\Http\Resources\UserResource;
use App\Support\PublicInputMapper;
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
        $data = PublicInputMapper::toInternal($request->validated(), 'identity');
        $user = $register->execute($data, $actor);
        $user->assignRole(Role::findOrCreate($data['rol'], 'web'));

        return response()->json(['data' => new UserResource($user->refresh())], Response::HTTP_CREATED);
    }

    public function status(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        if ($request->boolean('habilitado')) {
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
