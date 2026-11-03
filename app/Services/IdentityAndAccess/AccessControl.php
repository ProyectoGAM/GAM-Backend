<?php

namespace App\Services\IdentityAndAccess;

use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Models\IdentityAndAccess\AuthSession;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
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
        $allowed = [
            'shared' => ! $user->trashed() && $user->usesDevicePin() && $user->pin_enabled && ! $user->hasRole('admin') && $user->can('can_login_shared'),
            'cookie' => ! $user->trashed() && ! $user->usesDevicePin() && $user->can('can_login_web'),
            'bearer' => ! $user->trashed() && ! $user->usesDevicePin() && $user->can('can_login_native'),
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

    public function revokeAll(User $user, string $reason): void
    {
        $user->authSessions()->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => null])->save();
    }

    /** Revalida el contexto después del lock del actor, evitando ejecutar con credenciales recién revocadas. */
    public function assertCurrentSession(User $actor): void
    {
        if (auth()->id() !== $actor->id) {
            return;
        }
        $session = app(AuthSessionService::class)->current(request());
        $token = request()->user()?->currentAccessToken();
        $tokenId = $token instanceof PersonalAccessToken ? $token->getKey() : null;
        if ($session === null && (is_int($tokenId) || is_string($tokenId))) {
            throw new IdentityException(401, 'SESSION_REVOKED', 'La sesión ya no está vigente.');
        }
        if ($session !== null && (! $session->isUsable() || $session->user_id !== $actor->id
            || ($actor->usesDevicePin() && $session->production_unit_id !== $actor->production_unit_id))) {
            throw new IdentityException(401, 'SESSION_REVOKED', 'La sesión ya no está vigente.');
        }
        if ($actor->usesDevicePin()) {
            $device = $session?->sharedDevice;
            if ($session === null || ! $actor->pin_enabled || $device === null || ! $device->isUsable()
                || $session->device_generation !== $device->session_generation) {
                throw new IdentityException(401, 'SESSION_EXPIRED', 'La sesión del empleado expiró.');
            }
        }
    }
}
