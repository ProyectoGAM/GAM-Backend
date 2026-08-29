<?php

namespace App\Modules\FarmStructure\Application\Actions;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final readonly class UpdateProductionUnitAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array{locality_id?: int, name?: string, latitude?: numeric-string, longitude?: numeric-string}  $attributes
     */
    public function execute(ProductionUnit $productionUnit, array $attributes, User $actor): ProductionUnit
    {
        return DB::transaction(function () use ($productionUnit, $attributes, $actor): ProductionUnit {
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

            return $lockedProductionUnit->load('locality.department');
        });
    }

    /** @return array{locality_id: int, name: string, latitude: string, longitude: string, status: string} */
    private function snapshot(ProductionUnit $productionUnit): array
    {
        return [
            'locality_id' => $productionUnit->locality_id,
            'name' => $productionUnit->name,
            'latitude' => $productionUnit->latitude,
            'longitude' => $productionUnit->longitude,
            'status' => $productionUnit->status->value,
        ];
    }
}
