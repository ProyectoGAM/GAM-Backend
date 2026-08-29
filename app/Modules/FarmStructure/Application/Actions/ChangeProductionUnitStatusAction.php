<?php

namespace App\Modules\FarmStructure\Application\Actions;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\FarmStructure\Domain\Exceptions\FarmStructureConflict;
use Illuminate\Support\Facades\DB;

final readonly class ChangeProductionUnitStatusAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(
        ProductionUnit $productionUnit,
        ProductionUnitStatus $status,
        User $actor,
    ): ProductionUnit {
        return DB::transaction(function () use ($productionUnit, $status, $actor): ProductionUnit {
            $query = ProductionUnit::query()->whereKey($productionUnit->getKey());
            $query->getQuery()->lockForUpdate();
            $lockedProductionUnit = $query->firstOrFail();

            if (! $lockedProductionUnit->status->canTransitionTo($status)) {
                throw new FarmStructureConflict('La unidad productiva ya tiene el estado solicitado.');
            }

            if ($status === ProductionUnitStatus::Inactive
                && $lockedProductionUnit->poultryHouses()->where('status', '!=', PoultryHouseStatus::Inactive)->exists()) {
                throw new FarmStructureConflict('Una unidad productiva con galpones activos no puede desactivarse.');
            }

            $previousStatus = $lockedProductionUnit->status;
            $lockedProductionUnit->update(['status' => $status]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $lockedProductionUnit,
                actor: $actor,
                logName: 'farm_structure',
                event: 'production_unit_status_changed',
                description: 'Estado de unidad productiva cambiado',
                properties: [
                    'subject_snapshot' => [
                        'name' => $lockedProductionUnit->name,
                        'status' => $lockedProductionUnit->status->value,
                    ],
                ],
                attributeChanges: [
                    'old' => ['status' => $previousStatus->value],
                    'new' => ['status' => $lockedProductionUnit->status->value],
                ],
                upId: (int) $lockedProductionUnit->getKey(),
                source: 'api',
            ));

            return $lockedProductionUnit->load('locality.department');
        });
    }
}
