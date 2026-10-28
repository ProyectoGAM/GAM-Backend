<?php

namespace Database\Seeders\Inventory;

use App\Actions\Inventory\PrepareStockStorageAction;
use App\Actions\Inventory\ReceiveStockAction;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Database\Seeder;

final class HealthStockDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') && ! (app()->environment('production') && config('app.demo_db_reset_enabled'))) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        app(PrepareStockStorageAction::class)->execute($actor);
        $location = StockLocation::query()->where('type', 'health')->orderBy('production_unit_id')->firstOrFail();
        $products = [Medicine::query()->whereNotNull('product_id')->orderBy('id')->firstOrFail()->product_id, Vaccine::query()->orderBy('id')->firstOrFail()->product_id];
        foreach ($products as $index => $productId) {
            app(ReceiveStockAction::class)->execute([
                'idempotency_key' => '00000000-0000-4000-8000-00000000083'.($index + 1),
                'reason' => 'Existencia ficticia inicial para sanidad',
                'lines' => [['product_id' => (int) $productId, 'stock_location_id' => (int) $location->getKey(), 'quantity' => '100']],
            ], $actor);
        }
    }
}
