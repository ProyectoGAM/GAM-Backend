<?php

namespace App\Modules\Lots\Application\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class LotsAuthorization
{
    public static function allows(User $actor, string $permission): bool
    {
        return ! $actor->trashed() && ($actor->hasRole('admin') || $actor->checkPermissionTo($permission));
    }

    public static function ensure(User $actor, string $permission): void
    {
        if (! self::allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }
}
