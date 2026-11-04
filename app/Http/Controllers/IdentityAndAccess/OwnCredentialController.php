<?php

namespace App\Http\Controllers\IdentityAndAccess;

use App\Actions\IdentityAndAccess\ChangeOwnCredentialAction;
use App\Http\Requests\IdentityAndAccess\ChangeOwnCredentialRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class OwnCredentialController
{
    public function update(ChangeOwnCredentialRequest $request, ChangeOwnCredentialAction $action): JsonResponse
    {
        $action->execute($request->user(), (string) $request->route('credential'), $request->validated());
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'La credencial fue actualizada. Debes iniciar sesión nuevamente.', 'requiere_reingreso' => true])->header('Cache-Control', 'no-store');
    }
}
