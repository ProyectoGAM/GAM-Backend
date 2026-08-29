<?php

namespace App\Modules\FarmStructure\Application\Actions;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\FarmStructure\Domain\Exceptions\FarmStructureConflict;
use App\Modules\FarmStructure\Domain\ValueObjects\BirdCapacity;
use Illuminate\Support\Facades\DB;

final readonly class CreatePoultryHouseAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{name: string, bird_capacity: int} $attributes */
    public function execute(ProductionUnit $productionUnit, array $attributes, User $actor): PoultryHouse
    {
        return DB::transaction(function () use ($productionUnit, $attributes, $actor): PoultryHouse {
            $query = ProductionUnit::query()->whereKey($productionUnit->getKey());
            $query->getQuery()->lockForUpdate();
            $lockedProductionUnit = $query->firstOrFail();

            if ($lockedProductionUnit->status !== ProductionUnitStatus::Active) {
                throw new FarmStructureConflict('Los galpones sólo pueden crearse en una unidad productiva activa.');
            }

            $capacity = BirdCapacity::fromInt($attributes['bird_capacity']);
            $poultryHouse = $lockedProductionUnit->poultryHouses()->create([
                'name' => $attributes['name'],
                'bird_capacity' => $capacity->value(),
                'status' => PoultryHouseStatus::Operational,
            ]);
            $snapshot = $this->snapshot($poultryHouse);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $poultryHouse,
                actor: $actor,
                logName: 'farm_structure',
                event: 'poultry_house_created',
                description: 'Galpón creado',
                properties: ['subject_snapshot' => $snapshot],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                upId: $poultryHouse->production_unit_id,
                source: 'api',
            ));

            return $poultryHouse->load('productionUnit.locality.department');
        });
    }

    /** @return array{production_unit_id: int, name: string, bird_capacity: int, status: string} */
    private function snapshot(PoultryHouse $poultryHouse): array
    {
        return [
            'production_unit_id' => $poultryHouse->production_unit_id,
            'name' => $poultryHouse->name,
            'bird_capacity' => $poultryHouse->bird_capacity,
            'status' => $poultryHouse->status->value,
        ];
    }
}
