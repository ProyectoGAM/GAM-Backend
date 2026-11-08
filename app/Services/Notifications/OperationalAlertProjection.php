<?php

namespace App\Services\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Models\Notifications\OperationalAlert;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class OperationalAlertProjection
{
    /**
     * Aplica el estado dentro de la transacción y el bloqueo del evaluador de origen.
     *
     * @param  array<string, array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string}>  $pending
     */
    public function synchronize(?int $unitId, OperationalAlertType $type, string $permission, string $entityType, CarbonImmutable $evaluatedAt, array $pending): void
    {
        $this->openAlerts($unitId, $type)->whereNotIn('source_key', array_keys($pending))
            ->update(['resolved_at' => $evaluatedAt->toIso8601String()]);

        foreach ($pending as $key => $alert) {
            $this->open($unitId, $type, $permission, $entityType, (string) $key, $evaluatedAt, $alert);
        }
    }

    /**
     * Reutiliza el episodio vigente. Una reapertura recibe un nuevo identificador.
     *
     * @param  array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string}  $alert
     */
    public function open(?int $unitId, OperationalAlertType $type, string $permission, string $entityType, string $key, CarbonImmutable $occurredAt, array $alert): OperationalAlert
    {
        $record = $this->openAlerts($unitId, $type)->where('source_key', $key)->firstOrNew([], [
            'production_unit_id' => $unitId,
            'type' => $type,
            'source_key' => $key,
            'public_id' => (string) Str::ulid(),
            'occurred_at' => $occurredAt,
        ]);
        $record->fill([
            'entity_type' => $entityType,
            'entity_id' => $alert['entity_id'],
            'target_path' => $alert['target_path'],
            'title' => $alert['title'],
            'urgency' => $alert['urgency'],
            'view_permission' => $permission,
        ])->save();

        return $record;
    }

    public function resolve(?int $unitId, OperationalAlertType $type, string $key, CarbonImmutable $resolvedAt): void
    {
        $this->openAlerts($unitId, $type)->where('source_key', $key)->update(['resolved_at' => $resolvedAt->toIso8601String()]);
    }

    /** @return Builder<OperationalAlert> */
    private function openAlerts(?int $unitId, OperationalAlertType $type): Builder
    {
        return OperationalAlert::query()->where('production_unit_id', $unitId)->where('type', $type)->whereNull('resolved_at');
    }
}
