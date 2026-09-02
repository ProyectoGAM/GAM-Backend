<?php

namespace Database\Seeders\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockCommand;
use App\Models\Lots\Breed;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\Inventory\Application\PublicApi\Actions\EnsureEggStockAccountAction;
use App\Modules\Inventory\Application\PublicApi\Actions\RecordManualEggStockAction;
use App\Modules\Lots\Application\Actions\CorrectEggCollectionAction;
use App\Modules\Lots\Application\Actions\CreateFlockAction;
use App\Modules\Lots\Application\Actions\RecordEggCollectionAction;
use Closure;
use Illuminate\Database\Seeder;

final class EggProductionDemoSeeder extends Seeder
{
    public function run(CreateFlockAction $flocks, RecordEggCollectionAction $collections, CorrectEggCollectionAction $corrections, RecordManualEggStockAction $manual, EnsureEggStockAccountAction $accounts): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        ProductionUnit::query()->each(function (ProductionUnit $unit) use ($accounts): void {
            $accounts->execute($unit);
        });
        $house = PoultryHouse::query()->where('normalized_name', 'galpón ponedoras')->firstOrFail();
        $unit = ProductionUnit::query()->findOrFail($house->production_unit_id);
        $accounts->execute($unit);
        $breed = Breed::query()->where('normalized_name', 'ponedoras demo')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $created = $this->onceFlock($actor, 901, fn (string $key): FlockOperation => $flocks->execute([
            'code' => 'DEMO-EGG-PROD', 'breed_id' => $breed->id, 'supplier_id' => $supplier->id,
            'poultry_house_id' => $house->id, 'initial_quantity' => 120,
            'entry_date' => now(config('lots.timezone'))->subDays(60)->toDateString(), 'idempotency_key' => $key,
        ], $actor, 'seeder'));
        $flock = Flock::query()->where('public_id', $created->result['flock']['public_id'])->firstOrFail();

        $first = $this->onceFlock($actor, 902, fn (string $key): FlockOperation => $collections->execute($flock, ['quantity' => 4000, 'occurred_at' => now(config('lots.timezone'))->subDays(20)->toIso8601String(), 'idempotency_key' => $key], $actor, 'seeder'));
        $collection = EggCollection::query()->where('public_id', $first->result['collection']['public_id'])->firstOrFail();
        $this->onceFlock($actor, 903, fn (string $key): FlockOperation => $corrections->execute($collection, ['version' => $collection->version, 'quantity' => 400, 'correction_reason' => 'Corrección de digitación demo.', 'idempotency_key' => $key], $actor, source: 'seeder'));
        $this->onceEggStock($actor, 904, fn (string $key): array => $manual->execute($unit, ['quantity' => 100, 'reason' => 'Ingreso manual demo.', 'idempotency_key' => $key], $actor, source: 'seeder'));
        $this->onceEggStock($actor, 905, fn (string $key): array => $manual->execute($unit, ['quantity' => 20, 'type' => 'distribution_preparation', 'reason' => 'Preparación de reparto demo.', 'idempotency_key' => $key], $actor, -1, 'distribution_preparation', 'seeder'));
        $this->onceEggStock($actor, 906, fn (string $key): array => $manual->execute($unit, ['quantity' => 3, 'type' => 'loss', 'reason' => 'Rotura demo.', 'idempotency_key' => $key], $actor, -1, 'loss', 'seeder'));
    }

    /** @param Closure(string): FlockOperation $create */
    private function onceFlock(User $actor, int $number, Closure $create): FlockOperation
    {
        $key = '00000000-0000-4000-8600-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);

        return FlockOperation::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->first() ?? $create($key);
    }

    /** @param Closure(string): array<string, mixed> $create */
    private function onceEggStock(User $actor, int $number, Closure $create): array
    {
        $key = '00000000-0000-4000-8700-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
        $existing = EggStockCommand::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->first();

        return $existing === null ? $create($key) : $existing->result;
    }
}
