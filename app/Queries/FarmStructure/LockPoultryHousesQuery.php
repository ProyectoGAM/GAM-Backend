<?php

namespace App\Queries\FarmStructure;

use App\DTO\FarmStructure\LockedPoultryHouseData;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class LockPoultryHousesQuery
{
    public function __construct(private PoultryHouseOccupancyProvider $occupancy) {}

    /**
     * @param  list<int>  $ids
     * @return array<int, LockedPoultryHouseData>
     */
    public function execute(array $ids): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La reserva de capacidad requiere una transacción activa.');
        }
        $ids = array_values(array_unique($ids));
        $houses = PoultryHouse::query()
            ->whereIn('id', $ids)
            ->where('type', PoultryHouseType::Poultry)
            ->orderBy('id')
            ->lockForUpdate()->with('productionUnit')->get();
        if ($houses->count() !== count($ids)) {
            if (PoultryHouse::query()->whereIn('id', $ids)->where('type', PoultryHouseType::Feed)->exists()) {
                throw new LotsConflict('Las plantas de ración no pueden recibir aves ni lotes.');
            }

            throw (new ModelNotFoundException)->setModel(PoultryHouse::class, $ids);
        }
        $result = [];
        foreach ($houses as $house) {
            $result[$house->id] = new LockedPoultryHouseData(
                $house->id, $house->production_unit_id, $house->bird_capacity,
                $this->occupancy->occupancyFor($house->id),
                $this->occupancy->openFlocksCountFor($house->id),
                $house->status === PoultryHouseStatus::Operational
                    && $house->productionUnit->status === ProductionUnitStatus::Active,
            );
        }

        return $result;
    }
}
