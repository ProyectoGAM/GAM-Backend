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
            'message' => 'Welcome to the admin area.',
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }
}
