<?php

namespace App\Actions\FarmStructure;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateProductionUnitAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private ChangeProductionUnitStatusAction $changeStatus,
    ) {}

    /**
     * @param  array{locality_id?: int|null, name?: string, address?: string, latitude?: numeric-string, longitude?: numeric-string}  $attributes
     */
    public function execute(
        ProductionUnit $productionUnit,
        array $attributes,
        User $actor,
        ?ProductionUnitStatus $status = null,
    ): ProductionUnit {
        return DB::transaction(function () use ($productionUnit, $attributes, $actor, $status): ProductionUnit {
            $query = ProductionUnit::query()->whereKey($productionUnit->getKey());
            $query->getQuery()->lockForUpdate();
            $lockedProductionUnit = $query->firstOrFail();
            $before = $this->snapshot($lockedProductionUnit);

            $lockedProductionUnit->fill($attributes)->save();
            $after = $this->snapshot($lockedProductionUnit);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $lockedProductionUnit,
                actor: $actor,
                logName: 'farm_structure',
                event: 'production_unit_updated',
                description: 'Unidad productiva actualizada',
                properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
                upId: (int) $lockedProductionUnit->getKey(),
                source: 'api',
            ));

            if ($status !== null && $status !== $lockedProductionUnit->status) {
                return $this->changeStatus->execute($lockedProductionUnit, $status, $actor);
            }

            return $lockedProductionUnit->load('locality.department');
        });
    }

    /** @return array{locality_id: int|null, name: string, address: string|null, latitude: string, longitude: string, status: string} */
    private function snapshot(ProductionUnit $productionUnit): array
    {
        return [
            'locality_id' => $productionUnit->locality_id,
            'name' => $productionUnit->name,
            'address' => $productionUnit->address,
            'latitude' => $productionUnit->latitude,
            'longitude' => $productionUnit->longitude,
            'status' => $productionUnit->status->value,
        ];
    }
}
