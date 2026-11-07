<?php

namespace App\Actions\Notifications;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Notifications\OperationalAlertType;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Lots\LotsAuthorization;
use App\Services\Notifications\AlertConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class UpdateAlertConfigurationAction
{
    public function __construct(
        private AlertConfiguration $configuration,
        private AuditRecorder $audit,
        private ProductionUnitAccess $access,
    ) {}

    /**
     * @param  array<string, bool|int|string|null>  $changes
     * @return array<string, bool|int|string|null>
     */
    public function execute(OperationalAlertType $type, ?ProductionUnit $unit, array $changes, User $actor): array
    {
        return DB::transaction(function () use ($type, $unit, $changes, $actor): array {
            $actor = User::query()->findOrFail($actor->id);
            LotsAuthorization::ensure($actor, 'can_manage_alert_thresholds');
            $unitId = $unit?->id;
            abort_unless($type->appliesTo($unitId), 404);
            if ($unitId === null) {
                abort_if($actor->usesDevicePin(), 403);
            } else {
                $this->access->assertUnit($unitId, $actor);
            }

            // Serializa también el primer cambio, cuando aún no hay una fila que bloquear.
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['alert-configuration:'.($unitId ?? 'general').':'.$type->value]);
            $old = $this->configuration->get($type, $unitId);
            $new = array_replace($old, $changes);
            if ($old === $new) {
                return $old;
            }

            $defaults = $this->configuration->defaults($type);
            $overrides = array_filter($new, static fn (mixed $value, string $key): bool => $value !== $defaults[$key], ARRAY_FILTER_USE_BOTH);
            $this->configuration->query($unitId)->updateOrInsert(['type' => $type->value], [
                'production_unit_id' => $unitId,
                'settings' => json_encode((object) $overrides, JSON_THROW_ON_ERROR),
            ]);

            $this->audit->record(new AuditEntryData(
                logName: 'notifications',
                event: 'alert_configuration_changed',
                description: 'Configuración de alerta actualizada',
                operationId: (string) Str::uuid(),
                traceId: null,
                source: 'api',
                upId: $unitId,
                actorType: $actor->getMorphClass(),
                actorId: $actor->id,
                subjectType: $unit?->getMorphClass(),
                subjectId: $unitId,
                properties: [
                    'result' => 'success',
                    'alert_type' => $type->value,
                    'actor_snapshot' => ['kind' => 'person', 'name' => $actor->name],
                    'subject_snapshot' => ['name' => $type->label(), 'production_unit_id' => $unitId, 'production_unit_name' => $unit?->name],
                ],
                attributeChanges: ['old' => $old, 'new' => $new],
            ));

            return $new;
        }, 3);
    }
}
