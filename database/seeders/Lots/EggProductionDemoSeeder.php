<?php

namespace Database\Seeders\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\StockLocation;
use App\Models\Lots\Breed;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\CreateStockLocationAction;
use App\Modules\Lots\Application\Actions\CreateFlockAction;
use App\Modules\Lots\Application\Actions\RecordEggCollectionAction;
use App\Modules\Lots\Application\Actions\RecordEggCollectionLossAction;
use App\Modules\SuppliersAndCatalogs\Application\Actions\CreateProductAction;
use Closure;
use Illuminate\Database\Seeder;

final class EggProductionDemoSeeder extends Seeder
{
    public function run(CreateFlockAction $flocks, RecordEggCollectionAction $collections, RecordEggCollectionLossAction $losses, CreateProductAction $products, CreateStockLocationAction $locations): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $house = PoultryHouse::query()->where('normalized_name', 'galpón ponedoras')->firstOrFail();
        $breed = Breed::query()->where('normalized_name', 'ponedoras demo')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $generic = Product::query()->where('sku', 'HUEVO-001')->firstOrFail();
        $classified = Product::query()->where('sku', 'HUEVO-CLASIFICADO-DEMO')->first();
        if ($classified === null) {
            $classified = $products->execute(['sku' => 'HUEVO-CLASIFICADO-DEMO', 'name' => 'Huevos clasificados demo', 'kind' => 'egg', 'base_unit' => 'unit', 'stock_tracked' => true], $actor);
        }
        $location = StockLocation::query()->where('normalized_name', 'cámara de huevos - santa clara - producción demo')->first();
        if ($location === null) {
            $location = $locations->execute(['name' => 'Cámara de huevos - Santa Clara - Producción Demo', 'production_unit_id' => $house->production_unit_id], $actor);
        }
        $created = $this->once($actor, 901, fn (string $key): FlockOperation => $flocks->execute([
            'code' => 'DEMO-EGG-PROD', 'breed_id' => $breed->id, 'supplier_id' => $supplier->id,
            'poultry_house_id' => $house->id, 'initial_quantity' => 120,
            'entry_date' => now(config('lots.timezone'))->subDays(60)->toDateString(), 'idempotency_key' => $key,
        ], $actor, 'seeder'));
        $flockId = $created->result['flock']['public_id'];

        $first = $this->once($actor, 902, function (string $key) use ($flockId, $collections, $actor, $generic, $classified, $location): FlockOperation {
            $flock = Flock::query()->where('public_id', $flockId)->firstOrFail();

            return $collections->execute($flock, [
                'version' => $flock->version, 'collected_quantity' => 30, 'discarded_quantity' => 0,
                'lines' => [
                    ['product_id' => $generic->id, 'stock_location_id' => $location->id, 'quantity' => 20],
                    ['product_id' => $classified->id, 'stock_location_id' => $location->id, 'quantity' => 10],
                ], 'occurred_at' => now(config('lots.timezone'))->subDays(20)->toIso8601String(), 'idempotency_key' => $key,
            ], $actor, 'seeder');
        });
        $second = $this->once($actor, 903, function (string $key) use ($flockId, $collections, $actor, $generic, $classified, $location): FlockOperation {
            $flock = Flock::query()->where('public_id', $flockId)->firstOrFail();

            return $collections->execute($flock, [
                'version' => $flock->version, 'collected_quantity' => 24, 'discarded_quantity' => 2,
                'discard_reason' => 'Cáscara dañada durante la clasificación.',
                'lines' => [
                    ['product_id' => $generic->id, 'stock_location_id' => $location->id, 'quantity' => 12],
                    ['product_id' => $classified->id, 'stock_location_id' => $location->id, 'quantity' => 10],
                ], 'occurred_at' => now(config('lots.timezone'))->subDays(7)->toIso8601String(), 'idempotency_key' => $key,
            ], $actor, 'seeder');
        });
        $collectionId = $second->result['collection']['public_id'];
        $this->once($actor, 904, function (string $key) use ($collectionId, $losses, $actor, $generic, $location): FlockOperation {
            $collection = EggCollection::query()->where('public_id', $collectionId)->firstOrFail();

            return $losses->execute($collection, [
                'idempotency_key' => $key, 'lines' => [['product_id' => $generic->id, 'stock_location_id' => $location->id, 'quantity' => 3]],
                'reason' => 'Rotura posterior en el depósito.', 'occurred_at' => now(config('lots.timezone'))->subDays(5)->toIso8601String(),
            ], $actor, 'seeder');
        });
        $this->once($actor, 905, function (string $key) use ($flockId, $collections, $actor): FlockOperation {
            $flock = Flock::query()->where('public_id', $flockId)->firstOrFail();

            return $collections->execute($flock, [
                'version' => $flock->version, 'collected_quantity' => 5, 'discarded_quantity' => 5,
                'discard_reason' => 'Lote completo descartado por contaminación.', 'lines' => [],
                'occurred_at' => now(config('lots.timezone'))->subDays(2)->toIso8601String(), 'idempotency_key' => $key,
            ], $actor, 'seeder');
        });
    }

    /** @param Closure(string): FlockOperation $create */
    private function once(User $actor, int $number, Closure $create): FlockOperation
    {
        $key = '00000000-0000-4000-8600-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);

        return FlockOperation::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->first() ?? $create($key);
    }
}
