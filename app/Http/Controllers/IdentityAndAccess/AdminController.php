<?php

namespace App\Http\Controllers\IdentityAndAccess;

use App\Http\Requests\IdentityAndAccess\AdminRequest;
use App\Http\Resources\IdentityAndAccess\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class AdminController
{
    public function __invoke(AdminRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'message' => 'Bienvenido al área de administración.',
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }
}
