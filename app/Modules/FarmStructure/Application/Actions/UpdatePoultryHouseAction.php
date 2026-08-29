<?php

namespace App\Modules\FarmStructure\Application\Actions;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Application\PublicApi\Contracts\PoultryHouseOccupancyProvider;
use App\Modules\FarmStructure\Domain\Exceptions\FarmStructureConflict;
use App\Modules\FarmStructure\Domain\ValueObjects\BirdCapacity;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePoultryHouseAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private PoultryHouseOccupancyProvider $occupancyProvider,
    ) {}

    /** @param array{name?: string, bird_capacity?: int} $attributes */
    public function execute(PoultryHouse $poultryHouse, array $attributes, User $actor): PoultryHouse
    {
        return DB::transaction(function () use ($poultryHouse, $attributes, $actor): PoultryHouse {
            $query = PoultryHouse::query()->whereKey($poultryHouse->getKey());
            $query->getQuery()->lockForUpdate();
            $lockedPoultryHouse = $query->firstOrFail();
            $before = $this->snapshot($lockedPoultryHouse);

            if (array_key_exists('bird_capacity', $attributes)) {
                $capacity = BirdCapacity::fromInt($attributes['bird_capacity']);
                $occupancy = $this->occupancyProvider->occupancyFor((int) $lockedPoultryHouse->getKey());

                if (! $capacity->supportsOccupancy($occupancy)) {
                    throw new FarmStructureConflict('La capacidad de aves no puede ser menor que la ocupación actual.');
                }

                $attributes['bird_capacity'] = $capacity->value();
            }

            $lockedPoultryHouse->fill($attributes)->save();
            $after = $this->snapshot($lockedPoultryHouse);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $lockedPoultryHouse,
                actor: $actor,
                logName: 'farm_structure',
                event: 'poultry_house_updated',
                description: 'Galpón actualizado',
                properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
                upId: $lockedPoultryHouse->production_unit_id,
                source: 'api',
            ));

            return $lockedPoultryHouse->load('productionUnit.locality.department');
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
