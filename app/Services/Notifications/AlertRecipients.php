<?php

namespace App\Services\Notifications;

use App\Enums\IdentityAndAccess\AccessMethod;
use App\Models\User;

final class AlertRecipients
{
    /** @return array<int, true> */
    public function ids(?int $unitId, string $permission): array
    {
        $ids = User::query()->whereHas('roles', fn ($role) => $role->where('guard_name', 'web')
            ->where('name', '!=', 'unassigned')
            ->whereHas('permissions', fn ($permissions) => $permissions->where('name', $permission))
            ->whereHas('permissions', fn ($permissions) => $permissions->where('name', 'can_view_notifications')))
            ->where(fn ($users) => $users
                ->where(fn ($personal) => $personal->where('access_method', AccessMethod::EmailPassword)->whereNull('production_unit_id'))
                ->when($unitId !== null, fn ($scoped) => $scoped->orWhere(fn ($shared) => $shared
                    ->where('access_method', AccessMethod::DevicePin)->where('production_unit_id', $unitId)->where('pin_enabled', true))))
            ->pluck('id');

        return array_fill_keys($ids->all(), true);
    }
}
