<?php

namespace App\Services\IdentityAndAccess;

use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Models\IdentityAndAccess\AuthSession;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class AccessControl
{
    /** Serializa las mutaciones de acceso antes de bloquear roles o usuarios concretos. */
    public function lockActor(User $actor): User
    {
        Role::query()->where('name', 'admin')->where('guard_name', 'web')->lockForUpdate()->first();

        return User::query()->findOrFail($actor->getKey());
    }

    public function requireAnotherAdmin(User $user): void
    {
        if (! $user->trashed() && $user->hasRole('admin')
            && ! User::query()->whereKeyNot($user->getKey())->role('admin')->exists()) {
            throw new IdentityException(409, 'LAST_ADMIN', 'Debe permanecer al menos un administrador activo.');
        }
    }

    public function requireVersion(Role $role, int $version): void
    {
        if ($role->version !== $version) {
            throw new IdentityException(409, 'ROLE_VERSION_CONFLICT', 'El rol cambió desde que lo consultaste. Recarga sus datos.');
        }
    }

    public function invalidate(): void
    {
        DB::afterCommit(static function (): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /** Revoca los canales que dejaron de estar autorizados y conserva los restantes. */
    public function enforceSessions(User $user): void
    {
        if ($user->hasRole('admin')) {
            $user->forceFill(['pin_hash' => null, 'pin_enabled' => false, 'pin_pepper_version' => null])->save();
        }
        $allowed = [
            'shared' => ! $user->trashed() && ! $user->hasRole('admin') && $user->can('can_login_shared'),
            'cookie' => ! $user->trashed() && $user->can('can_login_web'),
            'bearer' => ! $user->trashed() && $user->can('can_login_native'),
        ];
        $user->authSessions()->whereNull('revoked_at')->orderBy('id')->get()->each(function (AuthSession $session) use ($allowed, $user): void {
            $channel = $session->kind === 'shared_user' ? 'shared' : $session->transport;
            if (! ($allowed[$channel] ?? false)) {
                $session->forceFill(['revoked_at' => now(), 'revoked_reason' => 'permissions_changed'])->save();
                if ($session->personal_access_token_id !== null) {
                    $user->tokens()->whereKey($session->personal_access_token_id)->delete();
                }
            }
        });
        if (! $allowed['bearer'] && ! $allowed['shared']) {
            $user->tokens()->delete();
        }
    }
}
