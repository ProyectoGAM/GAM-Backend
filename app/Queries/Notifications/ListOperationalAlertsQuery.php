<?php

namespace App\Queries\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Models\Notifications\OperationalAlert;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListOperationalAlertsQuery
{
    /** @return LengthAwarePaginator<int, OperationalAlert> */
    public function execute(User $actor, ?int $unitId, ?string $urgency, int $perPage): LengthAwarePaginator
    {
        return $this->visible($actor, $unitId, $urgency)->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    /** @return array<string, int> */
    public function counts(User $actor, ?int $unitId, ?string $urgency): array
    {
        $counts = array_fill_keys(array_column(NotificationUrgency::cases(), 'value'), 0);
        $groups = $this->visible($actor, $unitId, $urgency)->toBase()
            ->selectRaw('urgency, COUNT(*) AS total')->groupBy('urgency')->pluck('total', 'urgency');
        foreach ($groups as $value => $count) {
            $counts[$value] = (int) $count;
        }

        return $counts;
    }

    /** @return Builder<OperationalAlert> */
    private function visible(User $actor, ?int $unitId, ?string $urgency): Builder
    {
        $permissions = $actor->getAllPermissions()->where('guard_name', 'web')->pluck('name');

        return OperationalAlert::query()->whereNull('resolved_at')->where(function (Builder $query) use ($actor, $permissions): void {
            $query->whereIn('view_permission', $permissions);
            if ($actor->can('can_monitor_deliveries')) {
                $query->orWhere('type', OperationalAlertType::DeliveryEggBalanceNegative);
            }
        })
            ->when($unitId !== null, fn (Builder $query): Builder => $query->where('production_unit_id', $unitId))
            ->when($urgency !== null, fn (Builder $query): Builder => $query->where('urgency', $urgency));
    }
}
