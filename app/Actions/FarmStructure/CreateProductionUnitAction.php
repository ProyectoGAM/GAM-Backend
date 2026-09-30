<?php

namespace App\Actions\FarmStructure;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateProductionUnitAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array{locality_id: int, name: string, latitude: numeric-string, longitude: numeric-string, status?: string}  $attributes
     */
    public function execute(array $attributes, User $actor): ProductionUnit
    {
        return DB::transaction(function () use ($attributes, $actor): ProductionUnit {
            $productionUnit = ProductionUnit::query()->create([
                ...$attributes,
                'status' => ProductionUnitStatus::from($attributes['status'] ?? ProductionUnitStatus::Active->value),
            ]);

            $snapshot = $this->snapshot($productionUnit);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $productionUnit,
                actor: $actor,
                logName: 'farm_structure',
                event: 'production_unit_created',
                description: 'Unidad productiva creada',
                properties: ['subject_snapshot' => $snapshot],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                upId: (int) $productionUnit->getKey(),
                source: 'api',
            ));

            return $productionUnit->load('locality.department');
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
