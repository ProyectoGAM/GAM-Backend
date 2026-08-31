<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\User;
use App\Modules\IdentityAndAccess\Application\Data\LoginResult;
use Illuminate\Support\Facades\Hash;

final class LoginUserAction
{
    /**
     * @param  array{email: string, password: string}  $credentials
     */
    public function execute(array $credentials): LoginResult
    {
        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if (
            $user === null ||
            ! Hash::check($credentials['password'], $user->password)
        ) {
            return LoginResult::invalidCredentials();
        }

        return LoginResult::success($user);
    }
}
