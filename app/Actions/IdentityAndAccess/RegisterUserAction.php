<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RegisterUserAction
{
    public function __construct(private AuditRecorder $auditRecorder, private AccessControl $access) {}

    /** @param array{name: string, email: string, password: string, rol_id: int} $data */
    public function execute(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $actor = $this->access->lockActor($actor);
            Gate::forUser($actor)->authorize('create', User::class);
            $role = Role::query()->where('guard_name', 'web')->whereKey($data['rol_id'])->lockForUpdate()->firstOrFail();
            $user = User::query()->create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
            ]);
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
