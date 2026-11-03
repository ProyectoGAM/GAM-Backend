<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Deliveries\Delivery;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;

final class UpdateUserProductionUnitAction
{
    public function __construct(private AccessControl $access, private AuditRecorder $audit) {}

    public function execute(User $actor, User $user, int $unitId): User
    {
        return DB::transaction(function () use ($actor, $user, $unitId): User {
            $actor = $this->access->lockActor($actor);
            abort_unless($actor->hasRole('admin'), 403);
            ProductionUnit::query()->whereKey($unitId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $user->usesDevicePin()) {
                throw new IdentityException(422, 'ACCESS_METHOD_CONFLICT', 'Sólo las cuentas con acceso PIN tienen una UP de acceso.');
            }
            if ($user->production_unit_id === $unitId) {
                return $user;
            }
            if (Delivery::query()->where('driver_id', $user->id)->where('status', 'active')->exists()) {
                throw new IdentityException(409, 'OPEN_USER_OPERATIONS', 'Debes cerrar el reparto activo antes de cambiar la UP.');
            }
            $before = $user->production_unit_id;
            $user->forceFill(['production_unit_id' => $unitId])->save();
            $this->access->revokeAll($user, 'production_unit_changed');
            $this->audit->record(AuditEntryData::forSubject(
                subject: $user, actor: $actor, logName: 'identity', event: 'user_production_unit_changed',
                description: 'UP de acceso del usuario actualizada', upId: $unitId,
                attributeChanges: ['old' => ['production_unit_id' => $before], 'new' => ['production_unit_id' => $unitId]], source: 'api',
            ));

            return $user;
        });
    }
}
