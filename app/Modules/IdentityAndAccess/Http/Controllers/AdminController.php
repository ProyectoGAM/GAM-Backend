<?php

namespace App\Modules\IdentityAndAccess\Http\Controllers;

use App\Models\User;
use App\Modules\IdentityAndAccess\Http\Requests\AdminRequest;
use App\Modules\IdentityAndAccess\Http\Resources\UserResource;
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
