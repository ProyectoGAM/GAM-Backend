<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use App\Services\IdentityAndAccess\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CreateRoleAction
{
    public function __construct(private AccessControl $access, private PermissionCatalog $catalog, private AuditRecorder $audit) {}

    /** @param array{nombre: string, descripcion?: ?string, permisos: list<string>} $data */
    public function execute(User $actor, array $data, ?string $demoKey = null): Role
    {
        return DB::transaction(function () use ($actor, $data, $demoKey): Role {
            $actor = $this->access->lockActor($actor);
            Gate::forUser($actor)->authorize('create', Role::class);
            $name = $demoKey === null ? 'custom_'.Str::lower((string) Str::ulid()) : 'demo_'.$demoKey;
            if ($demoKey !== null) {
                $existing = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
                if ($existing !== null) {
                    return $existing->load('permissions');
                }
            }

            $this->catalog->validate($data['permisos']);
            $role = Role::query()->create([
                'name' => $name, 'guard_name' => 'web',
                'label' => $data['nombre'], 'description' => $data['descripcion'] ?? null,
            ]);
            $role->syncPermissions($data['permisos']);
            $this->audit->record(AuditEntryData::forSubject(
                subject: $role, actor: $actor, logName: 'identity', event: 'role_created',
                description: 'Rol creado', properties: ['result' => 'success', 'subject_snapshot' => [
                    'name' => $role->name, 'label' => $role->label, 'description' => $role->description,
                    'permissions' => $data['permisos'],
                ]], attributeChanges: ['old' => ['permissions' => []], 'new' => ['permissions' => $data['permisos']]], source: $demoKey === null ? 'api' : 'seeder',
            ));
            $this->access->invalidate();

            return $role->refresh()->load('permissions');
        });
    }
}
