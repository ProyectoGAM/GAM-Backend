<?php

namespace Database\Seeders\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockLocation;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\FarmStructure\Application\Actions\CreatePoultryHouseAction;
use App\Modules\Inventory\Application\Actions\CreateStockLocationAction;
use App\Modules\Lots\Application\Actions\ChangeFlockStatusAction;
use App\Modules\Lots\Application\Actions\CreateFlockAction;
use App\Modules\Lots\Application\Actions\FinalizeFlockAction;
use App\Modules\Lots\Application\Actions\RecordEggCollectionAction;
use App\Modules\Lots\Application\Actions\RecordMortalityAction;
use App\Modules\Lots\Application\Actions\RedistributeFlockAction;
use App\Modules\Lots\Application\Actions\SaveBreedAction;
use App\Modules\Lots\Application\Actions\SaveMortalityCategoryAction;
use App\Modules\SuppliersAndCatalogs\Application\Actions\CreateProductAction;
use Closure;
use Illuminate\Database\Seeder;

final class LotsDemoSeeder extends Seeder
{
    public function run(CreateFlockAction $create, RedistributeFlockAction $redistribute, RecordMortalityAction $mortality, RecordEggCollectionAction $eggs, FinalizeFlockAction $finalize, ChangeFlockStatusAction $status, SaveBreedAction $breeds, SaveMortalityCategoryAction $categories, CreatePoultryHouseAction $createHouse, CreateProductAction $createProduct, CreateStockLocationAction $createLocation): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $unit = ProductionUnit::query()->where('normalized_name', 'granja el ombú')->firstOrFail();
        $north = PoultryHouse::query()->where('production_unit_id', $unit->id)->where('normalized_name', 'galpón norte')->firstOrFail();
        $layers = PoultryHouse::query()->where('normalized_name', 'galpón ponedoras')->firstOrFail();
        $destination = PoultryHouse::query()->where('production_unit_id', $unit->id)->where('normalized_name', 'galpón lotes demo')->first();
        if ($destination === null) {
            $destination = $createHouse->execute($unit, ['name' => 'Galpón Lotes Demo', 'bird_capacity' => 300], $actor);
        }
        /** El inventario demo de Lotes queda separado de los saldos base que recarga el seeder general. */
        $product = Product::query()->where('sku', 'HUEVO-LOTES-DEMO')->first();
        if ($product === null) {
            $product = $createProduct->execute(['sku' => 'HUEVO-LOTES-DEMO', 'name' => 'Huevos demo de Lotes', 'kind' => 'egg', 'base_unit' => 'unit', 'stock_tracked' => true], $actor);
        }
        $location = StockLocation::query()->where('normalized_name', 'cámara de huevos - lotes demo')->first();
        if ($location === null) {
            $location = $createLocation->execute(['name' => 'Cámara de huevos - Lotes Demo', 'production_unit_id' => $unit->id], $actor);
        }
        $breedResult = $this->once($actor, 501, fn (string $key): FlockOperation => $breeds->execute(null, ['name' => 'Ponedoras demo', 'idempotency_key' => $key], $actor, 'seeder'));
        $otherBreed = $this->once($actor, 502, fn (string $key): FlockOperation => $breeds->execute(null, ['name' => 'Camperas demo', 'idempotency_key' => $key], $actor, 'seeder'));
        $category = $this->once($actor, 503, fn (string $key): FlockOperation => $categories->execute(null, ['name' => 'Causa en observación', 'idempotency_key' => $key], $actor, 'seeder'));
        $suppliers = Supplier::query()->orderBy('id')->limit(2)->get();
        $entryDate = now(config('lots.timezone'))->subDays(40)->toDateString();
        $a = $this->once($actor, 504, fn (string $key): FlockOperation => $create->execute([
            'code' => 'DEMO-LOT-A', 'breed_id' => $breedResult->result['catalog']['id'],
            'supplier_id' => $suppliers[0]->id, 'poultry_house_id' => $north->id, 'initial_quantity' => 100,
            'entry_date' => $entryDate, 'idempotency_key' => $key,
        ], $actor, 'seeder'));
        $b = $this->once($actor, 505, fn (string $key): FlockOperation => $create->execute([
            'code' => 'DEMO-LOT-B', 'breed_id' => $breedResult->result['catalog']['id'],
            'supplier_id' => $suppliers[1]->id, 'poultry_house_id' => $layers->id, 'initial_quantity' => 40,
            'entry_date' => $entryDate, 'idempotency_key' => $key,
        ], $actor, 'seeder'));
        $aId = $a->result['flock']['public_id'];
        $bId = $b->result['flock']['public_id'];
        $split = $this->once($actor, 506, function (string $key) use ($aId, $layers, $redistribute, $actor): FlockOperation {
            $flock = Flock::query()->where('public_id', $aId)->firstOrFail();

            return $redistribute->execute($flock, ['quantity' => 20, 'destination_poultry_house_id' => $layers->id, 'destination_code' => 'DEMO-LOT-C', 'version' => $flock->version, 'idempotency_key' => $key], $actor, 'seeder');
        });
        $this->once($actor, 507, function (string $key) use ($aId, $bId, $redistribute, $actor): FlockOperation {
            $from = Flock::query()->where('public_id', $aId)->firstOrFail();
            $to = Flock::query()->where('public_id', $bId)->firstOrFail();

            return $redistribute->execute($from, ['quantity' => 10, 'destination_flock_id' => $to->public_id, 'version' => $from->version, 'destination_version' => $to->version, 'idempotency_key' => $key], $actor, 'seeder');
        });
        $this->once($actor, 508, function (string $key) use ($aId, $destination, $redistribute, $actor): FlockOperation {
            $flock = Flock::query()->where('public_id', $aId)->firstOrFail();

            return $redistribute->execute($flock, ['quantity' => $flock->current_quantity, 'destination_poultry_house_id' => $destination->id, 'version' => $flock->version, 'idempotency_key' => $key], $actor, 'seeder');
        });
        $this->once($actor, 509, function (string $key) use ($bId, $category, $mortality, $actor): FlockOperation {
            $flock = Flock::query()->where('public_id', $bId)->firstOrFail();

            return $mortality->execute($flock, ['quantity' => 2, 'mortality_category_id' => $category->result['catalog']['id'], 'version' => $flock->version, 'idempotency_key' => $key], $actor, 'seeder');
        });
        $this->once($actor, 510, function (string $key) use ($bId, $product, $location, $eggs, $actor): FlockOperation {
            $flock = Flock::query()->where('public_id', $bId)->firstOrFail();

            return $eggs->execute($flock, ['quantity' => 12, 'product_id' => $product->id, 'stock_location_id' => $location->id, 'version' => $flock->version, 'idempotency_key' => $key], $actor, 'seeder');
        });
        $this->once($actor, 511, function (string $key) use ($split, $finalize, $actor): FlockOperation {
            $flock = Flock::query()->where('public_id', $split->result['destination']['public_id'])->firstOrFail();

            return $finalize->execute($flock, ['version' => $flock->version, 'reason' => 'Fin del ciclo demo: egreso de 20 aves.', 'idempotency_key' => $key], $actor, 'seeder');
        });
        $d = $this->once($actor, 512, fn (string $key): FlockOperation => $create->execute([
            'code' => 'DEMO-LOT-D', 'breed_id' => $otherBreed->result['catalog']['id'], 'origin' => 'Cría propia demo',
            'poultry_house_id' => $destination->id, 'initial_quantity' => 25, 'entry_date' => $entryDate, 'idempotency_key' => $key,
        ], $actor, 'seeder'));
        $this->once($actor, 513, function (string $key) use ($d, $status, $actor): FlockOperation {
            $flock = Flock::query()->where('public_id', $d->result['flock']['public_id'])->firstOrFail();

            return $status->execute($flock, ['status' => 'quarantined', 'version' => $flock->version, 'reason' => 'Observación demo.', 'idempotency_key' => $key], $actor, 'seeder');
        });
    }

    /** @param Closure(string): FlockOperation $create */
    private function once(User $actor, int $number, Closure $create): FlockOperation
    {
        $key = '00000000-0000-4000-8500-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);

        return FlockOperation::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->first() ?? $create($key);
    }
}
