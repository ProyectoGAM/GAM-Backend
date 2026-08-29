<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class LoginUserAction
{
    /**
     * @param  array{email: string, password: string}  $credentials
     */
    public function execute(array $credentials): ?User
    {
        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if (! $user instanceof User || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        return $user;
    }
}
