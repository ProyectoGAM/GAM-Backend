<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\IdentityAndAccess\AccessMethod;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use App\Services\IdentityAndAccess\PinHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RegisterUserAction
{
    public function __construct(private AuditRecorder $auditRecorder, private AccessControl $access) {}

    /** @param array{name: string, email?: ?string, password?: string, pin?: string, up_id?: int, rol_id: int} $data */
    public function execute(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $actor = $this->access->lockActor($actor);
            Gate::forUser($actor)->authorize('create', User::class);
            $role = Role::query()->where('guard_name', 'web')->whereKey($data['rol_id'])->lockForUpdate()->firstOrFail();
            $personal = ! empty($data['email']);
            if (! $personal && $role->name === 'admin') {
                throw new IdentityException(422, 'ACCESS_METHOD_CONFLICT', 'Admin requiere correo y contraseña.');
            }
            if (! $personal) {
                ProductionUnit::query()->whereKey($data['up_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            }
            $user = new User;
            $user->forceFill([
                'name' => $data['name'], 'email' => $personal ? $data['email'] : null, 'password' => $personal ? $data['password'] : null,
                'access_method' => $personal ? AccessMethod::EmailPassword : AccessMethod::DevicePin,
                'production_unit_id' => $personal ? null : $data['up_id'],
                'pin_hash' => $personal ? null : app(PinHasher::class)->hash($data['pin']),
                'pin_enabled' => ! $personal,
                'pin_changed_at' => $personal ? null : now(),
            ])->save();
            $user->syncRoles([$role]);
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user, actor: $actor, logName: 'identity', event: 'user_registered', description: 'Usuario registrado',
                properties: ['result' => 'success', 'subject_snapshot' => ['name' => $user->name, 'email' => $user->email, 'role' => $role->name]],
                attributeChanges: ['old' => ['role' => null], 'new' => ['role' => $role->name]], source: 'api',
            ));

            return $user;
        });
    }
}
