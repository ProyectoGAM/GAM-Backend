<?php

namespace App\Http\Controllers\IdentityAndAccess;

use App\Actions\IdentityAndAccess\RegisterUserAction;
use App\Actions\IdentityAndAccess\ResetUserPasswordAction;
use App\Actions\IdentityAndAccess\RevokeUserSessionsAction;
use App\Actions\IdentityAndAccess\SetUserPinAction;
use App\Actions\IdentityAndAccess\UpdateUserProductionUnitAction;
use App\Actions\IdentityAndAccess\UpdateUserRoleAction;
use App\Actions\IdentityAndAccess\UpdateUserStatusAction;
use App\Http\Requests\IdentityAndAccess\CreateManagedUserRequest;
use App\Http\Requests\IdentityAndAccess\DeletePinRequest;
use App\Http\Requests\IdentityAndAccess\ListManagedUsersRequest;
use App\Http\Requests\IdentityAndAccess\RevokeUserSessionsRequest;
use App\Http\Requests\IdentityAndAccess\SetPinRequest;
use App\Http\Requests\IdentityAndAccess\UnlockPinRequest;
use App\Http\Requests\IdentityAndAccess\UpdateUserPasswordRequest;
use App\Http\Requests\IdentityAndAccess\UpdateUserProductionUnitRequest;
use App\Http\Requests\IdentityAndAccess\UpdateUserRoleRequest;
use App\Http\Requests\IdentityAndAccess\UpdateUserStatusRequest;
use App\Http\Resources\IdentityAndAccess\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class UserManagementController
{
    public function productionUnit(UpdateUserProductionUnitRequest $request, User $user, UpdateUserProductionUnitAction $action): JsonResponse
    {
        return response()->json(['data' => new UserResource($action->execute($request->user(), $user, $request->integer('up_id')))]);
    }

    public function index(ListManagedUsersRequest $request): JsonResponse
    {
        return response()->json(['data' => UserResource::collection(User::query()->withTrashed()->paginate(50))]);
    }

    public function store(CreateManagedUserRequest $request, RegisterUserAction $register): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();
        $user = $register->execute($data, $actor);

        return response()->json(['data' => new UserResource($user->refresh())], Response::HTTP_CREATED);
    }

    public function status(UpdateUserStatusRequest $request, User $user, UpdateUserStatusAction $action): JsonResponse
    {
        $user = $action->execute($request->user(), $user, $request->boolean('enabled'));

        return response()->json(['data' => new UserResource($user)]);
    }

    public function roles(UpdateUserRoleRequest $request, User $user, UpdateUserRoleAction $action): JsonResponse
    {
        $user = $action->execute($request->user(), $user, $request->integer('rol_id'));

        return response()->json(['data' => new UserResource($user)]);
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
        RevokeUserSessionsRequest $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $revokeSessions->execute($actor, $user);

        return response()->json(['message' => 'Las sesiones fueron revocadas.']);
    }
}
