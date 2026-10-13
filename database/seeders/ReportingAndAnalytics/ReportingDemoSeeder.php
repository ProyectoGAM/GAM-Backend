<?php

namespace Database\Seeders\ReportingAndAnalytics;

use App\Actions\Inventory\RecordManualEggStockAction;
use App\Actions\ManagementPlans\RecordFeedSupplyAction;
use App\Actions\ReportingAndAnalytics\CreateReportPresetAction;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockCommand;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

final class ReportingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') && ! (app()->environment('production') && config('app.demo_db_reset_enabled'))) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $flock = Flock::query()->where('status', 'active')->orderBy('id')->first();
        if ($flock === null) {
            return;
        }
        $product = Product::query()->where('kind', 'finished_feed')->where('status', 'active')->first();
        if ($product === null) {
            $product = new Product;
            $product->forceFill(['sku' => 'RACION-REPORTES', 'name' => 'Ración de postura demo', 'kind' => 'finished_feed', 'base_unit' => 'kg', 'stock_tracked' => false, 'status' => 'active'])->save();
        }
        $feedKey = '00000000-0000-4000-8210-000000000001';
        if (! FlockOperation::query()->where('created_by', $actor->id)->where('idempotency_key', $feedKey)->exists()) {
            app(RecordFeedSupplyAction::class)->execute($flock, ['idempotency_key' => $feedKey, 'product_id' => $product->id,
                'quantity' => '25.500000', 'unit' => 'kg', 'occurred_at' => now()->toIso8601String(), 'notes' => 'Suministro ficticio para consultar reportes.'], $actor);
        }
        $donationKey = '00000000-0000-4000-8210-000000000002';
        if (! EggStockCommand::query()->where('created_by', $actor->id)->where('idempotency_key', $donationKey)->exists()) {
            app(RecordManualEggStockAction::class)->execute(ProductionUnit::query()->findOrFail($flock->production_unit_id), ['idempotency_key' => $donationKey,
                'quantity' => 12, 'reason' => 'Donación demo a institución local', 'occurred_at' => now()->toIso8601String()], $actor, -1, 'donation', 'seeder');
        }
        $name = 'Postura histórica por galpón';
        if (! ReportPreset::query()->where('user_id', $actor->id)->where('name', $name)->exists()) {
            app(CreateReportPresetAction::class)->execute(['name' => $name, 'source_key' => 'lots.egg-production',
                'configuration' => ['groupings' => ['production_unit', 'poultry_house'], 'metrics' => ['egg_count']]], $actor);
        }
    }
}
