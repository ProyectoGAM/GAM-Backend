<?php

namespace App\Modules\FarmStructure\Application\Actions;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Application\PublicApi\Contracts\PoultryHouseOccupancyProvider;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\FarmStructure\Domain\Exceptions\FarmStructureConflict;
use Illuminate\Support\Facades\DB;

final readonly class ChangePoultryHouseStatusAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private PoultryHouseOccupancyProvider $occupancyProvider,
    ) {}

    public function execute(
        PoultryHouse $poultryHouse,
        PoultryHouseStatus $status,
        User $actor,
    ): PoultryHouse {
        return DB::transaction(function () use ($poultryHouse, $status, $actor): PoultryHouse {
            $query = PoultryHouse::query()
                ->with('productionUnit')
                ->whereKey($poultryHouse->getKey());
            $query->getQuery()->lockForUpdate();
            $lockedPoultryHouse = $query->firstOrFail();

            if (! $lockedPoultryHouse->status->canTransitionTo($status)) {
                throw new FarmStructureConflict('La transición de estado del galpón no está permitida.');
            }

            if ($status === PoultryHouseStatus::Operational
                && $lockedPoultryHouse->productionUnit->status !== ProductionUnitStatus::Active) {
                throw new FarmStructureConflict('Un galpón no puede operar en una unidad productiva inactiva.');
            }

            if ($status === PoultryHouseStatus::Inactive
                && $this->occupancyProvider->occupancyFor((int) $lockedPoultryHouse->getKey()) > 0) {
                throw new FarmStructureConflict('Un galpón ocupado no puede desactivarse.');
            }

            $previousStatus = $lockedPoultryHouse->status;
            $lockedPoultryHouse->update(['status' => $status]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $lockedPoultryHouse,
                actor: $actor,
                logName: 'farm_structure',
                event: 'poultry_house_status_changed',
                description: 'Estado de galpón cambiado',
                properties: [
                    'subject_snapshot' => [
                        'production_unit_id' => $lockedPoultryHouse->production_unit_id,
                        'name' => $lockedPoultryHouse->name,
                        'status' => $lockedPoultryHouse->status->value,
                    ],
                ],
                attributeChanges: [
                    'old' => ['status' => $previousStatus->value],
                    'new' => ['status' => $lockedPoultryHouse->status->value],
                ],
                upId: $lockedPoultryHouse->production_unit_id,
                source: 'api',
            ));

            return $lockedPoultryHouse->load('productionUnit.locality.department');
        });
    }
}
